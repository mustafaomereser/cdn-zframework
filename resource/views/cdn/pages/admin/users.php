@extends('cdn.main')
@section('title')<?= _l('cdn.operator.users') ?>@endsection
@section('lede')<?= _l('cdn.operator.users-lede') ?>@endsection

@section('body')
<?php include(BASE_PATH . '/resource/views/cdn/partials/operator-nav.php') ?>

<?php
/**
 * A list, and nothing else.
 *
 * Every row used to carry a quota form and a suspend form folded into
 * collapsible rows - four forms per account, on a page whose job is to let
 * somebody find one. The account's own page exists and has room for all of it.
 */
?>

<?php # The four numbers somebody opens this page to see. ?>
<div class="row g-3 mb-3">
    <?php foreach ([
        ['users',     'bi-people',  number_format($totals['users'])],
        ['projects',  'bi-boxes',   number_format($totals['projects'])],
        ['stored',    'bi-hdd',     File::humanFileSize($totals['storage'])],
        ['transfer',  'bi-arrow-down-up', File::humanFileSize($totals['bandwidth'])],
    ] as [$key, $icon, $value]) : ?>
        <div class="col-6 col-lg-3">
            <div class="stat">
                <div class="label"><i class="bi <?= $icon ?>"></i> <?= _l("cdn.operator.total-$key") ?></div>
                <div class="value notranslate" translate="no"><?= $value ?></div>
            </div>
        </div>
    <?php endforeach ?>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-center mb-0" method="GET">
            <div class="col-sm-6 col-lg-4">
                <input name="q" class="form-control form-control-sm" value="{{ request('q') }}"
                       placeholder="{{ _l('cdn.operator.search-users') }}">
            </div>
            <div class="col-sm-4 col-lg-3">
                <select name="status" class="form-select form-select-sm" data-autosubmit>
                    <option value="">{{ _l('cdn.operator.any-status') }}</option>
                    <option value="active" <?= request('status') === 'active' ? 'selected' : '' ?>>{{ _l('cdn.operator.active') }}</option>
                    <option value="suspended" <?= request('status') === 'suspended' ? 'selected' : '' ?>>{{ _l('cdn.operator.suspended') }}</option>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-secondary">{{ _l('cdn.common.search') }}</button>
            </div>
        </form>
    </div>
</div>

<?php if ($locked) : ?>
    <div class="alert alert-info small py-2"><?= _l('cdn.operator.operators-in-config') ?></div>
<?php endif ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>{{ _l('cdn.operator.account') }}</th>
                    <th>{{ _l('cdn.operator.projects') }}</th>
                    <th class="text-end">{{ _l('cdn.common.storage') }}</th>
                    <th class="text-end">{{ _l('cdn.operator.transfer') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users['items'] as $user) :
                    $suspended = ($user['status'] ?? 'active') === 'suspended';
                ?>
                    <tr>
                        <td>
                            <div class="notranslate" translate="no">
                                <a class="fw-medium" href="{{ route('cdn-admin.operator.users.show', ['id' => $user['id']]) }}">
                                    <?= e($user['username'], false) ?>
                                </a>
                                <?php if ($user['operator']) : ?><span class="badge text-bg-primary ms-1">operator</span><?php endif ?>
                                <?php if ($suspended) : ?><span class="badge text-bg-danger ms-1"><?= _l('cdn.operator.suspended') ?></span><?php endif ?>
                            </div>

                            <div class="hint mono truncate notranslate" translate="no"><?= e($user['email'], false) ?></div>

                            <?php if ($suspended && ($user['suspend_reason'] ?? '')) : ?>
                                <div class="small text-danger truncate"><?= e((string) $user['suspend_reason'], false) ?></div>
                            <?php endif ?>
                        </td>

                        <td class="small notranslate" translate="no">
                            <?php foreach ($user['projects'] as $project) : ?>
                                <div class="truncate">
                                    <a href="{{ route('cdn-admin.operator.projects.show', ['id' => $project['id']]) }}">
                                        <?= e($project['name'], false) ?>
                                    </a>
                                </div>
                            <?php endforeach ?>
                            <?php if (!count($user['projects'])) : ?><span class="hint">—</span><?php endif ?>
                        </td>

                        <td class="text-end small notranslate" translate="no">
                            {{ File::humanFileSize($user['storage']) }}
                            <?php if ($user['quota'] > 0) : ?>
                                <div class="hint">{{ _l('cdn.common.of') }} {{ File::humanFileSize($user['quota']) }}</div>
                            <?php else : ?>
                                <div class="hint">{{ _l('cdn.operator.unlimited') }}</div>
                            <?php endif ?>
                        </td>

                        <td class="text-end small notranslate" translate="no">
                            {{ File::humanFileSize($user['bandwidth']) }}
                            <?php # The same shape as the storage cell next to
                                  # it. It showed a number with nothing to
                                  # measure it against, which reads as though
                                  # there were no transfer limit at all. ?>
                            <?php if (($user['bandwidth-quota'] ?? 0) > 0) : ?>
                                <div class="hint">{{ _l('cdn.common.of') }} {{ File::humanFileSize((int) $user['bandwidth-quota']) }}</div>
                            <?php else : ?>
                                <div class="hint">{{ _l('cdn.operator.unlimited') }}</div>
                            <?php endif ?>
                        </td>

                        <td class="text-end text-nowrap">
                            <?php # Everything that changes an account is on the
                                  # account's page. This one is for finding it. ?>
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('cdn-admin.operator.users.show', ['id' => $user['id']]) }}">
                                {{ _l('cdn.common.edit') }}
                            </a>
                        </td>
                    </tr>
                <?php endforeach ?>

                <?php if (!count($users['items'])) : ?>
                    <tr><td colspan="5" class="text-center hint py-4">{{ _l('cdn.common.nothing') }}</td></tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3"><?= $users['links']('cdn.pagination') ?></div>
@endsection
