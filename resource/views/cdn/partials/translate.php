<?php

/**
 * The language switcher, and Google's translate widget behind it.
 *
 * Two different things, deliberately kept apart:
 *
 *   - English and Turkish are translated by hand, live in resource/lang, and
 *     are switched by reloading the page. Nothing is guessed and nothing moves.
 *   - Everything else is the widget, which rewrites the page in the browser.
 *
 * The widget cannot tell a sentence from a shell command, so before it loads,
 * every element that must survive intact is marked `translate="no"`: code
 * blocks, urls, monospaced values, and anything holding a bucket name, a path
 * or an api key. A translated curl command is a command that does not run, and
 * a translated bucket name is a 404.
 *
 * Machine translation always starts from English. Turkish is a hand-written
 * translation of the English, so translating Turkish into German is a copy of
 * a copy - and every engine has far more English to work from than Turkish.
 * Picking a machine language therefore switches the page to English first and
 * translates that.
 *
 * @var string $area 'public' or 'panel'
 */

use zFramework\Core\Facades\Lang;

$area    ??= 'public';
$current  = Lang::currentLocale();
$widget   = (bool) (config('cdn.i18n.translate-widget')[$area] ?? false);
$extra    = $widget ? (array) config('cdn.i18n.widget-languages') : [];

# The hand-translated ones, from the directories that actually exist.
$native = array_values(array_intersect(Lang::list(), ['en', 'tr']));
?>

<div class="lang-menu dropdown">
    <button class="lang-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-translate"></i>
        <span class="notranslate" translate="no" id="lang-current"><?= strtoupper($current) ?></span>
    </button>

    <div class="dropdown-menu dropdown-menu-end lang-list">
        <?php foreach ($native as $code) : ?>
            <?php # The href still works with javascript off; with it on, the
                  # handler clears the machine translation first and navigates
                  # itself, so the two cannot race. ?>
            <a class="dropdown-item <?= $code === $current ? 'active' : '' ?>" data-lang="<?= $code ?>"
               href="<?= route('language', ['lang' => $code]) ?>"
               onclick="return cdnNative(this.href)">
                <span class="notranslate" translate="no"><?= $code === 'tr' ? 'Türkçe' : 'English' ?></span>
                <i class="bi bi-check2 ms-auto lang-tick <?= $code === $current ? '' : 'd-none' ?>"></i>
            </a>
        <?php endforeach ?>

        <?php if (count($extra)) : ?>
            <div class="dropdown-divider"></div>
            <div class="lang-note"><?= _l('cdn.common.translated') ?></div>

            <?php foreach ($extra as $code => [$name, $flag]) : ?>
                <a class="dropdown-item notranslate" translate="no" data-lang="<?= $code ?>"
                   href="javascript:void(0)" onclick="cdnTranslate('<?= $code ?>')">
                    <?= $name ?>
                    <i class="bi bi-check2 ms-auto lang-tick d-none"></i>
                </a>
            <?php endforeach ?>
        <?php endif ?>
    </div>
</div>

