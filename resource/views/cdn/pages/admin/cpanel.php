@extends('cdn.main')
@section('title')cPanel@endsection
@section('lede')<?= _l('cdn.cpanel.lede') ?>@endsection

@section('body')
<?php include(BASE_PATH . '/resource/views/cdn/partials/operator-nav.php') ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.cpanel.connection') }}</h6>
                <p class="hint">{{ _l('cdn.cpanel.connection-lede') }}</p>

                <form method="POST" action="{{ route('cdn-admin.operator.cpanel.save') }}">
                    <?= csrf() ?>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="enabled" value="1" id="cpanel-enabled"
                               <?= $credentials['enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="cpanel-enabled">
                            <b>{{ _l('cdn.cpanel.enabled') }}</b>
                        </label>
                    </div>

                    <label class="form-label small mb-1">{{ _l('cdn.cpanel.domain') }}</label>
                    <input name="domain" class="form-control form-control-sm mono mb-1"
                           value="<?= e($credentials['domain'], false) ?>" placeholder="server.example.com">
                    <div class="form-text mb-3">{{ _l('cdn.cpanel.domain-help') }}</div>

                    <label class="form-label small mb-1">{{ _l('cdn.cpanel.username') }}</label>
                    <input name="username" class="form-control form-control-sm mono mb-3"
                           value="<?= e($credentials['username'], false) ?>">

                    <label class="form-label small mb-1">{{ _l('cdn.cpanel.token') }}</label>

                    <?php # Never sent back to the browser. A token in the page
                          # source is a token in every cache and every screen
                          # share; leaving the field empty keeps the stored one. ?>
                    <input name="token" class="form-control form-control-sm mono mb-1" autocomplete="off"
                           placeholder="<?= $credentials['token'] !== '' ? _l('cdn.cpanel.token-kept') : '' ?>">
                    <div class="form-text mb-3">{{ _l('cdn.cpanel.token-help') }}</div>

                    <button class="btn btn-sm btn-primary">{{ _l('cdn.common.save') }}</button>

                    <?php if ($credentials['token'] !== '') : ?>
                        <button class="btn btn-sm btn-outline-danger" name="forget" value="1"
                                data-confirm="<?= e(_l('cdn.cpanel.forget-confirm'), false) ?>">
                            {{ _l('cdn.cpanel.forget') }}
                        </button>
                    <?php endif ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.cpanel.usage') }}</h6>

                <?php if (!$configured) : ?>
                    <p class="hint mb-0">{{ _l('cdn.cpanel.not-connected') }}</p>
                <?php elseif (!$usage) : ?>
                    <div class="alert alert-warning small mb-0">{{ _l('cdn.cpanel.no-answer') }}</div>
                <?php else : ?>
                    <div class="row g-3">
                        <?php foreach (['disk', 'files', 'bandwidth'] as $metric) : ?>
                            <?php if (!isset($usage[$metric])) continue ?>
                            <?php $one = $usage[$metric]; ?>

                            <div class="col-md-4">
                                <div class="stat">
                                    <div class="label"><?= _l("cdn.system.account-$metric") ?></div>

                                    <div class="value notranslate" translate="no">
                                        <?= $metric === 'files' ? number_format((int) $one['used']) : File::humanFileSize((int) $one['used']) ?>
                                    </div>

                                    <?php if ($one['maximum'] === null) : ?>
                                        <div class="hint"><?= _l('cdn.system.account-unlimited') ?></div>
                                    <?php else : ?>
                                        <div class="quota <?= $one['share'] >= 95 ? 'full' : ($one['share'] >= 85 ? 'warn' : '') ?>">
                                            <div style="width: <?= min(100, (int) $one['share']) ?>%"></div>
                                        </div>
                                        <div class="hint mt-1 notranslate" translate="no">
                                            <?= _l('cdn.common.of') ?>
                                            <?= $metric === 'files' ? number_format((int) $one['maximum']) : File::humanFileSize((int) $one['maximum']) ?>
                                            · <?= $one['share'] ?>%
                                        </div>
                                    <?php endif ?>
                                </div>
                            </div>
                        <?php endforeach ?>
                    </div>

                    <p class="hint small mt-3 mb-0">{{ _l('cdn.system.account-note') }}</p>
                <?php endif ?>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
            <div>
                <h6 class="mb-1">{{ _l('cdn.cpanel.cron') }}</h6>
                <p class="hint mb-0">{{ _l('cdn.cpanel.cron-lede') }}</p>
            </div>

            <?php if ($configured) : ?>
                <form method="POST" action="{{ route('cdn-admin.operator.cpanel.cron') }}" class="d-flex gap-2">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="install">

                    <select name="schedule" class="form-select form-select-sm" style="width: 190px" data-plain>
                        <?php foreach ((array) _l('cdn.cpanel.schedules') as $expression => $label) : ?>
                            <option value="<?= e((string) $expression, false) ?>" <?= $expression === '0 * * * *' ? 'selected' : '' ?>>
                                <?= e((string) $label, false) ?>
                            </option>
                        <?php endforeach ?>
                    </select>

                    <button class="btn btn-sm btn-primary text-nowrap">
                        <i class="bi bi-clock"></i> {{ _l('cdn.cpanel.install-cron') }}
                    </button>
                </form>
            <?php endif ?>
        </div>

        <?php # What this installation wants run, spelled out - so it can be
              # pasted into a crontab by hand on a host with no cPanel. ?>
        <pre class="small bg-light p-3 rounded mb-3 notranslate" translate="no"><code>0 * * * * <?= e($command, false) ?></code></pre>

        <?php if (!$configured) : ?>
            <p class="hint mb-0">{{ _l('cdn.cpanel.not-connected') }}</p>
        <?php elseif ($crons === null) : ?>
            <div class="alert alert-warning small mb-0">{{ _l('cdn.cpanel.no-answer') }}</div>
        <?php else : ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 140px">{{ _l('cdn.cpanel.schedule') }}</th>
                            <th>{{ _l('cdn.cpanel.command') }}</th>
                            <th style="width: 1%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($crons as $line) : ?>
                            <tr class="<?= $line['mine'] ? 'table-primary' : '' ?>">
                                <td class="mono small notranslate" translate="no"><?= e($line['schedule'], false) ?></td>

                                <td class="mono small path notranslate" translate="no">
                                    <?= e($line['command'], false) ?>
                                    <?php if ($line['mine']) : ?>
                                        <span class="badge text-bg-primary ms-1">{{ _l('cdn.cpanel.ours') }}</span>
                                    <?php endif ?>
                                </td>

                                <td class="text-end">
                                    <form method="POST" action="{{ route('cdn-admin.operator.cpanel.cron') }}"
                                          data-confirm="<?= e(_l('cdn.cpanel.remove-confirm'), false) ?>">
                                        <?= csrf() ?>
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="key" value="<?= $line['key'] ?>">
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach ?>

                        <?php if (!count($crons)) : ?>
                            <tr><td colspan="3" class="text-center hint py-3">{{ _l('cdn.cpanel.no-crons') }}</td></tr>
                        <?php endif ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </div>
</div>
@endsection
