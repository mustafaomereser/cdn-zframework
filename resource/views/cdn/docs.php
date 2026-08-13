<?php

/**
 * Documentation shell: navigation, language switch, and the content for the
 * chosen language.
 *
 * The content files are plain HTML. Anything that would otherwise be read as a
 * template - a php open tag inside a code sample, most of all - is written
 * escaped there, because the compiler does not know it is looking at an
 * example.
 */

use zFramework\Core\Facades\Auth;

$sections = [
    'start'     => ['en' => 'Quick start',      'tr' => 'Hızlı başlangıç'],
    'urls'      => ['en' => 'URLs',             'tr' => 'Adresler'],
    'images'    => ['en' => 'Image sizes',      'tr' => 'Görsel boyutları'],
    'buckets'   => ['en' => 'Buckets',          'tr' => "Bucket'lar"],
    'signed'    => ['en' => 'Signed URLs',      'tr' => 'İmzalı adresler'],
    'api'       => ['en' => 'API',              'tr' => 'API'],
    'upload'    => ['en' => 'Uploading',        'tr' => 'Yükleme'],
    'purge'     => ['en' => 'Clearing cache',   'tr' => 'Önbellek temizleme'],
    'errors'    => ['en' => 'Errors',           'tr' => 'Hatalar'],
    'cli'       => ['en' => 'Command line',     'tr' => 'Komut satırı'],
];
?>
<!DOCTYPE html>
<html lang="<?= $language ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $language === 'tr' ? 'Dokümantasyon' : 'Documentation' ?> · <?= config('app.title') ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= asset('/assets/css/cdn.css') ?>">
</head>

<body class="docs">
    <nav class="public-nav">
        <div class="container d-flex align-items-center justify-content-between gap-3">
            <a class="brand" href="/"><i class="bi bi-hdd-network"></i> <?= config('app.title') ?></a>

            <div class="d-flex align-items-center gap-2">
                <div class="lang-switch">
                    <?php foreach ($languages as $code => $name) : ?>
                        <a href="<?= route('docs.language', ['language' => $code]) ?>"
                           class="<?= $code === $language ? 'active' : '' ?>"><?= strtoupper($code) ?></a>
                    <?php endforeach ?>
                </div>

                <?php if (Auth::check()) : ?>
                    <a href="<?= route('cdn-admin.dashboard') ?>" class="btn btn-primary btn-sm">
                        <?= $language === 'tr' ? 'Panel' : 'Panel' ?>
                    </a>
                <?php else : ?>
                    <a href="<?= route('auth-form') ?>" class="btn btn-outline-secondary btn-sm">
                        <?= $language === 'tr' ? 'Giriş' : 'Sign in' ?>
                    </a>
                <?php endif ?>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="docs-layout">
            <aside class="docs-nav">
                <div class="label mb-2"><?= $language === 'tr' ? 'İçindekiler' : 'Contents' ?></div>
                <?php foreach ($sections as $id => $titles) : ?>
                    <a href="#<?= $id ?>"><?= $titles[$language] ?></a>
                <?php endforeach ?>
            </aside>

            <article class="docs-body">
                <?php include(BASE_PATH . "/resource/views/cdn/docs/$language.php") ?>
            </article>
        </div>
    </div>

    <footer class="site py-4">
        <div class="container d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="hint"><?= config('app.title') ?></span>
            <span class="hint mono">zFramework v<?= FRAMEWORK_VERSION ?> · PHP <?= PHP_VERSION ?></span>
        </div>
    </footer>

    <script>
        // Copy button on every code block, added here rather than written into
        // a hundred samples by hand.
        document.querySelectorAll('.docs-body pre').forEach(block => {
            const button = document.createElement('button');

            button.className = 'copy';
            button.type = 'button';
            button.textContent = '<?= $language === 'tr' ? 'Kopyala' : 'Copy' ?>';

            button.addEventListener('click', () => {
                navigator.clipboard.writeText(block.querySelector('code').innerText);
                button.textContent = '<?= $language === 'tr' ? 'Kopyalandı' : 'Copied' ?>';
                setTimeout(() => button.textContent = '<?= $language === 'tr' ? 'Kopyala' : 'Copy' ?>', 1500);
            });

            block.appendChild(button);
        });

        // Highlight the section being read.
        const links = [...document.querySelectorAll('.docs-nav a')];

        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                links.forEach(link => link.classList.toggle('active', link.hash === '#' + entry.target.id));
            });
        }, { rootMargin: '-80px 0px -70% 0px' });

        document.querySelectorAll('.docs-body section[id]').forEach(section => observer.observe(section));
    </script>
</body>

</html>
