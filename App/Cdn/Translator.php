<?php

namespace App\Cdn;

use zFramework\Core\Facades\cURL;

/**
 * Machine-translates the interface strings once, into a real language file.
 *
 * This is the difference between translating a page and translating a product.
 * The widget rewrites every page in the browser on every visit: a round trip
 * per page, a visible flip from English to the target, and an engine that
 * cannot tell a sentence from a shell command. Translating the language file
 * instead happens once, on somebody's terminal, and what comes out is an
 * ordinary locale - rendered by the server, cached like any other page, and
 * editable by a human who knows the language better than the engine did.
 *
 * The code samples are not in the language file at all, so nothing here can
 * translate a curl command by accident.
 *
 * What comes out is a first draft. It is a file, in the repository, that
 * somebody can fix a line of without re-running anything.
 */
class Translator
{
    /**
     * Text that must come back unchanged: {placeholders} and any markup.
     *
     * A translation engine will happily reorder "{count} file(s) uploaded" into
     * something where the brace is gone, or turn <code>?w=400</code> into
     * <code>?g=400</code>. Both are masked into a token that no engine touches
     * and put back afterwards.
     */
    private const MASK = '/(\{[a-z_]+\}|<[^>]+>)/i';

    /**
     * What the current walk is doing: how it treats existing values, how many
     * strings it may still translate, and what to do when a call fails.
     *
     * A property rather than arguments threaded through the recursion, because
     * the budget has to be shared by every level of it.
     */
    private static array $state = [
        'force'    => false,
        'budget'   => null,
        'fallback' => true,
        'progress' => null,
        'spent'    => 0,
    ];

    /**
     * Translate one string.
     *
     * @param string $text
     * @param string $to
     * @param string $from
     * @return string|null Null when the call failed - the caller keeps the
     *                     English rather than writing a broken line.
     */
    public static function text(string $text, string $to, string $from = 'en'): ?string
    {
        $text = trim($text);
        if ($text === '') return $text;

        [$masked, $tokens] = self::mask($text);

        $translated = self::call($masked, $to, $from);
        if ($translated === null) return null;

        return self::unmask($translated, $tokens);
    }

    /**
     * Replace protected fragments with markers.
     *
     * The marker is letters and digits with no spaces or punctuation, which is
     * what survives every engine intact. Anything with a brace, a bracket or a
     * percent sign in it does not.
     *
     * @param string $text
     * @return array{0:string,1:array}
     */
    private static function mask(string $text): array
    {
        $tokens = [];

        $capture = function ($fragment) use (&$tokens) {
            $index = count($tokens);
            $tokens[] = $fragment;

            return "ZQ{$index}QZ";
        };

        $masked = preg_replace_callback(self::MASK, fn($match) => $capture($match[0]), $text);

        # Product words stay in English. Without this the German file called a
        # bucket an "Eimer" - the thing you carry water in - which is not what
        # the URL says, not what the API calls it, and not a word anybody
        # searching the documentation would use.
        $keep = array_filter((array) Support::config('i18n.keep-words', []));

        if (count($keep)) {
            # Longest first, so "API key" is matched before "API".
            usort($keep, fn($a, $b) => strlen($b) <=> strlen($a));

            $pattern = '/\b(' . implode('|', array_map('preg_quote', $keep)) . ')\b/i';
            $masked  = preg_replace_callback($pattern, fn($match) => $capture($match[0]), $masked);
        }

        return [$masked, $tokens];
    }

    /**
     * @param string $text
     * @param array  $tokens
     * @return string
     */
    private static function unmask(string $text, array $tokens): string
    {
        foreach ($tokens as $index => $original) {
            # Engines sometimes space the marker out or change its case, so it is
            # matched loosely rather than compared.
            $text = preg_replace('/Z\s*Q\s*' . $index . '\s*Q\s*Z/i', str_replace('$', '\\$', $original), $text, 1);
        }

        return $text;
    }

