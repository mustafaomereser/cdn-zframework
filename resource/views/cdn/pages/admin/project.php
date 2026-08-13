@extends('cdn.main')
@section('title')<?= e($project['name'], false) ?>@endsection
@section('lede')<?= $prefix ?>/<?= e($project['slug'], false) ?>/@endsection

@section('body')
<?php include(BASE_PATH . '/resource/views/cdn/partials/operator-nav.php') ?>

<?php
$suspended = ($project['status'] ?? 'active') !== 'active';
$custom    = ($project['quota_mode'] ?? 'account') === 'custom';
$month     = ($project['bandwidth_period'] ?? null) === date('Y-m') ? (int) $project['bandwidth_used'] : 0;

$split = function (int $bytes) use ($units): array {
    if ($bytes <= 0) return [0, 'GB'];

    foreach (array_reverse($units, true) as $unitName => $size) {
        if ($bytes >= $size && $bytes % $size === 0) return [(int) ($bytes / $size), $unitName];
    }

    return [round($bytes / (1024 ** 3), 2), 'GB'];
};

[$storageAmount, $storageUnit]     = $split((int) $project['storage_quota']);
[$bandwidthAmount, $bandwidthUnit] = $split((int) $project['bandwidth_quota']);
?>

<?php # Up one level, and to the account that owns this. ?>
<nav class="crumbs mb-3 notranslate" translate="no">
    <a href="{{ route('cdn-admin.operator.projects') }}">{{ _l('cdn.operator.projects') }}</a>
    <i class="bi bi-chevron-right"></i>
    <?php if ($owner) : ?>
        <a href="{{ route('cdn-admin.operator.users.show', ['id' => $owner['id']]) }}"><?= e($owner['username'], false) ?></a>
        <i class="bi bi-chevron-right"></i>
    <?php endif ?>
    <span><?= e($project['name'], false) ?></span>

    <?php if ($suspended) : ?><span class="badge text-bg-danger ms-2"><?= _l('cdn.operator.suspended') ?></span><?php endif ?>
</nav>

<?php if ($suspended && ($project['suspend_reason'] ?? '')) : ?>
    <div class="alert alert-danger py-2 small"><?= e((string) $project['suspend_reason'], false) ?></div>
