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
    'cdn-admin.dashboard' => ['icon' => 'bi-grid-1x2',    'title' => 'Overview'],
    'cdn-admin.files'     => ['icon' => 'bi-file-earmark', 'title' => 'Files'],
    'cdn-admin.buckets'   => ['icon' => 'bi-folder2',     'title' => 'Buckets'],
    'cdn-admin.keys'      => ['icon' => 'bi-key',         'title' => 'API keys'],
    'cdn-admin.activity'  => ['icon' => 'bi-activity',    'title' => 'Activity'],
    'cdn-admin.settings'  => ['icon' => 'bi-sliders',     'title' => 'Settings'],
];

$current = uri();
$project = Tenant::project();

$used  = (int) $project['storage_used'];
$quota = (int) $project['storage_quota'];
$share = $quota > 0 ? min(100, round($used / $quota * 100)) : 0;
?>
<!DOCTYPE html>
<html lang="{{ zFramework\Core\Facades\Lang::$locale }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') · {{ config('app.title') }}</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="{{ asset('/assets/libs/notify/style.css') }}">

    <?php # After select2, which it restyles. ?>
    <link rel="stylesheet" href="{{ asset('/assets/css/cdn.css') }}">
    @yield('header')
</head>

<body>
    <div class="d-flex">
        <nav class="sidebar">
            <div class="brand"><i class="bi bi-hdd-network"></i> {{ config('app.title') }}</div>
            <div class="project truncate">{{ $project['name'] }}</div>

            <?php foreach ($menu as $route => $item) :
                $path = parse_url(route($route), PHP_URL_PATH);
                # Exact match for Overview, prefix match for the rest - otherwise
                # the panel root is highlighted on every page.
                $active = $route === 'cdn-admin.dashboard'
                    ? rtrim($current, '/') === rtrim($path, '/')
                    : str_starts_with($current, $path);
            ?>
                <a href="{{ route($route) }}" class="<?= $active ? 'active' : '' ?>">
                    <i class="bi {{ $item['icon'] }}"></i> {{ $item['title'] }}
                </a>
            <?php endforeach ?>

            <hr>

            <div class="usage">
                <div class="row-line">
                    <span>Storage</span>
                    <b>{{ File::humanFileSize($used) }}</b>
                </div>
                <?php if ($quota > 0) : ?>
                    <div class="quota <?= $share >= 95 ? 'full' : ($share >= 80 ? 'warn' : '') ?>">
                        <div style="width: <?= $share ?>%"></div>
                    </div>
                    <div class="row-line mt-1"><span>of {{ File::humanFileSize($quota) }}</span><b><?= $share ?>%</b></div>
                <?php endif ?>
            </div>

            <a href="/" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Public site</a>

            <?php /* A form, not a script: a sign-out that depends on javascript
                     is a session somebody cannot end when the javascript fails
                     to load. */ ?>
            <form action="{{ route('sign-out') }}" method="POST" class="m-0">
                <?= csrf() ?>
                <button type="submit" class="link-button"><i class="bi bi-box-arrow-right"></i> Sign out</button>
            </form>
        </nav>

        <main class="flex-grow-1">
            <div class="page-head">
                <div>
                    <h4>@yield('title')</h4>
                    <div class="hint">@yield('lede')</div>
                </div>
                <div class="text-nowrap">@yield('actions')</div>
            </div>

            @yield('body')
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('/assets/libs/notify/script.js') }}"></script>
    <script>
        // Guarded: this used to start with a call into the notify library, and
        // if that had not loaded the exception took every handler below it with
        // it - including, until it became a form, sign-out.
        try { $.showAlerts(<?= json_encode(\zFramework\Core\Facades\Alerts::get()) ?>); } catch (thrown) {}

        $('form[data-confirm]').on('submit', function (event) {
            if (!confirm($(this).data('confirm'))) event.preventDefault();
        });

        $('[data-copy]').on('click', function () {
            navigator.clipboard.writeText($(this).data('copy'));

            const button = $(this), original = button.html();
            button.html('<i class="bi bi-check2"></i> Copied');
            setTimeout(() => button.html(original), 1500);
        });

        // Select2 on every select that has not opted out. The search box only
        // appears once a list is long enough to need one - on a three-option
        // filter it is furniture.
        if ($.fn.select2) $('select:not([data-plain])').each(function () {
            const field = $(this);

            field.select2({
                width: '100%',
                minimumResultsForSearch: field.find('option').length > 8 ? 0 : Infinity,
                placeholder: field.data('placeholder') || null,
                allowClear: Boolean(field.data('placeholder')),
                closeOnSelect: !field.prop('multiple'),
            });
        });

        // Filters that submit as soon as they change. Bound through jQuery
        // rather than an inline onchange: select2 raises its change event on
        // the original element through jQuery, and an inline handler never
        // hears it - which would leave every filter in the panel doing nothing.
        $('select[data-autosubmit], input[data-autosubmit]').on('change', function () {
            this.form.submit();
        });
    </script>
    @yield('footer')
</body>

</html>
