@extends('cdn.main')
@section('title')<?= _l('cdn.operator.files') ?>@endsection
@section('lede')<?= _l('cdn.operator.files-lede') ?>@endsection

@section('body')
<?php include(BASE_PATH . '/resource/views/cdn/partials/operator-nav.php') ?>

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-center mb-0" method="GET">
            <?php # The bucket and project filters arrive as query parameters
                  # from the pages that link here, so they are carried rather
                  # than offered as selects over every bucket in the install. ?>
            <?php foreach (['bucket', 'project'] as $carry) : ?>
                <?php if (request($carry)) : ?>
                    <input type="hidden" name="<?= $carry ?>" value="<?= (int) request($carry) ?>">
                <?php endif ?>
            <?php endforeach ?>

            <div class="col-sm-6 col-lg-4">
                <input name="q" class="form-control form-control-sm" value="{{ request('q') }}"
                       placeholder="{{ _l('cdn.files.search') }}">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-secondary">{{ _l('cdn.common.search') }}</button>
            </div>

            <?php if (request('bucket') || request('project')) : ?>
                <div class="col-auto">
                    <a class="btn btn-sm btn-link" href="{{ route('cdn-admin.operator.files') }}">{{ _l('cdn.operator.clear-filter') }}</a>
                </div>
            <?php endif ?>
        </form>
    </div>
</div>

<?php
# The move menu is the buckets of whichever account the listing is filtered to.
# Across accounts there is no one right answer, and moving one customer's file
# into another's namespace is refused by the endpoint anyway.
$bulkAction  = route('cdn-admin.operator.files.bulk');
$bulkTargets = $moveTargets;

include(BASE_PATH . '/resource/views/cdn/partials/bulk-files.php');
?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 34px">
                        <input class="form-check-input" type="checkbox" id="pick-all" title="{{ _l('cdn.files.select-all') }}">
                    </th>
                    <th>{{ _l('cdn.files.path') }}</th>
                    <th>{{ _l('cdn.common.bucket') }}</th>
                    <th>{{ _l('cdn.common.project') }}</th>
                    <th class="text-end">{{ _l('cdn.files.size') }}</th>
                    <th class="text-end">{{ _l('cdn.common.requests') }}</th>
                    <th>{{ _l('cdn.files.added') }}</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($files['items'] as $file) : ?>
                    <tr>
                        <td>
                            <input class="form-check-input file-pick" type="checkbox" value="<?= (int) $file['id'] ?>">
                        </td>

                        <td class="mono small truncate notranslate" translate="no"><?= e($file['path'], false) ?></td>

                        <td class="small notranslate" translate="no">
                            <?php if ($file['bucket']) : ?>
                                <a href="{{ route('cdn-admin.operator.files') }}?bucket={{ $file['bucket']['id'] }}">
                                    <?= e($file['bucket']['name'], false) ?>
                                </a>
                            <?php else : ?>—<?php endif ?>
                        </td>

                        <td class="small notranslate" translate="no">
                            <?php if ($file['project']) : ?>
                                <a href="{{ route('cdn-admin.operator.projects.show', ['id' => $file['project']['id']]) }}">
                                    <?= e($file['project']['name'], false) ?>
                                </a>
                                <div class="hint mono truncate"><?= $prefix ?>/<?= e($file['project']['slug'], false) ?>/</div>
                            <?php else : ?>—<?php endif ?>
                        </td>

                        <td class="text-end small notranslate" translate="no">{{ File::humanFileSize((int) $file['size']) }}</td>
                        <td class="text-end small notranslate" translate="no">{{ number_format((int) $file['downloads']) }}</td>
                        <td class="small hint text-nowrap notranslate" translate="no"><?= $file['created_at'] ?></td>
                    </tr>
                <?php endforeach ?>

                <?php if (!count($files['items'])) : ?>
                    <tr><td colspan="7" class="text-center hint py-4">{{ _l('cdn.common.nothing') }}</td></tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3"><?= $files['links']('cdn.pagination') ?></div>
@endsection
