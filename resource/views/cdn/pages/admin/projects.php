@extends('cdn.main')
@section('title')<?= _l('cdn.operator.projects') ?>@endsection
@section('lede')<?= _l('cdn.operator.projects-lede') ?>@endsection

@section('body')
<?php include(BASE_PATH . '/resource/views/cdn/partials/operator-nav.php') ?>

<?php
/**
 * A list, and nothing else.
 *
 * Every project used to carry its own quota form, suspend field and reset
 * button on this page - four forms per row, on a page whose job is to let
 * somebody find a project. Editing happens on the project's own page, which
 * exists and has room for it.
 */
$prefix = rtrim((string) config('cdn.delivery.url-prefix'), '/');
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
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>{{ _l('cdn.common.project') }}</th>
                    <th>{{ _l('cdn.operator.account') }}</th>
                    <th class="text-end">{{ _l('cdn.common.storage') }}</th>
                    <th class="text-end">{{ _l('cdn.operator.transfer') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows['items'] as $project) :
                    $suspended = ($project['status'] ?? 'active') !== 'active';
                    $custom    = ($project['quota_mode'] ?? 'account') === 'custom';
                    $month     = ($project['bandwidth_period'] ?? null) === date('Y-m') ? (int) $project['bandwidth_used'] : 0;
                    $share     = $project['storage_quota'] > 0
                        ? min(100, round($project['storage_used'] / $project['storage_quota'] * 100))
                        : 0;
                ?>
                    <tr>
                        <td>
                            <a class="fw-medium notranslate" translate="no"
                               href="{{ route('cdn-admin.operator.projects.show', ['id' => $project['id']]) }}">
                                <?= e($project['name'], false) ?>
                            </a>

                            <?php if ($suspended) : ?>
                                <span class="badge text-bg-danger ms-1"><?= _l('cdn.operator.suspended') ?></span>
                            <?php endif ?>

                            <div class="hint mono truncate notranslate" translate="no"><?= $prefix ?>/<?= e($project['slug'], false) ?>/</div>

                            <?php if ($suspended && ($project['suspend_reason'] ?? '')) : ?>
                                <div class="small text-danger truncate"><?= e((string) $project['suspend_reason'], false) ?></div>
                            <?php endif ?>
                        </td>

                        <td class="small notranslate" translate="no">
                            <?php if ($project['owner']) : ?>
                                <a href="{{ route('cdn-admin.operator.users.show', ['id' => $project['owner']['id']]) }}">
                                    <?= e($project['owner']['username'], false) ?>
                                </a>
                            <?php else : ?>—<?php endif ?>
                        </td>

                        <td class="text-end small notranslate" translate="no">
                            {{ File::humanFileSize((int) $project['storage_used']) }}

                            <?php if ($project['storage_quota'] > 0) : ?>
                                <div class="hint">
                                    <?= $share ?>% {{ _l('cdn.common.of') }} {{ File::humanFileSize((int) $project['storage_quota']) }}
                                </div>
                            <?php endif ?>

                            <?php if ($custom) : ?>
                                <div class="hint"><?= _l('cdn.projects.own-quota') ?></div>
                            <?php endif ?>
                        </td>

                        <td class="text-end small notranslate" translate="no">
                            {{ File::humanFileSize($month) }}
                            <?php # The same shape as the storage cell. A number
                                  # with nothing to measure it against reads as
                                  # though there were no transfer limit. ?>
                            <?php if ((int) $project['bandwidth_quota'] > 0) : ?>
                                <div class="hint">
                                    {{ _l('cdn.common.of') }} {{ File::humanFileSize((int) $project['bandwidth_quota']) }}
                                </div>
                            <?php else : ?>
                                <div class="hint">{{ _l('cdn.operator.unlimited') }}</div>
                            <?php endif ?>
                        </td>

                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('cdn-admin.operator.files') }}?project={{ $project['id'] }}">
                                <i class="bi bi-file-earmark"></i> {{ _l('cdn.buckets.show-files') }}
                            </a>

                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('cdn-admin.operator.projects.show', ['id' => $project['id']]) }}">
                                {{ _l('cdn.common.edit') }}
                            </a>
                        </td>
                    </tr>
                <?php endforeach ?>

                <?php if (!count($rows['items'])) : ?>
                    <tr><td colspan="5" class="text-center hint py-4">{{ _l('cdn.common.nothing') }}</td></tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3"><?= $rows['links']('cdn.pagination') ?></div>
@endsection