<?php if ($widget) : ?>
    <div id="google_translate_element" style="display: none"></div>

    <script>
        (function () {
            // Everything that must not be rewritten, marked before the widget
            // is asked to load. A translated `curl` line or bucket name is
            // worse than an untranslated page.
            const keep = 'pre, code, .mono, .url-demo, [data-copy], .notranslate, [data-keep]';

            document.querySelectorAll(keep).forEach(function (element) {
                element.setAttribute('translate', 'no');
                element.classList.add('notranslate');
            });
        })();

        const cdnLocale  = '<?= $current ?>';
        const cdnEnglish = '<?= route('language', ['lang' => 'en']) ?>';

        function googleTranslateElementInit() {
            // Always English: the page is switched to it before any machine
            // translation is applied, so this is never a lie about what the
            // engine is reading.
            new google.translate.TranslateElement({
                pageLanguage: 'en',
                autoDisplay: false,
            }, 'google_translate_element');
        }

        // Loaded the first time somebody actually asks for a machine language,
        // not on every page. It is a third-party script on a slow third-party
        // host: fetching it for the visits that never use it costs everyone the
        // wait, and a hung request hangs the page it was injected into.
        function cdnLoadWidget() {
            if (document.getElementById('cdn-gtranslate')) return;

            const script = document.createElement('script');

            script.id  = 'cdn-gtranslate';
            script.src = 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';

            document.body.appendChild(script);
        }

        function cdnTranslate(language) {
            localStorage.setItem('cdn-translate', language);

            // Translating the Turkish page would be translating a translation.
            // Switch to English and let the reload apply the choice.
            if (cdnLocale !== 'en') return window.location = cdnEnglish;

            cdnLoadWidget();
            cdnApply(language);
        }

        // The widget has no api of its own: the only way in is to set the value
        // of the select it injects and tell the page it changed.
        //
        // Only when it is not already there. The widget restores its own choice
        // from a cookie as it initialises, so dispatching on top of that ran the
        // translation twice - two calls to translateHtml, and a visible flicker
        // as the page was rewritten, reverted and rewritten again.
        let cdnPending = null;

        function cdnApply(language) {
            const select = document.querySelector('.goog-te-combo');

            if (!select || !select.options.length) {
                clearTimeout(cdnPending);
                cdnPending = setTimeout(function () { cdnApply(language); }, 400);
                return;
            }

            cdnMarkActive(language);

            if (select.value === language) return;

            select.value = language;
            select.dispatchEvent(new Event('change'));
        }

        // The tick and the button label are rendered from the server's idea of
        // the locale, which is English whenever a machine language is on - so
        // without this the menu says English while the page is in Uzbek.
        function cdnMarkActive(language) {
            const label = document.getElementById('lang-current');
            if (label) label.textContent = language.toUpperCase();

            document.querySelectorAll('.lang-list .dropdown-item').forEach(function (item) {
                const active = item.dataset.lang === language;

                item.classList.toggle('active', active);
                item.querySelector('.lang-tick')?.classList.toggle('d-none', !active);
            });
        }

        // The widget remembers its choice in a cookie of its own, and it writes
        // it under more than one domain and path depending on where it runs.
        // Missing one of them is why picking English used to land on a page
        // that translated itself again a second later.
        function cdnForgetCookie() {
            const host    = location.hostname;
            const domains = ['', host, '.' + host];
            const parts   = host.split('.');

            // …and on the registrable domain, which is where it writes when the
            // site is on a subdomain.
            if (parts.length > 2) domains.push('.' + parts.slice(-2).join('.'));

            domains.forEach(function (domain) {
                ['/', location.pathname].forEach(function (path) {
                    document.cookie = 'googtrans=; Max-Age=0; path=' + path + (domain ? '; domain=' + domain : '');
                });
            });
        }

        // Going back to a hand-translated language: clear first, navigate after,
        // so the reload cannot arrive before the cookie is gone.
        function cdnNative(href) {
            localStorage.removeItem('cdn-translate');
            cdnForgetCookie();

            window.location = href;
            return false;
        }

        document.addEventListener('DOMContentLoaded', function () {
            const saved = localStorage.getItem('cdn-translate');

            // Nothing chosen: make sure a leftover cookie cannot translate the
            // page behind our back.
            if (!saved) return cdnForgetCookie();

            if (cdnLocale !== 'en') return window.location = cdnEnglish;

            // Marked immediately so the menu is right while the widget is still
            // loading, then again by cdnApply once it has actually taken.
            cdnMarkActive(saved);
            cdnLoadWidget();

            // Given a moment to restore its own cookie first: if it does, the
            // check inside cdnApply finds the value already set and leaves the
            // page alone rather than translating it a second time.
            setTimeout(function () { cdnApply(saved); }, 1200);
        });
    </script>

    <style>
        /* The widget pushes the document down by the height of a banner it is
           told not to show. */
        body { top: 0 !important; }
        .skiptranslate, .VIpgJd-ZVi9od-ORHb, .VIpgJd-ZVi9od-ORHb-OEVmcd { display: none !important; }
    </style>
<?php endif ?>
