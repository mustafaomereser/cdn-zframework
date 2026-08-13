@extends('cdn.main')
@section('title')<?= _l('cdn.operator.projects') ?>@endsection
@section('lede')<?= _l('cdn.operator.projects-lede') ?>@endsection

@section('body')
<?php include(BASE_PATH . '/resource/views/cdn/partials/operator-nav.php') ?>

<?php
/**
 * A quota is edited as a number and a unit rather than as bytes.
 *
 * Nobody types 214748364800, and everybody who has had to has typed it wrong
 * once. The value shown back is the largest unit the stored number divides
 * into cleanly, so 5 GB comes back as 5 GB and not as 5120 MB.
 *
 * The unit select carries data-plain: select2 replaces a select with a span,
 * which .input-group does not recognise as one of its children - so the styled
 * dropdown drops onto its own line under the number instead of sitting against
 * it. Inside an input-group the native control is the one that works.
 */
$units = ['B' => 1, 'KB' => 1024, 'MB' => 1024 ** 2, 'GB' => 1024 ** 3, 'TB' => 1024 ** 4];

$split = function (int $bytes) use ($units): array {
    if ($bytes <= 0) return [0, 'GB'];

    foreach (array_reverse($units, true) as $name => $size) {
        if ($bytes >= $size && $bytes % $size === 0) return [(int) ($bytes / $size), $name];
    }

    return [round($bytes / (1024 ** 3), 2), 'GB'];
};
?>

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-center mb-0" method="GET">
            <div class="col-sm-6 col-lg-4">
                <input name="q" class="form-control form-control-sm" value="{{ request('q') }}"
                       placeholder="{{ _l('cdn.operator.search-projects') }}">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-secondary">{{ _l('cdn.common.search') }}</button>
            </div>
            <div class="col text-end hint small">
                {{ _l('cdn.operator.zero-unlimited') }}
            </div>
        </form>
    </div>
</div>

<?php
$split = function (int $bytes) use ($units): array {
    if ($bytes <= 0) return [0, 'GB'];

    foreach (array_reverse($units, true) as $unitName => $size) {
        if ($bytes >= $size && $bytes % $size === 0) return [(int) ($bytes / $size), $unitName];
    }

    return [round($bytes / (1024 ** 3), 2), 'GB'];
};
?>

<?php foreach ($rows['items'] as $project) :
    $suspended = ($project['status'] ?? 'active') !== 'active';
    $custom    = ($project['quota_mode'] ?? 'account') === 'custom';

    [$storageAmount, $storageUnit]     = $split((int) $project['storage_quota']);
    [$bandwidthAmount, $bandwidthUnit] = $split((int) $project['bandwidth_quota']);

    [$storageAmount, $storageUnit]     = $split((int) $project['storage_quota']);
    [$bandwidthAmount, $bandwidthUnit] = $split((int) $project['bandwidth_quota']);

    $usedShare = $project['storage_quota'] > 0
        ? min(100, round($project['storage_used'] / $project['storage_quota'] * 100))
        : 0;

    $thisMonth = ($project['bandwidth_period'] ?? null) === date('Y-m') ? (int) $project['bandwidth_used'] : 0;
