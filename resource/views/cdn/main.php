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
use App\Cdn\Tenant;
use zFramework\Core\Helpers\File;

$menu = [
    'cdn-admin.dashboard' => ['icon' => 'bi-house',        'title' => 'Overview'],
    'cdn-admin.files'     => ['icon' => 'bi-file-earmark', 'title' => 'Files'],
    'cdn-admin.buckets'   => ['icon' => 'bi-folder',       'title' => 'Buckets'],
    'cdn-admin.keys'      => ['icon' => 'bi-key',          'title' => 'API keys'],
    'cdn-admin.activity'  => ['icon' => 'bi-activity',     'title' => 'Activity'],
    'cdn-admin.settings'  => ['icon' => 'bi-gear',         'title' => 'Settings'],
];

$current = uri();
$project = Tenant::project();
?>
<!DOCTYPE html>
<html lang="{{ zFramework\Core\Facades\Lang::$locale }}" data-bs-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') — {{ config('app.title') }}</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="{{ asset('/assets/libs/notify/style.css') }}" />
    <style>
        body { background: #f6f7f9; }
        .sidebar { min-height: 100vh; background: #16181d; width: 220px; flex: 0 0 220px; }
        .sidebar a { color: #adb5bd; text-decoration: none; display: block; padding: .5rem .85rem; border-radius: .4rem; font-size: .93rem; }
        .sidebar a:hover { background: #23262d; color: #fff; }
        .sidebar a.active { background: #0d6efd; color: #fff; }
        .stat { background: #fff; border: 1px solid #e6e8eb; border-radius: .6rem; padding: 1rem 1.1rem; height: 100%; }
        .stat .value { font-size: 1.5rem; font-weight: 600; line-height: 1.15; }
        .stat .label { color: #6c757d; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
        .card { border: 1px solid #e6e8eb; border-radius: .6rem; }
        code, .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85em; }
        .spark { display: flex; align-items: flex-end; gap: 2px; height: 54px; }
        .spark div { flex: 1; background: #0d6efd; border-radius: 2px 2px 0 0; min-height: 2px; opacity: .8; }
        .table td, .table th { vertical-align: middle; font-size: .9rem; }
        .truncate { max-width: 340px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .hint { color: #6c757d; font-size: .87rem; }
        .dropzone { border: 2px dashed #c9ced6; border-radius: .6rem; padding: 1.5rem; text-align: center; background: #fbfcfd; transition: .15s; }
        .dropzone.over { border-color: #0d6efd; background: #f0f6ff; }
        .quota { height: 6px; border-radius: 3px; background: #e9ecef; overflow: hidden; }
        .quota div { height: 100%; background: #0d6efd; }
    </style>
    @yield('header')
</head>

<body>
    <div class="d-flex">
        <nav class="sidebar p-3">
            <div class="text-white fw-semibold mb-1 px-2">
                <i class="bi bi-hdd-network"></i> {{ config('app.title') }}
            </div>
            <div class="small text-secondary mb-3 px-2 truncate">{{ $project['name'] }}</div>

            <?php foreach ($menu as $route => $item) :
                $path = parse_url(route($route), PHP_URL_PATH);
                # Exact match for Overview, prefix match for the rest - otherwise
                # the panel root is highlighted on every page.
                $active = $route === 'cdn-admin.dashboard' ? rtrim($current, '/') === rtrim($path, '/') : str_starts_with($current, $path);
            ?>
                <a href="{{ route($route) }}" class="<?= $active ? 'active' : '' ?>">
                    <i class="bi {{ $item['icon'] }} me-1"></i> {{ $item['title'] }}
                </a>
            <?php endforeach ?>

            <hr class="text-secondary">

            <div class="px-2 mb-3">
                <div class="small text-secondary d-flex justify-content-between">
                    <span>Storage</span>
                    <span>{{ File::humanFileSize($project['storage_used']) }}</span>
                </div>
                <?php if ($project['storage_quota'] > 0) : ?>
                    <div class="quota mt-1">
                        <div style="width: <?= min(100, round($project['storage_used'] / $project['storage_quota'] * 100)) ?>%"></div>
                    </div>
                    <div class="small text-secondary mt-1">of {{ File::humanFileSize($project['storage_quota']) }}</div>
                <?php endif ?>
            </div>

            <a href="/"><i class="bi bi-box-arrow-up-right me-1"></i> Site</a>
            <a href="#" id="sign-out"><i class="bi bi-box-arrow-right me-1"></i> Sign out</a>
        </nav>

        <main class="flex-grow-1 p-4" style="min-width: 0;">
            <div class="d-flex align-items-start justify-content-between mb-4 gap-3">
                <div>
                    <h4 class="mb-1">@yield('title')</h4>
                    <div class="hint">@yield('lede')</div>
                </div>
                <div class="text-nowrap">@yield('actions')</div>
            </div>

            @yield('body')
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('/assets/libs/notify/script.js') }}"></script>
    <script>
        $.showAlerts(<?= json_encode(\zFramework\Core\Facades\Alerts::get()) ?>);

        // Anything destructive asks first. These are forms rather than links so
        // a crawler or a prefetching browser cannot trigger one by following.
        $('form[data-confirm]').on('submit', function (event) {
            if (!confirm($(this).data('confirm'))) event.preventDefault();
        });

        $('[data-copy]').on('click', function () {
            navigator.clipboard.writeText($(this).data('copy'));

            const button = $(this), original = button.html();
            button.html('<i class="bi bi-check2"></i> Copied');
            setTimeout(() => button.html(original), 1500);
        });

        $('#sign-out').on('click', function (event) {
            event.preventDefault();
            // Csrf::get() rather than the csrf() helper, which echoes a whole
            // hidden input - what is wanted here is the value on its own.
            $.post('{{ route("sign-out") }}', { _token: '<?= \zFramework\Core\Csrf::get() ?>' }, () => window.location = '/');
        });
    </script>
    @yield('footer')
</body>

</html>
