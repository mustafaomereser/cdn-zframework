<?php

/**
 * The language switcher.
 *
 * Every configured language is in it, translated or not. The ones with a file
 * under resource/lang/<code>/cdn.php are a plain page reload rendered by the
 * server - no third-party script, no round trip per visit, no flip from English
 * to the target while the visitor watches. The ones without are built when
 * somebody picks them: a page with a bar on it for about a minute, and then
 * they are a file too, for everybody who comes after.
 *
 * So the list does not shrink to what has been generated. A language the
 * visitor reads is either there or one click from being there, and nobody has
 * to know which until they look at the bar.
 *
 * The old in-browser widget is still here, off unless i18n.translate-widget
 * turns it on. It is now only for a language on-demand cannot build, and it
 * comes with the caveat it always had: it cannot tell a sentence from a shell
 * command, so anything that must survive intact is marked translate="no"
 * before it loads.
 *
 * Every variable in here is prefixed. The compiler splices layout, partials and
 * page into one file and this partial runs in the layout's nav - before the
 * page body - so a plain $name or $code would still be holding the last
 * language in the list by the time the page used its own. That is not a
 * hypothetical: it is why a page asked to build German built Japanese.
 *
 * @var string $langArea 'public' or 'panel'
 */

use App\Cdn\Locale;
use zFramework\Core\Facades\Lang;

$langArea    ??= 'public';
$langCurrent  = Lang::currentLocale();
$langWidget   = (bool) (config('cdn.i18n.translate-widget')[$langArea] ?? false);

$langHand    = Locale::native();
$langBuild   = Locale::buildable();

$langNative = $langMachine = $langMissing = $langFallback = [];

foreach (Locale::names() as $langCode => $langName) {
    if (Locale::ready($langCode)) {
        if (in_array($langCode, $langHand, true)) $langNative[$langCode] = $langName;
        else                              $langMachine[$langCode] = $langName;

        continue;
    }

    # No file. Offered anyway when it can be built - and when it cannot, only
    # if the widget is there to render it in the browser instead.
    if ($langBuild && !in_array($langCode, $langHand, true)) $langMissing[$langCode] = $langName;
    elseif ($langWidget)                             $langFallback[$langCode] = $langName;
}

# Where to come back to once it is built. The switcher is on every page, so
# this is whichever one they were reading.
$langHere = (string) ($_SERVER['REQUEST_URI'] ?? '/');
?>

