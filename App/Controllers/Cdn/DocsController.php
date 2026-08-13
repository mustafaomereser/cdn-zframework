<?php

namespace App\Controllers\Cdn;

use App\Cdn\Support;
use zFramework\Core\Facades\Lang;

/**
 * The documentation page.
 *
 * Public: somebody deciding whether to sign up should be able to read how the
 * thing works first, and somebody integrating against it should not have to
 * keep a panel session open to check a parameter name.
 *
 * The two languages are two view files rather than a translation table. A
 * translation table is right for an interface, where the strings are short and
 * the surrounding markup is shared; for prose it produces a file of a hundred
 * numbered fragments that nobody can read or review in either language.
 */
class DocsController
{
    /**
     * Languages the documentation exists in.
     */
    private const LANGUAGES = ['en' => 'English', 'tr' => 'Türkçe'];

    /**
     * @param string|null $language
     * @return mixed
     */
    public function index(?string $language = null): mixed
    {
        $language = strtolower((string) $language);

        # No language in the url: follow whatever the visitor is already reading
        # the site in, and fall back to English.
        if (!isset(self::LANGUAGES[$language])) {
            $current  = strtolower((string) (Lang::currentLocale() ?: config('app.lang')));
            $language = isset(self::LANGUAGES[$current]) ? $current : 'en';
        }

        return view('cdn.docs', [
            'language'  => $language,
            'languages' => self::LANGUAGES,

            # Every example is written against the host it is being read on, so
            # it can be copied and run without editing.
            'host'      => rtrim(host(), '/'),
            'prefix'    => rtrim((string) Support::config('delivery.url-prefix', '/cdn'), '/'),
            'api'       => rtrim((string) Support::config('api.route', '/api/cdn'), '/') . '/v1',
            'panel'     => (string) Support::config('admin.route', '/panel'),
        ]);
    }
}
