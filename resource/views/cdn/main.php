<?php

/**
 * Aliases for every page in the panel.
 *
 * They belong here and nowhere else: the compiler splices a page into this
 * layout and writes one file, so the same `use` in both is a fatal "name
 * already in use" - which surfaces as a broken page rather than as an obvious
 * mistake in the template.
 */

use App\Cdn\Support;
use zFramework\Core\Helpers\File;

$menu = [
    'cdn-admin.dashboard' => ['icon' => 'fa-gauge-high', 'title' => 'Dashboard'],
    'cdn-admin.buckets'   => ['icon' => 'fa-box-archive', 'title' => 'Buckets'],
    'cdn-admin.files'     => ['icon' => 'fa-file-lines',  'title' => 'Files'],
    'cdn-admin.keys'      => ['icon' => 'fa-key',         'title' => 'API Keys'],
    'cdn-admin.logs'      => ['icon' => 'fa-list',        'title' => 'Access Log'],
    'cdn-admin.purges'    => ['icon' => 'fa-broom',       'title' => 'Purges'],
    'cdn-admin.settings'  => ['icon' => 'fa-sliders',     'title' => 'Settings'],
];
?>
<!DOCTYPE html>
<html lang="{{ zFramework\Core\Facades\Lang::$locale }}" data-bs-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CDN — @yield('title')</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="{{ asset('/assets/libs/notify/style.css') }}" />
    <style>
        body { background: #f6f7f9; }
        .sidebar { min-height: 100vh; background: #16181d; }
        .sidebar a { color: #adb5bd; text-decoration: none; display: block; padding: .55rem .9rem; border-radius: .4rem; font-size: .93rem; }
        .sidebar a:hover { background: #23262d; color: #fff; }
        .sidebar a.active { background: #0d6efd; color: #fff; }
        .stat { background: #fff; border: 1px solid #e6e8eb; border-radius: .6rem; padding: 1rem 1.1rem; height: 100%; }
        .stat .value { font-size: 1.6rem; font-weight: 600; line-height: 1.1; }
        .stat .label { color: #6c757d; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; }
        .card { border: 1px solid #e6e8eb; border-radius: .6rem; }
        code, .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85em; }
        .spark { display: flex; align-items: flex-end; gap: 2px; height: 60px; }
        .spark div { flex: 1; background: #0d6efd; border-radius: 2px 2px 0 0; min-height: 2px; opacity: .85; }
        .table td, .table th { vertical-align: middle; font-size: .9rem; }
        .truncate { max-width: 340px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    </style>
    @yield('header')
</head>

<body>
    <div class="d-flex">
        <nav class="sidebar p-3" style="width: 230px; flex: 0 0 230px;">
            <div class="text-white fw-semibold mb-3 px-2">
                <i class="bi bi-hdd-network"></i> CDN
                <div class="small text-secondary">{{ config('app.title') }}</div>
            </div>

            <?php foreach ($menu as $route => $item) : ?>
                <a href="{{ route($route) }}" class="<?= str_contains(uri(), parse_url(route($route), PHP_URL_PATH)) && (uri() === parse_url(route($route), PHP_URL_PATH) || $route !== 'cdn-admin.dashboard') ? 'active' : '' ?>">
                    <i class="bi {{ $item['icon'] }} me-1"></i> {{ $item['title'] }}
                </a>
            <?php endforeach ?>

            <hr class="text-secondary">
            <a href="/"><i class="bi bi-box-arrow-up-right me-1"></i> Site</a>
            <a href="{{ route('sign-out') }}"><i class="bi bi-box-arrow-right me-1"></i> Sign out</a>
        </nav>

        <main class="flex-grow-1 p-4" style="min-width: 0;">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <h4 class="mb-0">@yield('title')</h4>
                <div>@yield('actions')</div>
            </div>

            @yield('body')
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('/assets/libs/notify/script.js') }}"></script>
    <script>
        $.showAlerts(<?= json_encode(\zFramework\Core\Facades\Alerts::get()) ?>);

        // Anything that deletes asks first. The forms POST rather than link, so
        // a crawler or a prefetching browser cannot trigger one by following.
        $('form[data-confirm]').on('submit', function (event) {
            if (!confirm($(this).data('confirm'))) event.preventDefault();
        });

        $('[data-copy]').on('click', function () {
            navigator.clipboard.writeText($(this).data('copy'));
            $(this).addClass('btn-success').text('Copied');
        });
    </script>
    @yield('footer')
</body>

</html>
