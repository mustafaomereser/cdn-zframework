<?php

namespace App\Middlewares;

use App\Cdn\Locale;
use zFramework\Core\Facades\Cookie;
use zFramework\Core\Facades\Lang;

class Language
{
    public function attempt()
    {
        Lang::locale(Cookie::get('lang') ?? null);

        $current = (string) Lang::currentLocale();

        # A machine-built language file is a snapshot of the English one on the
        # day it was built. Add a page to the panel and every one of them is a
        # few strings short - and a missing key renders as nothing at all, so it
        # shows up as a tab with an icon and no label rather than as an error.
        #
        # Picking a language already checks for that. Being on one did not, so
        # somebody who chose German a week ago kept reading a German that was
        # missing whatever had been added since.
        #
        # The check here is two stats: if the source file is newer than this
        # locale's, it may be behind. Only then is anything counted, and only on
        # a page a person is reading.
        if (!Locale::behind($current)) return true;

        # Filling it in is the builder's job, and it comes straight back. Not on
        # a POST - somebody's form submission is not the place - and not on the
        # builder's own pages, which would be a loop.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return true;

        $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');

        if (str_contains((string) $path, '/language/')) return true;

        redirect(route('language.prepare', ['lang' => $current]) . '?next=' . urlencode((string) $_SERVER['REQUEST_URI']));
    }
}
