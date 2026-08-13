@extends('cdn.main')
@section('title')<?= _l('cdn.operator.system') ?>@endsection
@section('lede')<?= _l('cdn.operator.system-lede') ?>@endsection

@section('body')
<?php include(BASE_PATH . '/resource/views/cdn/partials/operator-nav.php') ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.operator.capabilities') }}</h6>
                <p class="hint">{{ _l('cdn.operator.capabilities-lede') }}</p>

                <dl class="row small mb-0">
                    <dt class="col-5 hint">{{ _l('cdn.operator.image-engine') }}</dt>
                    <dd class="col-7">
                        <?php if ($capabilities['driver'] === 'none') : ?>
                            <span class="badge text-bg-danger notranslate" translate="no">none</span>
                            <div class="hint">{{ _l('cdn.operator.no-engine') }}</div>
                        <?php else : ?>
                            <span class="badge text-bg-success notranslate" translate="no"><?= $capabilities['driver'] ?></span>
                        <?php endif ?>
                    </dd>

                    <dt class="col-5 hint">{{ _l('cdn.operator.can-write') }}</dt>
                    <dd class="col-7">
                        <?php foreach ($capabilities['formats'] as $format => $supported) : ?>
                            <span class="badge text-bg-<?= $supported ? 'success' : 'secondary' ?> notranslate" translate="no"><?= $format ?></span>
                        <?php endforeach ?>
                    </dd>

                    <dt class="col-5 hint">{{ _l('cdn.operator.shared-cache') }}</dt>
                    <dd class="col-7 notranslate" translate="no">
                        <span class="badge text-bg-<?= $capabilities['redis'] ? 'success' : 'secondary' ?>">redis</span>
                        <span class="badge text-bg-<?= $capabilities['apcu'] ? 'success' : 'secondary' ?>">apcu</span>
                    </dd>

                    <dt class="col-5 hint">{{ _l('cdn.operator.sniffing') }}</dt>
                    <dd class="col-7 notranslate" translate="no">
                        <span class="badge text-bg-<?= $capabilities['finfo'] ? 'success' : 'danger' ?>">finfo</span>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.operator.disks') }}</h6>
                <p class="hint">{{ _l('cdn.operator.disks-lede') }}</p>

                <?php foreach ($disks as $name => $disk) : ?>
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="mono truncate notranslate" translate="no" title="<?= e((string) $disk['root'], false) ?>"><?= e((string) $name, false) ?></span>
                        <span class="text-nowrap">
                            <?php if ($disk['writable'] === false) : ?>
                                <span class="badge text-bg-danger">{{ _l('cdn.operator.not-writable') }}</span>
                            <?php endif ?>
                            <?php if ($disk['free'] !== null) : ?>
                                <span class="notranslate" translate="no">{{ File::humanFileSize($disk['free']) }}</span> {{ _l('cdn.operator.free') }}
                            <?php endif ?>
                        </span>
                    </div>
                <?php endforeach ?>

                <hr>

                <div class="d-flex justify-content-between small">
                    <span class="hint">{{ _l('cdn.operator.generated-images') }}</span>
                    <span class="notranslate" translate="no">
                        {{ number_format($variants['files']) }} · {{ File::humanFileSize($variants['bytes']) }}
                    </span>
                </div>

                <div class="d-flex justify-content-between small mt-1">
                    <span class="hint">{{ _l('cdn.operator.total-stored') }}</span>
                    <span class="notranslate" translate="no">{{ File::humanFileSize($totals['storage']) }}</span>
                </div>

                <div class="d-flex justify-content-between small mt-1">
                    <span class="hint">{{ _l('cdn.operator.suspended-projects') }}</span>
                    <span class="notranslate" translate="no">{{ number_format($totals['suspended']) }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
