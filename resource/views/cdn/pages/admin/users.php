@extends('cdn.main')
@section('title')<?= _l('cdn.operator.users') ?>@endsection
@section('lede')<?= _l('cdn.operator.users-lede') ?>@endsection

@section('body')
<?php include(BASE_PATH . '/resource/views/cdn/partials/operator-nav.php') ?>

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

<div class="card">
    <div class="card-body">

        <form class="row g-2 align-items-center mb-3" method="GET">
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

        <?php if ($locked) : ?>
            <div class="alert alert-info small py-2">
                <?= _l('cdn.operator.operators-in-config') ?>
            </div>
        <?php endif ?>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
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
                        $self      = (int) $user['id'] === (int) zFramework\Core\Facades\Auth::id();
                    ?>
                        <tr>
                            <td>
                                <div class="notranslate" translate="no">
                                    <b><?= e($user['username'], false) ?></b>
                                    <?php if ($user['operator']) : ?><span class="badge text-bg-primary ms-1">operator</span><?php endif ?>
                                    <?php if ($suspended) : ?><span class="badge text-bg-danger ms-1"><?= _l('cdn.operator.suspended') ?></span><?php endif ?>
                                </div>
                                <div class="hint mono truncate notranslate" translate="no"><?= e($user['email'], false) ?></div>
                            </td>

                            <td class="small notranslate" translate="no">
                                <?php foreach ($user['projects'] as $project) : ?>
                                    <div class="truncate"><?= e($project['name'], false) ?></div>
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

                            <td class="text-end small notranslate" translate="no">{{ File::humanFileSize($user['bandwidth']) }}</td>

                            <td class="text-end text-nowrap">
                                <?php # Quotas are per project, so this is a link to the project rows for this account. ?>
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="{{ route('cdn-admin.operator.projects') }}?q=<?= urlencode((string) ($user['projects'][0]['name'] ?? '')) ?>">
                                    {{ _l('cdn.operator.quota') }}
                                </a>

                                <?php if (!$self) : ?>
                                    <form class="d-inline" method="POST" action="{{ route('cdn-admin.operator.users.status', ['id' => $user['id']]) }}">
                                        <?= csrf() ?>
                                        <input type="hidden" name="status" value="<?= $suspended ? 'active' : 'suspended' ?>">
                                        <button class="btn btn-sm btn-outline-<?= $suspended ? 'success' : 'warning' ?>">
                                            <?= _l($suspended ? 'cdn.operator.restore' : 'cdn.operator.suspend') ?>
                                        </button>
                                    </form>

                                    <?php if (!$locked) : ?>
                                        <form class="d-inline" method="POST" action="{{ route('cdn-admin.operator.users.operator', ['id' => $user['id']]) }}">
                                            <?= csrf() ?>
                                            <input type="hidden" name="operator" value="<?= $user['operator'] ? '0' : '1' ?>">
                                            <button class="btn btn-sm btn-outline-secondary">
                                                <?= _l($user['operator'] ? 'cdn.operator.demote' : 'cdn.operator.promote') ?>
                                            </button>
                                        </form>
                                    <?php endif ?>

                                    <?php # Deletes the files too, which is why it asks. ?>
                                    <form class="d-inline" method="POST"
                                          action="{{ route('cdn-admin.operator.users.delete', ['id' => $user['id']]) }}"
                                          data-confirm="<?= e(_l('cdn.operator.delete-confirm', ['user' => $user['username']]), false) ?>">
                                        <?= csrf() ?>
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>

                    <?php if (!count($users['items'])) : ?>
                        <tr><td colspan="5" class="text-center hint py-4">{{ _l('cdn.common.nothing') }}</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>

        <div class="mt-3"><?= $users['links']('cdn.pagination') ?></div>
    </div>
</div>
@endsection