?>
    <div class="card mb-3">
        <div class="card-body">

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div class="notranslate" translate="no">
                    <h6 class="mb-1">
                        <?= e($project['name'], false) ?>
                        <?php if ($suspended) : ?><span class="badge text-bg-danger ms-1"><?= _l('cdn.operator.suspended') ?></span><?php endif ?>
                    </h6>
                    <?php if ($suspended && ($project['suspend_reason'] ?? '')) : ?>
                        <div class="small text-danger mb-1"><?= e((string) $project['suspend_reason'], false) ?></div>
                    <?php endif ?>

                    <div class="hint mono">
                        <?= rtrim((string) config('cdn.delivery.url-prefix'), '/') ?>/<?= e($project['slug'], false) ?>/…
                        <?php if ($project['owner']) : ?>
                            · <?= e($project['owner']['username'], false) ?>
                        <?php endif ?>
                    </div>
                </div>

                <form class="d-flex gap-2 align-items-center" method="POST"
                      action="{{ route('cdn-admin.operator.projects.status', ['id' => $project['id']]) }}">
                    <?= csrf() ?>
                    <input type="hidden" name="status" value="<?= $suspended ? 'active' : 'suspended' ?>">

                    <?php # Typed here rather than on a page of its own: the
                          # moment somebody suspends something is the only moment
                          # they know why, and asking later gets nothing. ?>
                    <?php if (!$suspended) : ?>
                        <input name="reason" class="form-control form-control-sm" style="min-width: 220px"
                               placeholder="{{ _l('cdn.operator.reason-holder') }}" maxlength="255">
                    <?php endif ?>

                    <button class="btn btn-sm btn-outline-<?= $suspended ? 'success' : 'warning' ?> text-nowrap">
                        <?= _l($suspended ? 'cdn.operator.restore' : 'cdn.operator.suspend') ?>
                    </button>
                </form>
            </div>

            <div class="row g-3">
                <div class="col-lg-7">
                    <form method="POST" action="{{ route('cdn-admin.operator.projects.quota', ['id' => $project['id']]) }}">
                        <?= csrf() ?>

                        <?php # Off, this project follows the account and is
                              # rewritten whenever the account changes. On, it
                              # keeps its own numbers and an account-level edit
                              # leaves it alone. ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="custom" value="1"
                                   id="custom-<?= $project['id'] ?>" <?= $custom ? 'checked' : '' ?>
                                   data-toggle-fields="#quota-fields-<?= $project['id'] ?>">
                            <label class="form-check-label small" for="custom-<?= $project['id'] ?>">
                                <b>{{ _l('cdn.operator.custom-quota') }}</b>
                                <div class="hint">
                                    <?= _l('cdn.operator.custom-quota-help', [
                                        'user' => e((string) ($project['owner']['username'] ?? '—'), false),
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

                <div class="col-lg-5">
                    <div class="d-flex justify-content-between small">
                        <span class="hint">{{ _l('cdn.common.storage') }}</span>
                        <span class="notranslate" translate="no">
                            {{ File::humanFileSize($project['storage_used']) }}
                            <?php if ($project['storage_quota'] > 0) : ?>
                                <span class="hint">{{ _l('cdn.common.of') }} {{ File::humanFileSize($project['storage_quota']) }}</span>
                            <?php endif ?>
                        </span>
                    </div>

                    <?php if ($project['storage_quota'] > 0) : ?>
                        <div class="quota <?= $usedShare >= 95 ? 'full' : ($usedShare >= 80 ? 'warn' : '') ?> mb-2">
                            <div style="width: <?= $usedShare ?>%"></div>
                        </div>
                    <?php endif ?>

                    <div class="d-flex justify-content-between small mt-2">
                        <span class="hint">{{ _l('cdn.operator.transfer') }} <span class="notranslate" translate="no"><?= date('Y-m') ?></span></span>
                        <span class="notranslate" translate="no">
                            {{ File::humanFileSize($thisMonth) }}
                            <?php if ($project['bandwidth_quota'] > 0) : ?>
                                <span class="hint">{{ _l('cdn.common.of') }} {{ File::humanFileSize($project['bandwidth_quota']) }}</span>
                            <?php endif ?>
                        </span>
                    </div>

                    <form class="mt-2" method="POST"
                          action="{{ route('cdn-admin.operator.projects.bandwidth', ['id' => $project['id']]) }}"
                          data-confirm="<?= e(_l('cdn.operator.reset-confirm'), false) ?>">
                        <?= csrf() ?>
                        <button class="btn btn-sm btn-outline-secondary">{{ _l('cdn.operator.reset-bandwidth') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endforeach ?>

<?php if (!count($rows['items'])) : ?>
    <div class="card"><div class="card-body text-center hint py-4">{{ _l('cdn.common.nothing') }}</div></div>
<?php endif ?>

<div class="mt-3"><?= $rows['links']('cdn.pagination') ?></div>
@endsection
