@extends('cdn.main')
@section('title')<?= _l('cdn.operator.system') ?>@endsection
@section('lede')<?= _l('cdn.operator.system-lede') ?>@endsection

@section('body')
<?php include(BASE_PATH . '/resource/views/cdn/partials/operator-nav.php') ?>

<?php
/**
 * What the machine is, read fresh on every render.
 *
 * A row whose value is null is a figure this platform does not have - Windows
 * has no load average, a container may have no /proc/meminfo - and it says so
 * rather than showing a zero somebody would act on.
 */
$row = function (string $key, ?string $value, bool $mono = true): string {
    $shown = $value === null || $value === '' ? '—' : $value;

    return '<div class="d-flex justify-content-between small py-1 border-bottom">'
        . '<span class="hint">' . _l("cdn.system.$key") . '</span>'
        . '<span class="' . ($mono ? 'mono ' : '') . 'notranslate text-end" translate="no">' . e($shown, false) . '</span>'
        . '</div>';
};
?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.system.php') }}</h6>
                <p class="hint">{{ _l('cdn.system.php-lede') }}</p>

                <?php foreach ($info['php'] as $key => $value) : ?>
                    <?= $row('php-' . $key, $value === null ? null : (string) $value) ?>
                <?php endforeach ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.system.memory') }}</h6>
                <p class="hint">{{ _l('cdn.system.memory-lede') }}</p>

                <?php foreach ($info['memory'] as $key => $value) : ?>
                    <?= $row('mem-' . $key, $value === null ? null : (string) $value) ?>
                <?php endforeach ?>

                <?php if (!isset($info['memory']['total'])) : ?>
                    <div class="hint small mt-2">{{ _l('cdn.system.memory-unavailable') }}</div>
                <?php endif ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.system.server') }}</h6>
                <p class="hint">{{ _l('cdn.system.server-lede') }}</p>

                <?php foreach ($info['server'] as $key => $value) : ?>
                    <?= $row('srv-' . $key, $value === null ? null : (string) $value) ?>
                <?php endforeach ?>

                <?= $row('db-version', $info['db']['version'] ?? null) ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.system.capabilities') }}</h6>
                <p class="hint">{{ _l('cdn.operator.capabilities-lede') }}</p>

                <div class="d-flex justify-content-between small py-1 border-bottom">
                    <span class="hint">{{ _l('cdn.operator.image-engine') }}</span>
                    <span>
                        <?php if ($capabilities['driver'] === 'none') : ?>
                            <span class="badge text-bg-danger notranslate" translate="no">none</span>
                        <?php else : ?>
                            <span class="badge text-bg-success notranslate" translate="no"><?= $capabilities['driver'] ?></span>
                        <?php endif ?>
                    </span>
                </div>

                <div class="d-flex justify-content-between small py-1 border-bottom">
                    <span class="hint">{{ _l('cdn.operator.can-write') }}</span>
                    <span class="text-end">
                        <?php foreach ($capabilities['formats'] as $format => $supported) : ?>
                            <span class="badge text-bg-<?= $supported ? 'success' : 'secondary' ?> notranslate" translate="no"><?= $format ?></span>
                        <?php endforeach ?>
                    </span>
                </div>

                <?php # Only what something here asks for. The full list is a
                      # hundred rows nobody reads; a missing one of these
                      # explains a feature that is not working. ?>
                <div class="mt-2">
                    <div class="hint small mb-1">{{ _l('cdn.system.extensions') }}</div>
                    <?php foreach ($extensions as $name => $loaded) : ?>
                        <span class="badge text-bg-<?= $loaded ? 'success' : 'secondary' ?> notranslate" translate="no"><?= $name ?></span>
                    <?php endforeach ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h6>{{ _l('cdn.operator.disks') }}</h6>
        <p class="hint">{{ _l('cdn.system.disks-lede') }}</p>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ _l('cdn.system.disk') }}</th>
                        <th class="text-end">{{ _l('cdn.system.holds') }}</th>
                        <th class="text-end">{{ _l('cdn.operator.free') }}</th>
                        <th class="text-end">{{ _l('cdn.system.total') }}</th>
                        <th style="width: 160px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($disks as $name => $disk) : ?>
                        <tr>
                            <td>
                                <div class="notranslate" translate="no"><?= e((string) $name, false) ?></div>
                                <div class="hint"><?= _l('cdn.system.role-' . $disk['role']) ?></div>
                                <div class="hint mono truncate notranslate" translate="no"><?= e((string) $disk['root'], false) ?></div>

                                <?php if (!$disk['exists']) : ?>
                                    <span class="badge text-bg-danger">{{ _l('cdn.system.missing') }}</span>
                                <?php elseif ($disk['writable'] === false) : ?>
                                    <span class="badge text-bg-danger">{{ _l('cdn.operator.not-writable') }}</span>
                                <?php endif ?>
                            </td>

                            <td class="text-end small notranslate" translate="no">
                                <?= $disk['used'] === null ? '—' : File::humanFileSize($disk['used']) ?>
                            </td>

                            <td class="text-end small notranslate" translate="no">
                                <?= $disk['free'] === null ? '—' : File::humanFileSize($disk['free']) ?>
                            </td>

                            <td class="text-end small notranslate" translate="no">
                                <?= $disk['total'] === null ? '—' : File::humanFileSize($disk['total']) ?>
                            </td>

                            <td>
                                <?php if ($disk['share'] !== null) : ?>
                                    <div class="quota <?= $disk['share'] >= 95 ? 'full' : ($disk['share'] >= 85 ? 'warn' : '') ?>">
                                        <div style="width: <?= $disk['share'] ?>%"></div>
                                    </div>
                                    <div class="hint small text-end notranslate" translate="no"><?= $disk['share'] ?>%</div>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-between small mt-3">
            <span class="hint">{{ _l('cdn.operator.generated-images') }}</span>
            <span class="notranslate" translate="no">
                {{ number_format($variants['files']) }} · {{ File::humanFileSize($variants['bytes']) }}
            </span>
        </div>

        <div class="d-flex justify-content-between small mt-1">
            <span class="hint">{{ _l('cdn.operator.total-stored') }}</span>
            <span class="notranslate" translate="no">{{ File::humanFileSize($totals['storage']) }}</span>
        </div>

        <div class="d-flex justify-content-between small mt-1">
            <span class="hint">{{ _l('cdn.operator.suspended-projects') }}</span>
            <span class="notranslate" translate="no">{{ number_format($totals['suspended']) }}</span>
        </div>
    </div>
</div>
@endsection
