<?php

namespace App\Cdn;

use App\Models\Cdn\Variants;
use zFramework\Core\Helpers\Assets;

/**
 * Minified css and js, as a derivative.
 *
 * The same shape as Transform, and deliberately: a minified stylesheet is a
 * variant of a file the way a 400px thumbnail is. It goes in the same table,
 * under the same signature scheme, in the same storage - so purging a bucket
 * clears it, `cdn gc` evicts it, and the hit counters count it, with nothing
 * written twice.
 *
 * What it does not do is touch the stored object. The original is what was
 * uploaded and stays byte-for-byte what was uploaded: it is the thing the hash
 * addresses, the etag is derived from, and a deduplicated second upload matched
 * against. Minifying on the way in would break all three and leave nothing to
 * fall back to when a minifier mangles a file - which is a thing minifiers do.
 *
 * Failure is not an error here either. A stylesheet the parser cannot follow is
 * served as it is: slightly larger, and working.
 */
class Minifier
{
    /**
     * What can be minified, and with what.
     *
     * The framework already ships both - JShrink for js, a regex pass for css -
     * so this is a router rather than an implementation.
     */
    private const HANDLERS = [
        'js'  => 'js',
        'mjs' => 'js',
        'css' => 'css',
    ];

    /**
     * @return bool
     */
    public static function enabled(): bool
    {
        return (bool) Support::config('minify.enabled', true);
    }

    /**
     * Which handler a file wants, or null if none.
     *
     * The extension decides rather than the mime type: an uploaded .js served
     * as text/plain by a strict sniffer is still javascript, and a .css that
     * arrived as application/octet-stream is still a stylesheet.
     *
     * @param array $file
     * @return string|null
     */
    public static function kind(array $file): ?string
    {
        $extension = strtolower((string) pathinfo((string) ($file['path'] ?? $file['name'] ?? ''), PATHINFO_EXTENSION));

        $kind = self::HANDLERS[$extension] ?? null;
        if (!$kind) return null;

        # Already minified. Running JShrink over a file that has been through a
        # real bundler is work for nothing, and occasionally work for less than
        # nothing.
        if (preg_match('/\.min\.[a-z]+$/i', (string) ($file['path'] ?? ''))) return null;

        $allowed = (array) Support::config('minify.types', array_keys(self::HANDLERS));

        return in_array($extension, $allowed, true) ? $kind : null;
    }

    /**
     * Should this request be answered with the minified copy?
     *
     * `?min=1` asks for it; `?min=0` refuses it even where a bucket says
     * otherwise, which is what somebody debugging a mangled file needs.
     *
     * @param array $file
     * @param array $bucket
     * @return bool
     */
    public static function wanted(array $file, array $bucket): bool
    {
        if (!self::enabled() || self::kind($file) === null) return false;

        $asked = request('min');

        if ($asked !== false && $asked !== null) return (string) $asked !== '0';

        # The bucket's own default, then the installation's. A bucket that holds
        # build output wants this on; one that holds source somebody links to on
        # purpose does not.
        return (bool) ($bucket['minify'] ?? Support::config('minify.auto', false));
    }

    /**
     * The cache key.
     *
     * Same ingredients as a transform's, so bumping a bucket's cache_version
     * invalidates minified copies along with everything else.
     *
     * @param array $file
     * @param array $bucket
     * @return string
     */
    public static function signature(array $file, array $bucket): string
    {
        return sha1(implode('|', [
            $file['id'] ?? 0,
            $file['hash'] ?? '',
            (int) ($bucket['cache_version'] ?? 1),
            'minify',
        ]));
    }

    /**
     * Find or build it.
     *
     * @param array $file
     * @param array $bucket
     * @return array|null ['row' => variant row, 'built' => bool]
     */
    public static function resolve(array $file, array $bucket): ?array
    {
        $signature = self::signature($file, $bucket);
        $model     = new Variants;

        $row = $model->where('signature', $signature)->where('file_id', $file['id'])->closureMode(false)->first();

        if ($row && is_file(Storage::variantAbsolute($row['storage_path']))) return ['row' => $row, 'built' => false];

        $built = self::build($file, $bucket, $signature);
        if ($built === null) return null;

        if ($row) {
            $model->where('id', $row['id'])->update($built);
            $row = $built + $row;
        } else {
            $row = $model->insert([
                'file_id'   => $file['id'],
                'bucket_id' => $file['bucket_id'],
                'signature' => $signature,
            ] + $built);
        }

        return ['row' => $row, 'built' => true];
    }

