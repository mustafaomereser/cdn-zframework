@extends('cdn.main')
@section('title')<?= _l('cdn.operator.maintenance') ?>@endsection
@section('lede')<?= _l('cdn.operator.maintenance-lede') ?>@endsection

@section('actions')
<form method="POST" action="{{ route('cdn-admin.operator.maintenance.run') }}">
    <?= csrf() ?>
    <button class="btn btn-sm btn-primary text-nowrap">
        <i class="bi bi-play-fill"></i> {{ _l('cdn.operator.run-all') }}
    </button>
</form>
@endsection

@section('body')
<?php include(BASE_PATH . '/resource/views/cdn/partials/operator-nav.php') ?>

<div class="card mb-3">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>{{ _l('cdn.operator.task') }}</th>
                    <th class="text-end">{{ _l('cdn.operator.last-run') }}</th>
                    <th style="width: 1%"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasks as $task) : ?>
                    <tr>
                        <td>
                            <div class="fw-medium"><?= _l("cdn.operator.task-$task") ?></div>
                            <div class="hint"><?= _l("cdn.operator.task-$task-help") ?></div>
                        </td>

                        <td class="text-end hint small text-nowrap notranslate" translate="no">
                            <?= $lastRun[$task] ?: '—' ?>
                        </td>

                        <td class="text-end">
                            <form method="POST" action="{{ route('cdn-admin.operator.maintenance.run') }}">
                                <?= csrf() ?>
                                <input type="hidden" name="task" value="<?= $task ?>">
                                <button class="btn btn-sm btn-outline-secondary text-nowrap">
                                    {{ _l('cdn.operator.run') }}
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-body">
                <h6>{{ _l('cdn.operator.schedule') }}</h6>
                <p class="hint">{{ _l('cdn.operator.maintenance-cron') }}</p>

                <pre class="small bg-light p-3 rounded mb-2 notranslate" translate="no"><code>0 * * * * php <?= e(BASE_PATH, false) ?>/cron/cdn.php</code></pre>

                <div class="d-flex justify-content-between small py-1 border-bottom">
                    <span class="hint">{{ _l('cdn.operator.daily-marker') }}</span>
                    <span class="mono notranslate" translate="no"><?= $daily ?: '—' ?></span>
                </div>

                <p class="hint small mt-2 mb-0">{{ _l('cdn.operator.daily-marker-help') }}</p>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-body">
                <h6>{{ _l('cdn.operator.what-it-frees') }}</h6>

                <div class="d-flex justify-content-between small py-1 border-bottom">
                    <span class="hint">{{ _l('cdn.operator.generated-images') }}</span>
                    <span class="notranslate" translate="no">
                        {{ number_format($variants['files']) }} · {{ File::humanFileSize($variants['bytes']) }}
                    </span>
                </div>

                <div class="d-flex justify-content-between small py-1 border-bottom">
                    <span class="hint">{{ _l('cdn.operator.orphans') }}</span>
                    <span class="notranslate" translate="no">
                        {{ number_format($orphans['count']) }} · {{ File::humanFileSize($orphans['bytes']) }}
                    </span>
                </div>

                <div class="d-flex justify-content-between small py-1">
                    <span class="hint">{{ _l('cdn.operator.log-rows') }}</span>
                    <span class="notranslate" translate="no">{{ number_format($logRows) }}</span>
                </div>

                <p class="hint small mt-2 mb-0">{{ _l('cdn.operator.orphans-help') }}</p>
            </div>
        </div>
    </div>
</div>
@endsection
