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
$row = function (string $key, ?string $value): string {
    $shown = $value === null || $value === '' ? '—' : $value;

    return '<div class="d-flex justify-content-between small py-1 border-bottom gap-3">'
        . '<span class="hint text-nowrap">' . _l("cdn.system.$key") . '</span>'
        . '<span class="mono notranslate text-end" translate="no">' . e($shown, false) . '</span>'
        . '</div>';
};

/**
 * Directories grouped by the filesystem they sit on.
 *
 * Three directories under one storage root were three rows each claiming a
 * terabyte free - which reads as three disks with a terabyte each, when it is
 * one volume with three directories on it. The volume is stated once, and the
 * directories under it say what this CDN is holding there.
 */
$volumes = [];

foreach ($disks as $diskName => $disk) {
    $key = $disk['device'] ?: ('path:' . $disk['root']);

    $volumes[$key] ??= [
        'total' => $disk['total'],
        'free'  => $disk['free'],
        'share' => $disk['share'],
        'ours'  => 0,
        'paths' => [],
    ];

    $volumes[$key]['ours'] += (int) $disk['used'];
    $volumes[$key]['paths'][$diskName] = $disk;
}
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
                            <span class="badge text-bg-<?= $supported ? 'success' : 'danger' ?> notranslate" translate="no"><?= $format ?></span>
                        <?php endforeach ?>
                    </span>
                </div>

                <?php # Only what something here asks for - the full list is a
                      # hundred rows nobody reads. Missing reads as red, not
                      # grey: a grey badge on a grey card is a thing nobody
                      # sees, and the whole point of this row is the one that
                      # is not there. ?>
                <div class="mt-2">
                    <div class="hint small mb-1">{{ _l('cdn.system.extensions') }}</div>
                    <?php foreach ($extensions as $name => $loaded) : ?>
                        <span class="badge text-bg-<?= $loaded ? 'success' : 'danger' ?> notranslate" translate="no"><?= $name ?></span>
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

        <?php foreach ($volumes as $volume) : ?>
            <div class="volume mb-3">
                <div class="d-flex justify-content-between align-items-end flex-wrap gap-2">
                    <div>
                        <div class="label">{{ _l('cdn.system.volume') }}</div>
                        <div class="mt-1 notranslate" translate="no">
                            <b><?= $volume['free'] === null ? '—' : File::humanFileSize($volume['free']) ?></b>
                            <span class="hint">
                                <?= _l('cdn.system.free-of') ?>
                                <?= $volume['total'] === null ? '—' : File::humanFileSize($volume['total']) ?>
                            </span>
                        </div>
                    </div>

                    <div class="text-end">
                        <div class="label">{{ _l('cdn.system.ours') }}</div>
                        <div class="mt-1 notranslate" translate="no"><b><?= File::humanFileSize($volume['ours']) ?></b></div>
                    </div>
                </div>

                <?php if ($volume['share'] !== null) : ?>
                    <div class="quota <?= $volume['share'] >= 95 ? 'full' : ($volume['share'] >= 85 ? 'warn' : '') ?> mt-2">
                        <div style="width: <?= $volume['share'] ?>%"></div>
                    </div>

                    <?php # Whose the rest of it is: everything else on the same
                          # filesystem, which on shared hosting is most of it. ?>
                    <div class="hint small mt-1"><?= _l('cdn.system.volume-note', ['share' => $volume['share']]) ?></div>
                <?php endif ?>

                <table class="table table-sm align-middle mt-3 mb-0">
                    <thead>
                        <tr>
                            <th>{{ _l('cdn.system.directory') }}</th>
                            <th class="text-end" style="width: 130px">{{ _l('cdn.system.holds') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($volume['paths'] as $diskName => $disk) : ?>
                            <tr>
                                <td>
                                    <div class="notranslate" translate="no">
                                        <b><?= e((string) $diskName, false) ?></b>

                                        <?php if (!$disk['exists']) : ?>
                                            <span class="badge text-bg-danger">{{ _l('cdn.system.missing') }}</span>
                                        <?php elseif ($disk['writable'] === false) : ?>
                                            <span class="badge text-bg-danger">{{ _l('cdn.operator.not-writable') }}</span>
                                        <?php endif ?>
                                    </div>

                                    <div class="hint"><?= _l('cdn.system.role-' . $disk['role']) ?></div>

                                    <?php # The whole path, wrapped rather than cut:
                                          # a path you cannot read is a path you
                                          # cannot check against anything. ?>
                                    <div class="hint mono path notranslate" translate="no"><?= e((string) $disk['root'], false) ?></div>
                                </td>

                                <td class="text-end small notranslate" translate="no">
                                    <?= $disk['used'] === null ? '—' : File::humanFileSize($disk['used']) ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach ?>

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
