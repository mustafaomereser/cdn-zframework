<?php

namespace App\Controllers;

use App\Cdn\Locale;
use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Facades\Lang;
use zFramework\Core\Facades\Response;

class LanguageController extends Controller
{

    public function __construct()
    {
        //
    }

    /**
     * Switch to a language.
     *
     * A language that has no file yet is not an error - it is the normal way a
     * language starts. The visitor is sent to the page that builds it and comes
     * back here when it is done.
     *
     * @param string $lang
     * @return mixed
     */
    public function set($lang)
    {
        if (Locale::known($lang) && !Locale::ready($lang) && Locale::buildable()) {
            return redirect(route('language.prepare', ['lang' => $lang]) . '?next=' . urlencode($this->next()));
        }

        Lang::locale($lang);

        return redirect($this->next());
    }

    /**
     * The page that builds a missing language.
     *
     * @param string $lang
     * @return mixed
     */
    public function prepare($lang)
    {
        if (!Locale::known($lang) || in_array($lang, Locale::native(), true)) return abort(404);

        # Already there - somebody else built it while this link was sitting in
        # a tab, or the visitor reloaded after it finished.
        if (Locale::ready($lang)) {
            Lang::locale($lang);

            return redirect($this->next());
        }

        if (!Locale::buildable()) return abort(404);

        return view('cdn.language', [
            'code'     => $lang,
            'name'     => Locale::names()[$lang] ?? strtoupper($lang),
            'next'     => $this->next(),
            'progress' => Locale::progress($lang),
        ]);
    }

    /**
     * Translate one chunk. Called by the page above, over and over.
     *
     * @param string $lang
     * @return mixed
     */
    public function build($lang)
    {
        if (!Locale::buildable()) return abort(404);

        return Response::json(Locale::step($lang), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Where to send the visitor afterwards.
     *
     * A path on this site, or the front page. Taking it straight from ?next=
     * would make every language link an open redirect - somebody else's url,
     * reached through ours, with our name in the address bar.
     *
     * @return string
     */
    private function next(): string
    {
        $next = (string) ($_GET['next'] ?? $_POST['next'] ?? $_SERVER['HTTP_REFERER'] ?? '/');

        # A referer is absolute. Only the path is kept, whatever host it names -
        # so the redirect is always to somewhere on this site, and comparing
        # hosts is not needed. Which is just as well: HTTP_HOST carries the port
        # and a referer's host does not, so on any site not served from :80 that
        # comparison says "not ours" about our own pages.
        if (preg_match('#^https?://#i', $next)) {
            $next = (string) parse_url($next, PHP_URL_PATH) . (($query = parse_url($next, PHP_URL_QUERY)) ? "?$query" : '');
        }

        # `//evil.example` is a url, not a path, and every browser treats it as
        # one. So is anything that does not start with a single slash.
        if (!preg_match('#^/(?!/)#', $next)) return '/';

        # Bouncing back to the builder would be a loop.
        if (str_contains($next, '/language/')) return '/';

        return $next;
    }
}