<div class="lang-menu dropdown">
    <button class="lang-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-translate"></i>
        <span class="notranslate" translate="no" id="lang-current"><?= strtoupper($langCurrent) ?></span>
    </button>

    <div class="dropdown-menu dropdown-menu-end lang-list">
        <?php # The href works with javascript off; with it on, the handler
              # clears any leftover widget cookie first and navigates itself,
              # so a reload cannot arrive before the cookie is gone. ?>
        <?php foreach ($langNative as $langCode => $langName) : ?>
            <a class="dropdown-item <?= $langCode === $langCurrent ? 'active' : '' ?>" data-lang="<?= $langCode ?>"
               href="<?= route('language', ['lang' => $langCode]) ?>"
               onclick="return cdnNative(this.href)">
                <span class="notranslate" translate="no"><?= $langName ?></span>
                <i class="bi bi-check2 ms-auto lang-tick <?= $langCode === $langCurrent ? '' : 'd-none' ?>"></i>
            </a>
        <?php endforeach ?>

        <?php if (count($langMachine)) : ?>
            <div class="dropdown-divider"></div>
            <div class="lang-note"><?= _l('cdn.common.translated') ?></div>

            <?php # Also a plain reload. These are files like the ones above -
                  # the only difference is who wrote the first draft. ?>
            <?php foreach ($langMachine as $langCode => $langName) : ?>
                <a class="dropdown-item <?= $langCode === $langCurrent ? 'active' : '' ?>" data-lang="<?= $langCode ?>"
                   href="<?= route('language', ['lang' => $langCode]) ?>"
                   onclick="return cdnNative(this.href)">
                    <span class="notranslate" translate="no"><?= $langName ?></span>
                    <i class="bi bi-check2 ms-auto lang-tick <?= $langCode === $langCurrent ? '' : 'd-none' ?>"></i>
                </a>
            <?php endforeach ?>
        <?php endif ?>

        <?php if (count($langMissing)) : ?>
            <div class="dropdown-divider"></div>
            <div class="lang-note"><?= _l('cdn.language.build') ?></div>

            <?php # Not translated yet. Picking one starts it - which is a page
                  # with a bar on it for a minute, and then this language moves
                  # up into the group above and stays there. ?>
            <?php foreach ($langMissing as $langCode => $langName) : ?>
                <a class="dropdown-item lang-build" data-lang="<?= $langCode ?>"
                   href="<?= route('language.prepare', ['lang' => $langCode]) ?>?next=<?= urlencode($langHere) ?>">
                    <span class="notranslate" translate="no"><?= $langName ?></span>
                    <i class="bi bi-download ms-auto"></i>
                </a>
            <?php endforeach ?>
        <?php endif ?>

        <?php if (count($langFallback)) : ?>
            <div class="dropdown-divider"></div>

            <?php # Translated in the browser, on every page, every visit. The
                  # cure is `php cdn translate lang=<code>`, after which the
                  # language moves up into the list above. ?>
            <?php foreach ($langFallback as $langCode => $langName) : ?>
                <a class="dropdown-item notranslate" translate="no" data-lang="<?= $langCode ?>"
                   href="javascript:void(0)" onclick="cdnTranslate('<?= $langCode ?>')">
                    <?= $langName ?>
                    <i class="bi bi-check2 ms-auto lang-tick d-none"></i>
                </a>
            <?php endforeach ?>
        <?php endif ?>
    </div>
</div>

<?php if ($langWidget) : ?>
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

        const cdnLocale  = '<?= $langCurrent ?>';
        const cdnEnglish = '<?= route('language', ['lang' => 'en']) ?>';
        const cdnWanted  = localStorage.getItem('cdn-translate');

        // The request goes out here rather than on DOMContentLoaded: this runs
        // while the rest of the body is still parsing, which is the earliest it
        // can be in flight - and the time it is in flight is the time the page
        // is still in English.
        //
        // The page is not hidden while it waits. That was tried: it traded a
        // brief flip for up to three seconds of blank screen, and a blank screen
        // reads as a slow application rather than as a careful one.
        if (cdnWanted && cdnLocale === 'en') {
            ['https://translate.googleapis.com', 'https://translate-pa.googleapis.com', 'https://translate.google.com']
                .forEach(function (origin) {
                    const link = document.createElement('link');
                    link.rel = 'preconnect';
                    link.href = origin;
                    link.crossOrigin = '';
                    document.head.appendChild(link);
                });
        }

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

            (document.body || document.head).appendChild(script);
        }

        // Known before the document is finished, so the script is requested now
        // rather than after DOMContentLoaded.
        if (cdnWanted && cdnLocale === 'en') cdnLoadWidget();

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
            // Nothing chosen: make sure a leftover cookie cannot translate the
            // page behind our back.
            if (!cdnWanted) return cdnForgetCookie();

            if (cdnLocale !== 'en') return window.location = cdnEnglish;

            // The script was already requested above; this only marks the menu
            // and nudges the widget if its own cookie did not restore.
            cdnMarkActive(cdnWanted);
            cdnApply(cdnWanted);
        });
    </script>

    <style>
        /* The widget pushes the document down by the height of a banner it is
           told not to show. */
        body { top: 0 !important; }
        .skiptranslate, .VIpgJd-ZVi9od-ORHb, .VIpgJd-ZVi9od-ORHb-OEVmcd { display: none !important; }

    </style>
<?php endif ?>