    /**
     * Produce the bytes and store them.
     *
     * @param array  $file
     * @param array  $bucket
     * @param string $signature
     * @return array|null
     */
    private static function build(array $file, array $bucket, string $signature): ?array
    {
        $kind = self::kind($file);
        if (!$kind) return null;

        $source = Storage::absolute($file['storage_path'], $file['disk'] ?? null);
        if (!is_file($source)) return null;

        # A source this big is a bundle somebody already built, and holding it
        # plus its output in memory is the part that hurts.
        $limit = (int) Support::config('minify.max-size', 4 * 1024 * 1024);
        if ($limit > 0 && filesize($source) > $limit) return null;

        $contents = @file_get_contents($source);
        if ($contents === false || $contents === '') return null;

        $started = hrtime(true);

        try {
            $result = $kind === 'js'
                ? Assets::jsMinify($contents)
                : Assets::cssMinify(self::stripCss($contents));
        } catch (\Throwable $e) {
            # A file the minifier could not parse. One file out of many, not an
            # application error - the original is served instead.
            if (function_exists('errorHandler') && Support::config('minify.log-failures', false)) errorHandler($e);
            return null;
        }

        if (!is_string($result) || $result === '') return null;

        # Bigger, or barely smaller. Serving it would mean a second copy on disk
        # and a cache entry to maintain for nothing.
        if (strlen($result) >= strlen($contents)) return null;

        $extension = $kind === 'js' ? 'js' : 'css';
        $relative  = Storage::variantPath($signature, $extension);

        if (!Storage::writeVariant($relative, $result)) return null;

        return [
            'params'       => json_encode(['min' => 1]),
            'format'       => $extension,
            # The type the extension says, not the one the upload recorded. A
            # file stored as text/plain is still a stylesheet, and this copy is
            # one a browser is being asked to apply.
            'mime'         => $kind === 'js' ? 'application/javascript' : 'text/css',
            'width'        => null,
            'height'       => null,
            'size'         => strlen($result),
            'storage_path' => $relative,
            'etag'         => '"' . substr($signature, 0, 32) . '"',
            'build_ms'     => (int) round((hrtime(true) - $started) / 1e6),
        ];
    }

    /**
     * Comments and runs of whitespace, gone.
     *
     * The framework's css minifier is a set of substitutions that leave both -
     * a licence header survives, and `padding: 10px   18px` keeps its three
     * spaces. This runs first and leaves that one the structural work.
     *
     * Written as a scan rather than a regex because both things it removes can
     * appear inside a quoted value that must survive: `content: "/*"` is legal,
     * and so is a font name with two spaces in it.
     *
     * @param string $css
     * @return string
     */
    private static function stripCss(string $css): string
    {
        $out    = '';
        $length = strlen($css);
        $quote  = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];

            if ($quote !== null) {
                $out .= $char;

                # A quote after a backslash is part of the string, not its end.
                if ($char === $quote && ($i === 0 || $css[$i - 1] !== chr(92))) $quote = null;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $out  .= $char;
                continue;
            }

            # A comment, unless it is the `/*! */` kind, which is how a licence
            # asks to be kept.
            if ($char === '/' && ($css[$i + 1] ?? '') === '*' && ($css[$i + 2] ?? '') !== '!') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) break;

                $i = $end + 1;
                continue;
            }

            if (ctype_space($char)) {
                # One space stands in for any run of them, and none at all next
                # to punctuation that cannot be part of an identifier.
                $previous = substr($out, -1);

                if ($previous === '' || strpos(" 	
{};:,>~+(", $previous) !== false) continue;

                $out .= ' ';
                continue;
            }

            # The space we just kept turns out to have been before punctuation.
            if (strpos('{};:,>~+)', $char) !== false && substr($out, -1) === ' ') $out = substr($out, 0, -1);

            $out .= $char;
        }

        return trim($out);
    }

    /**
     * What minifying this file would save, for the panel.
     *
     * Builds it if it has not been built, which is the honest way to answer -
     * an estimate would be a number somebody plans a release around.
     *
     * @param array $file
     * @param array $bucket
     * @return array|null ['size' => int, 'saved' => int, 'share' => int]
     */
    public static function saving(array $file, array $bucket): ?array
    {
        if (!self::enabled() || self::kind($file) === null) return null;

        $variant = self::resolve($file, $bucket);
        if (!$variant) return null;

        $original = (int) $file['size'];
        $size     = (int) $variant['row']['size'];

        return [
            'size'  => $size,
            'saved' => max(0, $original - $size),
            'share' => $original > 0 ? (int) round((1 - $size / $original) * 100) : 0,
        ];
    }
}
