@extends('cdn.main')
@section('title')<?= _l('cdn.projects.title') ?>@endsection
@section('lede')<?= _l('cdn.projects.lede') ?>@endsection

@section('actions')
<a href="{{ route('cdn-admin.projects.create') }}" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg"></i> {{ _l('cdn.projects.add') }}
</a>
@endsection

@section('body')
<?php
/**
 * One card per project, and each of them says the four things somebody opens
 * this page to find out: where its urls start, what is in it, what it is using,
 * and what can be done to it.
 *
 * It used to be a link-wrapped card with two counts on it - which meant every
 * action had to be found by opening the project first, and the whole card being
 * one link left nowhere to put them.
 */
$share = $usage['quota'] > 0 ? min(100, round($usage['used'] / $usage['quota'] * 100)) : 0;
?>

<?php # What the account is using across all of them, above the per-project
      # cards - the allowance is the account's, so it belongs above them rather
      # than repeated on each. ?>
<div class="card mb-3">
    <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <div class="label">{{ _l('cdn.settings.allowance') }}</div>
                <div class="mt-1">
                    <span class="notranslate" translate="no"><b>{{ File::humanFileSize($usage['used']) }}</b></span>
                    <?php if ($usage['quota'] > 0) : ?>
                        <span class="hint">
                            {{ _l('cdn.common.of') }}
                            <span class="notranslate" translate="no">{{ File::humanFileSize($usage['quota']) }}</span>
                            · <?= _l('cdn.projects.account-wide') ?>
                        </span>
                    <?php else : ?>
                        <span class="hint">{{ _l('cdn.operator.unlimited') }}</span>
                    <?php endif ?>
                </div>
            </div>

            <div class="text-end">
                <div class="label">{{ _l('cdn.common.transfer') }} <span class="notranslate" translate="no"><?= date('Y-m') ?></span></div>
                <div class="mt-1 notranslate" translate="no">
                    <b>{{ File::humanFileSize($usage['bandwidth']) }}</b>
                    <?php if ($usage['bandwidth-quota'] > 0) : ?>
                        <span class="hint">{{ _l('cdn.common.of') }} {{ File::humanFileSize($usage['bandwidth-quota']) }}</span>
                    <?php endif ?>
                </div>
            </div>
        </div>

        <?php if ($usage['quota'] > 0) : ?>
            <div class="quota <?= $share >= 95 ? 'full' : ($share >= 80 ? 'warn' : '') ?> mt-2">
                <div style="width: <?= $share ?>%"></div>
            </div>
        <?php endif ?>

        <div class="hint small mt-2">{{ _l('cdn.projects.quota-note') }}</div>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($rows as $row) :
        $suspended = ($row['status'] ?? 'active') !== 'active';
        $rowShare  = $row['quota'] > 0 ? min(100, round($row['used'] / $row['quota'] * 100)) : 0;
        $base      = $prefix . '/' . $row['slug'] . '/';
    ?>
        <div class="col-xl-6">
            <div class="card project-row <?= $suspended ? 'suspended' : '' ?> <?= $row['selected'] ? 'selected' : '' ?>">
                <div class="card-body">

                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div class="min-w-0">
                            <h6 class="mb-1 truncate notranslate" translate="no">
                                <a href="{{ route('cdn-admin.projects.show', ['id' => $row['id']]) }}"><?= e($row['name'], false) ?></a>
                            </h6>

                            <div class="d-flex align-items-center gap-1">
                                <span class="mono hint truncate notranslate" translate="no"><?= $base ?></span>
                                <button class="btn btn-sm btn-link p-0 ms-1" data-copy="<?= host() . $base ?>"
                                        title="{{ _l('cdn.common.copy') }}"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </div>

                        <div class="text-nowrap">
                            <?php if ($row['main']) : ?><span class="badge text-bg-light border"><?= _l('cdn.projects.main') ?></span><?php endif ?>
                            <?php if ($suspended) : ?><span class="badge text-bg-danger"><?= _l('cdn.operator.suspended') ?></span><?php endif ?>
                            <?php if ($row['selected']) : ?><span class="badge text-bg-primary"><?= _l('cdn.projects.selected') ?></span><?php endif ?>
                        </div>
                    </div>

                    <?php if ($suspended && ($row['suspend_reason'] ?? '')) : ?>
                        <div class="small text-danger mb-2"><?= e((string) $row['suspend_reason'], false) ?></div>
                    <?php endif ?>

                    <div class="counts mb-2">
                        <span><b class="notranslate" translate="no"><?= number_format(count($row['buckets'])) ?></b> <?= _l('cdn.projects.buckets') ?></span>
                        <span><b class="notranslate" translate="no"><?= number_format($row['files']) ?></b> <?= _l('cdn.common.files') ?></span>
                        <span class="notranslate" translate="no"><b>{{ File::humanFileSize($row['used']) }}</b></span>
                        <span class="notranslate" translate="no"><i class="bi bi-arrow-down-up"></i> {{ File::humanFileSize($row['month']) }}</span>
                    </div>

                    <?php if ($row['quota'] > 0) : ?>
                        <div class="quota <?= $rowShare >= 95 ? 'full' : ($rowShare >= 80 ? 'warn' : '') ?>">
                            <div style="width: <?= $rowShare ?>%"></div>
                        </div>
                        <div class="hint small mt-1">
                            <?= $rowShare ?>% {{ _l('cdn.common.of') }}
                            <span class="notranslate" translate="no">{{ File::humanFileSize($row['quota']) }}</span>
                            · <?= _l($row['own-quota'] ? 'cdn.projects.own-quota' : 'cdn.projects.account-wide') ?>
                        </div>
                    <?php endif ?>

                    <?php # The buckets themselves, because "3 buckets" is not an
                          # answer to "which buckets". ?>
                    <?php if (count($row['buckets'])) : ?>
                        <div class="bucket-chips mt-3">
                            <?php foreach (array_slice($row['buckets'], 0, 6) as $bucket) : ?>
                                <a class="chip notranslate" translate="no"
                                   href="{{ route('cdn-admin.files') }}?bucket={{ $bucket['id'] }}">
                                    <i class="bi bi-folder2"></i> <?= e($bucket['name'], false) ?>
                                    <span class="hint"><?= number_format((int) $bucket['files_count']) ?></span>
                                </a>
                            <?php endforeach ?>

                            <?php if (count($row['buckets']) > 6) : ?>
                                <a class="chip" href="{{ route('cdn-admin.projects.show', ['id' => $row['id']]) }}">
                                    +<?= count($row['buckets']) - 6 ?>
                                </a>
                            <?php endif ?>
                        </div>
                    <?php endif ?>

                    <div class="d-flex flex-wrap gap-1 mt-3">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('cdn-admin.projects.show', ['id' => $row['id']]) }}">
                            {{ _l('cdn.common.open') }}
                        </a>

                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('cdn-admin.buckets.create') }}?project={{ $row['id'] }}">
                            <i class="bi bi-plus-lg"></i> {{ _l('cdn.buckets.add') }}
                        </a>

                        <?php # The switcher is in the sidebar and this is where
                              # somebody is when they decide which one to work
                              # in. Same route, so there is one place that
                              # decides what "selected" means. ?>
                        <?php if (!$row['selected']) : ?>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('cdn-admin.projects.switch') }}?id={{ $row['id'] }}">
                                <i class="bi bi-check2-square"></i> {{ _l('cdn.projects.work-in') }}
                            </a>
                        <?php endif ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach ?>
</div>
@endsection