    /**
     * One request to whichever endpoint is configured.
     *
     * @param string $text
     * @param string $to
     * @param string $from
     * @return string|null
     */
    private static function call(string $text, string $to, string $from): ?string
    {
        $config  = (array) Support::config('i18n.translator', []);
        $timeout = (int) ($config['timeout'] ?? 15);
        $key     = $config['key'] ?? null;

        # The official API when a key is configured: supported, billed, and it
        # does not change shape without warning.
        if ($key) {
            $response = cURL::set('https://translation.googleapis.com/language/translate/v2?key=' . urlencode($key))
                ->options([
                    CURLOPT_POST           => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $timeout,
                    CURLOPT_POSTFIELDS     => http_build_query([
                        'q'      => $text,
                        'source' => $from,
                        'target' => $to,
                        'format' => 'text',
                    ]),
                ])->send();

            $data = json_decode((string) $response, true);

            return $data['data']['translations'][0]['translatedText'] ?? null;
        }

        # Otherwise the endpoint the widget itself uses. No key, no quota to set
        # up - and no promises: it is undocumented, rate limited by address, and
        # can stop answering. Which is survivable here, because this runs once
        # on a terminal rather than on every request a visitor makes.
        $url = ($config['endpoint'] ?? 'https://translate.googleapis.com/translate_a/single')
            . '?' . http_build_query(['client' => 'gtx', 'sl' => $from, 'tl' => $to, 'dt' => 't', 'q' => $text]);

        $response = cURL::set($url)->options([
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; zFramework-CDN)',
        ])->send();

