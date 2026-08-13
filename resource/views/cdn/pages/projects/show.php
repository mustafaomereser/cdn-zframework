@extends('cdn.main')
@section('title')<?= e($project['name'], false) ?>@endsection
@section('lede')<?= $prefix ?>/<?= e($project['slug'], false) ?>/@endsection

@section('actions')
<a href="{{ route('cdn-admin.buckets.create') }}?project={{ $project['id'] }}" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg"></i> {{ _l('cdn.buckets.add') }}
</a>
@endsection

@section('body')
<?php $suspended = ($project['status'] ?? 'active') !== 'active'; ?>

<?php if ($suspended) : ?>
    <?php # The owner sees this rather than a project that simply stopped
          # working. Somebody whose urls answer 403 with no explanation writes
          # to support; somebody who reads this knows who to ask and what about. ?>
    <div class="alert alert-danger">
        <b><?= _l('cdn.projects.suspended-title') ?></b>
        <div class="small mt-1"><?= _l('cdn.projects.suspended-text') ?></div>
    </div>
<?php endif ?>

<div class="row g-3 mb-3">
    <div class="col-md-4 col-6">
        <div class="stat">
            <div class="label">{{ _l('cdn.common.storage') }}</div>
            <div class="value notranslate" translate="no">{{ File::humanFileSize($project['storage_used']) }}</div>
            <div class="hint"><?= $usage['quota'] > 0
                ? _l('cdn.common.of') . ' ' . File::humanFileSize($usage['quota']) . ' — ' . _l('cdn.projects.account-wide')
                : _l('cdn.operator.unlimited') ?></div>
        </div>
    </div>

    <div class="col-md-4 col-6">
        <div class="stat">
            <div class="label">{{ _l('cdn.common.transfer') }}</div>
            <div class="value notranslate" translate="no">{{ File::humanFileSize($month) }}</div>
            <div class="hint notranslate" translate="no"><?= date('Y-m') ?></div>
        </div>
    </div>

    <div class="col-md-4 col-12">
        <div class="stat">
            <div class="label">{{ _l('cdn.projects.buckets') }}</div>
            <div class="value notranslate" translate="no">{{ number_format(count($buckets)) }}</div>
            <div class="hint">{{ number_format($files) }} {{ _l('cdn.common.files') }}</div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h6>{{ _l('cdn.projects.buckets') }}</h6>
        <p class="hint">{{ _l('cdn.projects.buckets-lede') }}</p>

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
                                <a class="notranslate" translate="no" href="{{ route('cdn-admin.files') }}?bucket={{ $bucket['id'] }}">
                                    <?= e($bucket['name'], false) ?>
                                </a>
                                <div class="hint mono truncate notranslate" translate="no"><?= $prefix ?>/<?= e($project['slug'], false) ?>/<?= e($bucket['slug'], false) ?>/</div>
                            </td>

                            <td><span class="badge text-bg-secondary"><?= _l('cdn.visibility.' . ($bucket['visibility'] ?: 'public')) ?></span></td>

                            <td class="text-end notranslate" translate="no">{{ number_format((int) $bucket['files_count']) }}</td>
                            <td class="text-end notranslate" translate="no">{{ File::humanFileSize((int) $bucket['storage_used']) }}</td>

                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('cdn-admin.buckets.edit', ['id' => $bucket['id']]) }}">
                                    {{ _l('cdn.common.edit') }}
                                </a>
                            </td>
                        </tr>
                    <?php endforeach ?>

                    <?php if (!count($buckets)) : ?>
                        <tr><td colspan="5" class="text-center hint py-4">{{ _l('cdn.projects.no-buckets') }}</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body">
                <h6>{{ _l('cdn.projects.rename') }}</h6>
                <p class="hint">{{ _l('cdn.projects.rename-lede') }}</p>

                <?php # The main project's name is the account's namespace: it is
                      # what every other project's url name is derived from, so
                      # it is fixed in the same way its slug is. ?>
                <?php if ($only) : ?>
                    <div class="hint small">{{ _l('cdn.projects.main-fixed') }}</div>
                <?php else : ?>
                    <form action="{{ route('cdn-admin.projects.save', ['id' => $project['id']]) }}" method="POST">
                        <?= csrf() ?>
                        <div class="input-group input-group-sm" style="max-width: 420px">
                            <input name="name" class="form-control" value="{{ $project['name'] }}" required>
                            <button class="btn btn-outline-secondary">{{ _l('cdn.common.rename') }}</button>
                        </div>
                    </form>
                <?php endif ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card danger-card">
            <div class="card-body">
                <h6>{{ _l('cdn.projects.delete') }}</h6>
                <p class="hint">{{ _l('cdn.projects.delete-lede') }}</p>

                <?php # The last one cannot go: the panel is built on there being
                      # a project, and an account with none would be an account
                      # that cannot upload anything. ?>
                <?php if ($only) : ?>
                    <div class="hint small">{{ _l('cdn.projects.delete-main') }}</div>
                <?php else : ?>
                    <form action="{{ route('cdn-admin.projects.delete', ['id' => $project['id']]) }}" method="POST"
                          data-confirm="<?= e(_l('cdn.projects.delete-confirm', ['name' => $project['name'], 'files' => $files]), false) ?>">
                        <?= csrf() ?>
                        <button class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-trash"></i> {{ _l('cdn.projects.delete-action', ['files' => number_format($files)]) }}
                        </button>
                    </form>
                <?php endif ?>
            </div>
        </div>
    </div>
</div>
@endsection
