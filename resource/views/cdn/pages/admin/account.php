@extends('cdn.main')
@section('title')<?= e($user['username'], false) ?>@endsection
@section('lede')<?= e($user['email'], false) ?>@endsection

@section('body')
<?php include(BASE_PATH . '/resource/views/cdn/partials/operator-nav.php') ?>

<?php
$suspended = ($user['status'] ?? 'active') === 'suspended';
$self      = (int) $user['id'] === (int) zFramework\Core\Facades\Auth::id();

$split = function (int $bytes) use ($units): array {
    if ($bytes <= 0) return [0, 'GB'];

    foreach (array_reverse($units, true) as $unitName => $size) {
        if ($bytes >= $size && $bytes % $size === 0) return [(int) ($bytes / $size), $unitName];
    }

    return [round($bytes / (1024 ** 3), 2), 'GB'];
};

[$storageAmount, $storageUnit]     = $split((int) $user['quota']);
[$bandwidthAmount, $bandwidthUnit] = $split((int) ($user['bandwidth-quota'] ?? 0));

$share     = $user['quota'] > 0 ? min(100, round($user['storage'] / $user['quota'] * 100)) : 0;
$sentQuota = (int) ($user['bandwidth-quota'] ?? 0);
$sentShare = $sentQuota > 0 ? min(100, round($user['bandwidth'] / $sentQuota * 100)) : 0;
?>

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="{{ route('cdn-admin.operator.users') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> {{ _l('cdn.operator.users') }}
    </a>

    <?php if ($user['operator']) : ?><span class="badge text-bg-primary">operator</span><?php endif ?>
    <?php if ($suspended) : ?><span class="badge text-bg-danger"><?= _l('cdn.operator.suspended') ?></span><?php endif ?>
</div>

<?php if ($suspended && ($user['suspend_reason'] ?? '')) : ?>
    <div class="alert alert-danger py-2 small"><?= e((string) $user['suspend_reason'], false) ?></div>
