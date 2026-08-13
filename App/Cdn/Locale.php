<?php

namespace App\Cdn;

use zFramework\Core\Facades\Lang;

/**
 * Languages, and building the missing ones on demand.
 *
 * Every language in config/cdn.php -> i18n.languages appears in the switcher,
 * whether or not anybody has generated it. Picking one that does not exist yet
 * builds it - the visitor watches a progress bar for a minute and then reads
 * the site in their language, and so does everybody who comes after them,
 * because what was built is a file in resource/lang like any other.
 *
 * Building happens a chunk at a time. A request that translates three hundred
 * strings one after another is a request that dies of a timeout with nothing to
 * show for it; the browser asks for twenty-five at a time and the draft on disk
 * is the state between calls. Close the tab halfway and the work already paid
 * for is still there for the next person.
 *
 * The draft lives in storage, not in resource/lang. A half-built language must
 * not be selectable: the framework decides what is available by looking for the
 * directory, so a file that appears there before it is finished is a page of
 * blanks. It is moved into place in one step, when it is complete.
 */
class Locale
{
    /**
     * Where drafts are kept while they are being built.
     *
     * @return string
     */
    private static function drafts(): string
    {
        return base_path('storage/cdn/translations');
    }

    /**
     * @param string $code
     * @return string
     */
    private static function draft(string $code): string
    {
        return self::drafts() . '/' . $code . '.json';
    }

    /**
     * @param string $code
     * @return string
     */
    public static function path(string $code): string
    {
        return base_path("resource/lang/$code/cdn.php");
    }

    /**
     * Every language the switcher offers, in the order they are configured.
     *
     * @return array<string,string> code => name
     */
    public static function names(): array
    {
        $names = (array) config('cdn.i18n.languages');

        # On disk but not configured - somebody added a directory by hand. It is
        # still a language of the site, so it is still offered, under its code.
        foreach (Lang::list() as $code) {
            if (!isset($names[$code]) && is_file(self::path($code))) $names[$code] = strtoupper($code);
        }

        return $names;
    }

    /**
     * Hand-written languages. Never built, never labelled machine translated.
     *
     * @return array
     */
    public static function native(): array
    {
        return (array) config('cdn.i18n.native');
    }

    /**
     * Is this a language of this site at all?
     *
     * The code comes out of a url, so this is also what stops `../../etc` from
     * being treated as a language.
     *
     * @param string $code
     * @return bool
     */
    public static function known(string $code): bool
    {
        return isset(self::names()[$code]) && (bool) preg_match('/^[a-z]{2}(-[A-Za-z]{2,4})?$/', $code);
    }

    /**
     * Does a usable file exist for it?
     *
     * @param string $code
     * @return bool
     */
    public static function ready(string $code): bool
    {
        return is_file(self::path($code));
    }

    /**
     * May visitors build a language that is missing?
     *
     * Off means the switcher only offers what has already been generated, and
     * `php cdn translate` is the only way to add one - which is the right
     * setting for a host that would rather not have its address making calls to
     * a translation service because somebody opened a menu.
     *
     * @return bool
     */
    public static function buildable(): bool
    {
        return (bool) (config('cdn.i18n.on-demand.enabled') ?? true);
    }

    /**
     * The English file everything is translated from.
     *
     * @return array
     */
    public static function source(): array
    {
        $file = self::path((string) (config('cdn.i18n.source') ?: 'en'));

        return is_file($file) ? (array) include($file) : [];
    }

    /**
     * What is in the draft, plus anything already published.
     *
     * Published first: a language that is being extended - new strings added to
     * the interface since it was generated - keeps every line somebody may have
     * corrected by hand.
     *
     * @param string $code
     * @return array
     */
    private static function existing(string $code): array
    {
        $published = self::ready($code) ? (array) include(self::path($code)) : [];
        $draft     = is_file($file = self::draft($code)) ? (array) json_decode((string) file_get_contents($file), true) : [];

        return array_replace_recursive($published, $draft);
    }

    /**
     * How far along a language is.
     *
     * @param string $code
     * @return array{done:int,total:int,ready:bool}
     */
    public static function progress(string $code): array
    {
        $source = self::source();
        $total  = Translator::count($source);
        $left   = Translator::missing($source, self::existing($code));

        return [
            'done'  => $total - $left,
            'total' => $total,
            'ready' => self::ready($code) && $left === 0,
        ];
    }

    /**
     * Translate the next chunk of a language.
     *
     * Returns where it got to, so the browser can draw a bar and decide whether
     * to ask again. `stalled` means the chunk translated nothing: the service
     * is refusing or unreachable, and asking again immediately would only be a
     * faster way of being refused.
     *
     * @param string   $code
     * @param int|null $limit
     * @return array{done:int,total:int,ready:bool,stalled:bool}
     */
    public static function step(string $code, ?int $limit = null): array
    {
        $code = trim($code);

        if (!self::known($code) || in_array($code, self::native(), true)) return abort(404);

        $source = self::source();
        $limit ??= max(1, (int) (config('cdn.i18n.on-demand.chunk') ?: 25));

        if (!is_dir($directory = self::drafts())) @mkdir($directory, 0755, true);

        # One builder at a time. Two tabs on the same language would otherwise
        # translate the same strings twice and write over each other's draft -
        # paying for the work twice and finishing no sooner. The second one just
        # reads the progress the first is making.
        $lock   = @fopen($directory . '/' . $code . '.lock', 'c');
        $mine   = $lock && flock($lock, LOCK_EX | LOCK_NB);

        # Somebody else is building it. Report where they have got to and say so,
        # rather than translating the same strings alongside them - the caller
        # waits instead of asking again straight away, which is what stops two
        # tabs on the same language from becoming a busy loop.
        if (!$mine) {
            if ($lock) fclose($lock);

            return self::progress($code) + ['stalled' => false, 'waiting' => true];
        }

        try {
            $result = Translator::fill($source, self::existing($code), $code, $limit);

            file_put_contents(self::draft($code), json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            # Complete: move it where the framework looks for languages. Only
            # now, and in one step, so a visitor never lands on a half-built one.
            if ($result['left'] === 0) {
                Translator::write(self::path($code), $result['data'], $code, self::names()[$code] ?? $code);
                @unlink(self::draft($code));
            }

            $total = Translator::count($source);

            return [
                'done'    => $total - $result['left'],
                'total'   => $total,
                'ready'   => $result['left'] === 0,
                'stalled' => $result['translated'] === 0 && $result['left'] > 0,
                'waiting' => false,
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