        $data = json_decode((string) $response, true);
        if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) return null;

        # The reply is the sentence split into pieces; joining them back is the
        # translation.
        $out = '';
        foreach ($data[0] as $piece) $out .= $piece[0] ?? '';

        return $out === '' ? null : $out;
    }

    /**
     * Walk a language array, translating every string in it.
     *
     * Existing values are kept unless $force: a line somebody corrected by hand
     * must not be overwritten by the engine the next time this runs.
     *
     * @param array         $source   The English structure.
     * @param array         $existing What the target file already has.
     * @param string        $to
     * @param bool          $force
     * @param callable|null $progress Called with (key path, before, after).
     * @return array
     */
    public static function walk(array $source, array $existing, string $to, bool $force = false, ?callable $progress = null): array
    {
        self::$state = [
            'force'    => $force,
            'budget'   => null,
            'fallback' => true,
            'progress' => $progress,
            'spent'    => 0,
        ];

        return self::merge($source, $existing, $to);
    }

    /**
     * Translate at most $limit of the strings that are still missing.
     *
     * The web does not get a command's patience: a request that translates
     * three hundred strings one at a time is a request that dies of a timeout
     * halfway through. So the browser asks for a chunk at a time and the file
     * being built is the state between calls - stop at any point and nothing is
     * lost, ask again and it carries on where it stopped.
     *
     * Unlike walk(), a string that could not be translated is left out rather
     * than filled with the English. It has to stay missing, or the next pass
     * would see a value there and never try it again.
     *
     * @param array  $source
     * @param array  $existing
     * @param string $to
     * @param int    $limit
     * @return array{data:array,translated:int,left:int}
     */
    public static function fill(array $source, array $existing, string $to, int $limit): array
    {
        self::$state = [
            'force'    => false,
            'budget'   => max(1, $limit),
            'fallback' => false,
            'progress' => null,
            'spent'    => 0,
        ];

        $data = self::merge($source, $existing, $to);

        return [
            'data'       => $data,
            'translated' => self::$state['spent'],
            'left'       => self::missing($source, $data),
        ];
    }

    /**
     * How many strings in $source have no value in $existing yet.
     *
     * @param array $source
     * @param array $existing
     * @return int
     */
    public static function missing(array $source, array $existing): int
    {
        $left = 0;

        foreach ($source as $key => $value) {
            $have = $existing[$key] ?? null;

            if (is_array($value))        $left += self::missing($value, is_array($have) ? $have : []);
            elseif (!is_string($value))  continue;
            elseif (!is_string($have) || $have === '') $left++;
        }

        return $left;
    }

    /**
     * The walk both of the above are.
     *
     * @param array  $source
     * @param array  $existing
     * @param string $to
     * @param string $path
     * @return array
     */
    private static function merge(array $source, array $existing, string $to, string $path = ''): array
    {
        $output = [];

        foreach ($source as $key => $value) {
            $here = $path === '' ? (string) $key : "$path.$key";
            $have = $existing[$key] ?? null;

            if (is_array($value)) {
                $output[$key] = self::merge($value, is_array($have) ? $have : [], $to, $here);
                continue;
            }

            if (!is_string($value)) {
                $output[$key] = $value;
                continue;
            }

            if (!self::$state['force'] && is_string($have) && $have !== '') {
                $output[$key] = $have;
                continue;
            }

            # Out of budget: the rest is the next call's work, and leaving the
            # key out is what tells that call there is work left.
            if (self::$state['budget'] !== null && self::$state['spent'] >= self::$state['budget']) continue;

            $translated = self::text($value, $to);

            if ($translated === null && !self::$state['fallback']) continue;

            # A failed call keeps the English. A half-translated file that reads
            # is better than a file with holes in it.
            $output[$key] = $translated ?? $value;

            self::$state['spent']++;

            if (self::$state['progress']) (self::$state['progress'])($here, $value, $output[$key]);
        }

        return $output;
    }

    /**
     * Write a language array out as a php file.
     *
     * @param string $path
     * @param array  $data
     * @param string $code Language code, for the line that regenerates it.
     * @param string $name Its name, for somebody reading the file.
     * @return bool
     */
    public static function write(string $path, array $data, string $code, string $name): bool
    {
        $header = <<<PHP
<?php

/**
 * CDN interface strings - $name.
 *
 * Generated by `php cdn translate lang=$code` from the English file, which is
 * the source of truth. Edit freely: running the command again keeps every line
 * that already has a value and only fills in the ones that are missing. Pass
 * --force to have the machine overwrite yours.
 *
 * It is a first draft. Product words - bucket, CDN, ETag, webp - are left in
 * English on purpose; see i18n.keep-words.
 */
return
PHP;

        if (!is_dir($directory = dirname($path))) @mkdir($directory, 0755, true);

        return (bool) file_put_contents($path, $header . ' ' . self::export($data) . ";\n");
    }

    /**
     * The array, printed the way the hand-written language files are written.
     *
     * var_export would do, but it prints `array (` on the line below the key
     * and escapes nothing else the way anybody here writes it. These files are
     * meant to be edited by hand after the machine has had its go, so they are
     * printed to match the ones already in the repository.
     *
     * @param array $data
     * @param int   $depth
     * @return string
     */
    private static function export(array $data, int $depth = 0): string
    {
        $pad  = str_repeat('    ', $depth + 1);
        $out  = "[\n";

        foreach ($data as $key => $value) {
            $out .= $pad . self::quote((string) $key) . ' => '
                 . (is_array($value) ? self::export($value, $depth + 1) : self::quote((string) $value))
                 . ",\n";
        }

        return $out . str_repeat('    ', $depth) . ']';
    }

    /**
     * A single-quoted php string. Only the quote and the backslash mean
     * anything inside one, so only those two are escaped - the accents and the
     * non-latin scripts these files are full of stay readable.
     *
     * @param string $value
     * @return string
     */
    private static function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * Every string in a language array, counted - so a command can say how much
     * work it is about to do.
     *
     * @param array $data
     * @return int
     */
    public static function count(array $data): int
    {
        $total = 0;

        foreach ($data as $value) $total += is_array($value) ? self::count($value) : (is_string($value) ? 1 : 0);

        return $total;
    }
}