<?php endif ?>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['stored',   'bi-hdd',           File::humanFileSize($user['storage'])],
        ['transfer', 'bi-arrow-down-up', File::humanFileSize($user['bandwidth'])],
        ['projects', 'bi-boxes',         number_format(count($owned))],
        ['buckets',  'bi-folder2',       number_format(count($buckets))],
    ] as [$key, $icon, $value]) : ?>
        <div class="col-6 col-lg-3">
            <div class="stat">
                <div class="label"><i class="bi <?= $icon ?>"></i> <?= _l("cdn.operator.total-$key") ?></div>
                <div class="value notranslate" translate="no"><?= $value ?></div>

                <?php # Both allowances, each under its own number. ?>
                <?php if ($key === 'stored') : ?>
                    <?php if ($user['quota'] > 0) : ?>
                        <div class="quota <?= $share >= 95 ? 'full' : ($share >= 80 ? 'warn' : '') ?>">
                            <div style="width: <?= $share ?>%"></div>
                        </div>
                        <div class="hint mt-1"><?= _l('cdn.common.of') ?> <span class="notranslate" translate="no"><?= File::humanFileSize((int) $user['quota']) ?></span></div>
                    <?php else : ?>
                        <div class="hint"><?= _l('cdn.operator.unlimited') ?></div>
                    <?php endif ?>
                <?php endif ?>

                <?php if ($key === 'transfer') : ?>
                    <?php if ($sentQuota > 0) : ?>
                        <div class="quota <?= $sentShare >= 95 ? 'full' : ($sentShare >= 80 ? 'warn' : '') ?>">
                            <div style="width: <?= $sentShare ?>%"></div>
                        </div>
                        <div class="hint mt-1">
                            <?= _l('cdn.common.of') ?> <span class="notranslate" translate="no"><?= File::humanFileSize($sentQuota) ?></span>
                            · <span class="notranslate" translate="no"><?= date('Y-m') ?></span>
                        </div>
                    <?php else : ?>
                        <div class="hint"><?= _l('cdn.operator.unlimited') ?></div>
                    <?php endif ?>
                <?php endif ?>
            </div>
        </div>
    <?php endforeach ?>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.operator.projects') }}</h6>
                <p class="hint">{{ _l('cdn.operator.account-projects-lede') }}</p>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ _l('cdn.common.project') }}</th>
                                <th class="text-end">{{ _l('cdn.projects.buckets') }}</th>
                                <th class="text-end">{{ _l('cdn.common.files') }}</th>
                                <th class="text-end">{{ _l('cdn.common.storage') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php # $owned, not $projects: the layout owns that name and its own
                                  # assignment runs before this section does. ?>
                            <?php foreach ($owned as $project) : ?>
                                <tr>
                                    <td>
                                        <a href="{{ route('cdn-admin.operator.projects.show', ['id' => $project['id']]) }}"
                                           class="notranslate" translate="no"><?= e($project['name'], false) ?></a>
                                        <?php if (($project['status'] ?? 'active') !== 'active') : ?>
                                            <span class="badge text-bg-danger ms-1"><?= _l('cdn.operator.suspended') ?></span>
                                        <?php endif ?>
                                        <div class="hint mono truncate notranslate" translate="no"><?= $prefix ?>/<?= e($project['slug'], false) ?>/</div>
                                    </td>
                                    <td class="text-end notranslate" translate="no"><?= number_format($project['buckets']) ?></td>
                                    <td class="text-end notranslate" translate="no"><?= number_format($project['files']) ?></td>
                                    <td class="text-end notranslate" translate="no">{{ File::humanFileSize((int) $project['storage_used']) }}</td>
                                    <td class="text-end text-nowrap">
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="{{ route('cdn-admin.operator.files') }}?project={{ $project['id'] }}">
                                            <i class="bi bi-file-earmark"></i> {{ _l('cdn.buckets.show-files') }}
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach ?>

                            <?php if (!count($owned)) : ?>
                                <tr><td colspan="5" class="text-center hint py-3">{{ _l('cdn.common.nothing') }}</td></tr>
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
                    <a class="small" href="{{ route('cdn-admin.operator.files') }}?q=">{{ _l('cdn.operator.all-files') }}</a>
                </div>

                <?php foreach ($files as $file) : ?>
                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                        <span class="mono small truncate notranslate" translate="no"><?= e($file['path'], false) ?></span>
                        <span class="hint text-nowrap ms-3 notranslate" translate="no">
                            {{ File::humanFileSize((int) $file['size']) }} · {{ number_format((int) $file['downloads']) }}
                        </span>
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
                <p class="hint">{{ _l('cdn.operator.quota-lede') }}</p>

                <form method="POST" action="{{ route('cdn-admin.operator.users.quota', ['id' => $user['id']]) }}">
                    <?= csrf() ?>

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

                    <button class="btn btn-sm btn-primary">{{ _l('cdn.common.save') }}</button>
                    <span class="hint small ms-1">{{ _l('cdn.operator.zero-unlimited') }}</span>
                </form>
            </div>
        </div>

        <?php if (!$self) : ?>
            <div class="card danger-card">
                <div class="card-body">
                    <h6>{{ _l('cdn.operator.account-actions') }}</h6>

                    <?php if ($suspended) : ?>
                        <?php # What is true now, then what can be done about it.
                              # A lone "Restore" button says nothing about the
                              # state it would be leaving. ?>
                        <div class="state-box mb-3">
                            <div class="mb-1"><b><?= _l('cdn.operator.is-suspended-user') ?></b></div>

                            <?php if ($user['suspend_reason'] ?? '') : ?>
                                <div class="small mb-2"><?= _l('cdn.operator.reason') ?>: <?= e((string) $user['suspend_reason'], false) ?></div>
                            <?php endif ?>

                            <div class="hint small mb-2"><?= _l('cdn.operator.restore-question-user') ?></div>

                            <form method="POST" action="{{ route('cdn-admin.operator.users.status', ['id' => $user['id']]) }}">
                                <?= csrf() ?>
                                <input type="hidden" name="status" value="active">
                                <button class="btn btn-sm btn-success">{{ _l('cdn.operator.restore-user') }}</button>
                            </form>
                        </div>
                    <?php else : ?>
                        <form method="POST" action="{{ route('cdn-admin.operator.users.status', ['id' => $user['id']]) }}" class="mb-3">
                            <?= csrf() ?>
                            <input type="hidden" name="status" value="suspended">

                            <label class="form-label small mb-1">{{ _l('cdn.operator.reason') }}</label>
                            <div class="input-group input-group-sm">
                                <input name="reason" class="form-control" maxlength="255" placeholder="{{ _l('cdn.operator.reason-holder') }}">
                                <button class="btn btn-outline-warning">{{ _l('cdn.operator.suspend') }}</button>
                            </div>
                            <div class="form-text">{{ _l('cdn.operator.reason-help') }}</div>
                        </form>
                    <?php endif ?>

                    <?php if (!$locked) : ?>
                        <form method="POST" action="{{ route('cdn-admin.operator.users.operator', ['id' => $user['id']]) }}" class="mb-3">
                            <?= csrf() ?>
                            <input type="hidden" name="operator" value="<?= $user['operator'] ? '0' : '1' ?>">
                            <button class="btn btn-sm btn-outline-secondary">
                                <?= _l($user['operator'] ? 'cdn.operator.demote' : 'cdn.operator.promote') ?>
                            </button>
                        </form>
                    <?php endif ?>

                    <form method="POST" action="{{ route('cdn-admin.operator.users.delete', ['id' => $user['id']]) }}"
                          data-confirm="<?= e(_l('cdn.operator.delete-confirm', ['user' => $user['username']]), false) ?>">
                        <?= csrf() ?>
                        <button class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-trash"></i> {{ _l('cdn.operator.delete-account') }}
                        </button>
                    </form>
                </div>
            </div>
        <?php endif ?>
    </div>
</div>
@endsection