<?php endif ?>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['stored',   'bi-hdd',           File::humanFileSize((int) $project['storage_used'])],
        ['transfer', 'bi-arrow-down-up', File::humanFileSize($month)],
        ['buckets',  'bi-folder2',       number_format(count($buckets))],
        ['files',    'bi-file-earmark',  number_format($counts['files'])],
    ] as [$key, $icon, $value]) : ?>
        <div class="col-6 col-lg-3">
            <div class="stat">
                <div class="label"><i class="bi <?= $icon ?>"></i> <?= _l("cdn.operator.total-$key") ?></div>
                <div class="value notranslate" translate="no"><?= $value ?></div>

                <?php # Each allowance under the number it belongs to. ?>
                <?php if ($key === 'stored') : ?>
                    <div class="hint"><?= (int) $project['storage_quota'] > 0
                        ? _l('cdn.common.of') . ' ' . File::humanFileSize((int) $project['storage_quota'])
                        : _l('cdn.operator.unlimited') ?></div>
                <?php endif ?>

                <?php if ($key === 'transfer') : ?>
                    <div class="hint"><?= (int) $project['bandwidth_quota'] > 0
                        ? _l('cdn.common.of') . ' ' . File::humanFileSize((int) $project['bandwidth_quota'])
                        : _l('cdn.operator.unlimited') ?></div>
                <?php endif ?>
            </div>
        </div>
    <?php endforeach ?>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.projects.buckets') }}</h6>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ _l('cdn.common.bucket') }}</th>
                                <th>{{ _l('cdn.buckets.visibility') }}</th>
                                <th class="text-end">{{ _l('cdn.common.files') }}</th>
                                <th class="text-end">{{ _l('cdn.common.storage') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($buckets as $bucket) : ?>
                                <tr>
                                    <td>
                                        <a class="notranslate" translate="no"
                                           href="{{ route('cdn-admin.operator.files') }}?bucket={{ $bucket['id'] }}">
                                            <?= e($bucket['name'], false) ?>
                                        </a>
                                        <div class="hint mono truncate notranslate" translate="no"><?= $prefix ?>/<?= e($project['slug'], false) ?>/<?= e($bucket['slug'], false) ?>/</div>
                                    </td>
                                    <td><span class="badge text-bg-secondary"><?= _l('cdn.visibility.' . ($bucket['visibility'] ?: 'public')) ?></span></td>
                                    <td class="text-end notranslate" translate="no">{{ number_format((int) $bucket['files_count']) }}</td>
                                    <td class="text-end notranslate" translate="no">{{ File::humanFileSize((int) $bucket['storage_used']) }}</td>
                                    <td class="text-end text-nowrap">
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="{{ route('cdn-admin.operator.files') }}?bucket={{ $bucket['id'] }}">
                                            <i class="bi bi-file-earmark"></i> {{ _l('cdn.buckets.show-files') }}
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach ?>

                            <?php if (!count($buckets)) : ?>
                                <tr><td colspan="5" class="text-center hint py-3">{{ _l('cdn.projects.no-buckets') }}</td></tr>
                            <?php endif ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">{{ _l('cdn.operator.recent-files') }}</h6>
                    <a class="small" href="{{ route('cdn-admin.operator.files') }}?project={{ $project['id'] }}">{{ _l('cdn.operator.all-files') }}</a>
                </div>

                <?php foreach ($files as $file) : ?>
                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                        <span class="mono small truncate notranslate" translate="no"><?= e($file['path'], false) ?></span>
                        <span class="hint text-nowrap ms-3 notranslate" translate="no">{{ File::humanFileSize((int) $file['size']) }}</span>
                    </div>
                <?php endforeach ?>

                <?php if (!count($files)) : ?>
                    <p class="hint mb-0">{{ _l('cdn.common.nothing') }}</p>
                <?php endif ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.operator.quota') }}</h6>

                <form method="POST" action="{{ route('cdn-admin.operator.projects.quota', ['id' => $project['id']]) }}">
                    <?= csrf() ?>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="custom" value="1"
                               id="custom-<?= $project['id'] ?>" <?= $custom ? 'checked' : '' ?>
                               data-toggle-fields="#quota-fields-<?= $project['id'] ?>">
                        <label class="form-check-label small" for="custom-<?= $project['id'] ?>">
                            <b>{{ _l('cdn.operator.custom-quota') }}</b>
                            <div class="hint">
                                <?= _l('cdn.operator.custom-quota-help', [
                                    'user' => e((string) ($owner['username'] ?? '—'), false),
                                ]) ?>
                            </div>
                        </label>
                    </div>

                    <div id="quota-fields-<?= $project['id'] ?>" class="<?= $custom ? '' : 'd-none' ?>">
                        <label class="form-label small mb-1">{{ _l('cdn.operator.storage-quota') }}</label>
                        <div class="input-group input-group-sm mb-2">
                            <input name="storage" class="form-control" value="<?= $storageAmount ?>" inputmode="decimal">
                            <select name="storage-unit" class="form-select" style="max-width: 92px" data-plain>
                                <?php foreach (array_keys($units) as $unit) : ?>
                                    <option value="<?= $unit ?>" <?= $unit === $storageUnit ? 'selected' : '' ?>><?= $unit ?></option>
                                <?php endforeach ?>
                            </select>
                        </div>

                        <label class="form-label small mb-1">{{ _l('cdn.operator.bandwidth-quota') }}</label>
                        <div class="input-group input-group-sm mb-2">
                            <input name="bandwidth" class="form-control" value="<?= $bandwidthAmount ?>" inputmode="decimal">
                            <select name="bandwidth-unit" class="form-select" style="max-width: 92px" data-plain>
                                <?php foreach (array_keys($units) as $unit) : ?>
                                    <option value="<?= $unit ?>" <?= $unit === $bandwidthUnit ? 'selected' : '' ?>><?= $unit ?></option>
                                <?php endforeach ?>
                            </select>
                        </div>
                    </div>

                    <button class="btn btn-sm btn-primary">{{ _l('cdn.common.save') }}</button>
                    <span class="hint small ms-1">{{ _l('cdn.operator.zero-unlimited') }}</span>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h6>{{ _l('cdn.operator.project-actions') }}</h6>

                <?php if ($suspended) : ?>
                    <div class="state-box mb-3">
                        <div class="mb-1"><b><?= _l('cdn.operator.is-suspended-project') ?></b></div>

                        <?php if ($project['suspend_reason'] ?? '') : ?>
                            <div class="small mb-2"><?= _l('cdn.operator.reason') ?>: <?= e((string) $project['suspend_reason'], false) ?></div>
                        <?php endif ?>

                        <div class="hint small mb-2"><?= _l('cdn.operator.restore-question-project') ?></div>

                        <form method="POST" action="{{ route('cdn-admin.operator.projects.status', ['id' => $project['id']]) }}">
                            <?= csrf() ?>
                            <input type="hidden" name="status" value="active">
                            <button class="btn btn-sm btn-success">{{ _l('cdn.operator.restore-project') }}</button>
                        </form>
                    </div>
                <?php else : ?>
                    <form method="POST" action="{{ route('cdn-admin.operator.projects.status', ['id' => $project['id']]) }}" class="mb-3">
                        <?= csrf() ?>
                        <input type="hidden" name="status" value="suspended">

                        <label class="form-label small mb-1">{{ _l('cdn.operator.reason') }}</label>
                        <div class="input-group input-group-sm">
                            <input name="reason" class="form-control" maxlength="255" placeholder="{{ _l('cdn.operator.reason-holder') }}">
                            <button class="btn btn-outline-warning">{{ _l('cdn.operator.suspend') }}</button>
                        </div>
                        <div class="form-text">{{ _l('cdn.operator.suspend-help') }}</div>
                    </form>
                <?php endif ?>

                <form method="POST" action="{{ route('cdn-admin.operator.projects.bandwidth', ['id' => $project['id']]) }}"
                      data-confirm="<?= e(_l('cdn.operator.reset-confirm'), false) ?>">
                    <?= csrf() ?>
                    <button class="btn btn-sm btn-outline-secondary">{{ _l('cdn.operator.reset-bandwidth') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
