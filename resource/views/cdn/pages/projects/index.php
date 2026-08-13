@extends('cdn.main')
@section('title')<?= _l('cdn.projects.title') ?>@endsection
@section('lede')<?= _l('cdn.projects.lede') ?>@endsection

@section('actions')
<a href="{{ route('cdn-admin.projects.create') }}" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg"></i> {{ _l('cdn.projects.add') }}
</a>
@endsection

@section('body')
<div class="row g-3">
    <?php foreach ($rows as $row) :
        $suspended = ($row['status'] ?? 'active') !== 'active';
        $share     = $row['quota'] > 0 ? min(100, round($row['used'] / $row['quota'] * 100)) : 0;
    ?>
        <div class="col-lg-6">
            <a class="project-card <?= $suspended ? 'suspended' : '' ?>" href="{{ route('cdn-admin.projects.show', ['id' => $row['id']]) }}">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="min-w-0">
                        <div class="name truncate notranslate" translate="no"><?= e($row['name'], false) ?></div>
                        <div class="hint mono truncate notranslate" translate="no"><?= $prefix ?>/<?= e($row['slug'], false) ?>/</div>
                    </div>

                    <?php if ($suspended) : ?>
                        <span class="badge text-bg-danger"><?= _l('cdn.operator.suspended') ?></span>
                    <?php endif ?>
                </div>

                <div class="counts">
                    <span><b class="notranslate" translate="no"><?= number_format($row['buckets']) ?></b> <?= _l('cdn.projects.buckets') ?></span>
                    <span><b class="notranslate" translate="no"><?= number_format($row['files']) ?></b> <?= _l('cdn.common.files') ?></span>
                    <span class="notranslate" translate="no"><b>{{ File::humanFileSize($row['used']) }}</b></span>
                </div>

                <?php if ($row['quota'] > 0) : ?>
                    <div class="quota <?= $share >= 95 ? 'full' : ($share >= 80 ? 'warn' : '') ?>">
                        <div style="width: <?= $share ?>%"></div>
                    </div>
                <?php endif ?>
            </a>
        </div>
    <?php endforeach ?>
</div>

<p class="hint mt-3 mb-0">{{ _l('cdn.projects.quota-note') }}</p>
@endsection
