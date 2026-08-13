@extends('cdn.main')
@section('title')<?= _l('cdn.settings.title') ?>@endsection
@section('lede')<?= _l('cdn.settings.lede') ?>@endsection

@section('body')
<?php
/**
 * What is left here is the account.
 *
 * Projects have their own pages, and the installation block an operator used to
 * find at the bottom of this one is under Administration - it was the only
 * thing here that had nothing to do with the signed-in account.
 */
$user = zFramework\Core\Facades\Auth::user() ?: [];

# host() is the framework's - scheme and host, proxy headers accounted for.
$api = host() . rtrim((string) (config('cdn.api.route') ?: '/api/cdn'), '/') . '/v1';
?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.settings.account') }}</h6>
                <p class="hint">{{ _l('cdn.settings.account-lede') }}</p>

                <dl class="row small mb-0">
                    <dt class="col-4 hint">{{ _l('cdn.auth.username') }}</dt>
                    <dd class="col-8 notranslate" translate="no">{{ $user['username'] }}</dd>

                    <dt class="col-4 hint">{{ _l('cdn.auth.email') }}</dt>
                    <dd class="col-8 mono truncate notranslate" translate="no">{{ $user['email'] }}</dd>

                    <dt class="col-4 hint">{{ _l('cdn.common.projects') }}</dt>
                    <dd class="col-8">
                        <a href="{{ route('cdn-admin.projects') }}">{{ count($projects) }}</a>
                    </dd>
                </dl>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.settings.allowance') }}</h6>
                <p class="hint">{{ _l('cdn.settings.allowance-lede') }}</p>

                <div class="d-flex justify-content-between small">
                    <span class="hint">{{ _l('cdn.common.storage') }}</span>
                    <span class="notranslate" translate="no">
                        {{ File::humanFileSize($usage['used']) }}
                        <?php if ($usage['quota'] > 0) : ?>
                            <span class="hint">{{ _l('cdn.common.of') }} {{ File::humanFileSize($usage['quota']) }}</span>
                        <?php else : ?>
                            <span class="hint">{{ _l('cdn.operator.unlimited') }}</span>
                        <?php endif ?>
                    </span>
                </div>

                <?php if ($usage['quota'] > 0) :
                    $share = min(100, round($usage['used'] / $usage['quota'] * 100));
                ?>
                    <div class="quota <?= $share >= 95 ? 'full' : ($share >= 80 ? 'warn' : '') ?> mb-2">
                        <div style="width: <?= $share ?>%"></div>
                    </div>
                <?php endif ?>

                <div class="d-flex justify-content-between small mt-2">
                    <span class="hint">{{ _l('cdn.common.transfer') }} <span class="notranslate" translate="no"><?= date('Y-m') ?></span></span>
                    <span class="notranslate" translate="no">
                        {{ File::humanFileSize($usage['bandwidth']) }}
                        <?php if ($usage['bandwidth-quota'] > 0) : ?>
                            <span class="hint">{{ _l('cdn.common.of') }} {{ File::humanFileSize($usage['bandwidth-quota']) }}</span>
                        <?php endif ?>
                    </span>
                </div>

                <p class="hint small mt-3 mb-0">{{ _l('cdn.settings.allowance-note') }}</p>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.settings.api') }}</h6>
                <p class="hint">{{ _l('cdn.settings.api-lede') }}</p>

                <div class="input-group input-group-sm mb-2">
                    <input class="form-control mono notranslate" translate="no" value="{{ $api }}" readonly>
                    <button class="btn btn-outline-secondary" data-copy="{{ $api }}">
                        <i class="bi bi-clipboard"></i> {{ _l('cdn.common.copy') }}
                    </button>
                </div>

                <a href="{{ route('cdn-admin.keys') }}" class="btn btn-sm btn-outline-secondary">{{ _l('cdn.menu.keys') }}</a>
                <a href="{{ route('docs') }}" target="_blank" class="btn btn-sm btn-link">{{ _l('cdn.menu.docs') }}</a>
            </div>
        </div>

        <?php if ($operator) : ?>
            <div class="card">
                <div class="card-body">
                    <h6>{{ _l('cdn.menu.operator') }}</h6>
                    <p class="hint">{{ _l('cdn.settings.operator-lede') }}</p>

                    <a href="{{ route('cdn-admin.operator.users') }}" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-shield-lock"></i> {{ _l('cdn.settings.operator-open') }}
                    </a>
                </div>
            </div>
        <?php endif ?>
    </div>
</div>
@endsection
