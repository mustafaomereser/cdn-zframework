@extends('cdn.main')
@section('title')<?= _l('cdn.operator.audits') ?>@endsection
@section('lede')<?= _l('cdn.operator.audits-lede') ?>@endsection

@section('body')
<?php include(BASE_PATH . '/resource/views/cdn/partials/operator-nav.php') ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ _l('cdn.operator.when') }}</th>
                        <th>{{ _l('cdn.operator.who') }}</th>
                        <th>{{ _l('cdn.operator.what') }}</th>
                        <th>{{ _l('cdn.operator.subject') }}</th>
                        <th>{{ _l('cdn.operator.detail') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audits['items'] as $row) :
                        $detail = json_decode((string) $row['detail'], true) ?: [];
                    ?>
                        <tr>
                            <td class="small hint text-nowrap notranslate" translate="no"><?= $row['created_at'] ?></td>

                            <td class="small notranslate" translate="no">
                                <?= e((string) ($row['actor_email'] ?: '—'), false) ?>
                                <div class="hint mono"><?= e((string) ($row['ip'] ?: ''), false) ?></div>
                            </td>

                            <td><span class="badge text-bg-secondary notranslate" translate="no"><?= e((string) $row['action'], false) ?></span></td>

                            <td class="small notranslate" translate="no">
                                <?= e((string) ($row['subject_label'] ?: ''), false) ?>
                                <div class="hint"><?= e((string) $row['subject_type'], false) ?> #<?= (int) $row['subject_id'] ?></div>
                            </td>

                            <td class="small mono notranslate" translate="no">
                                <?php # Quota changes carry [before, after]; the rest carry whatever they carry. ?>
                                <?php foreach ($detail as $key => $value) : ?>
                                    <div class="truncate">
                                        <?= e((string) $key, false) ?>:
                                        <?php if (is_array($value) && count($value) === 2) : ?>
                                            <?= File::humanFileSize((int) $value[0]) ?> → <?= File::humanFileSize((int) $value[1]) ?>
                                        <?php else : ?>
                                            <?= e(is_scalar($value) ? (string) $value : json_encode($value), false) ?>
                                        <?php endif ?>
                                    </div>
                                <?php endforeach ?>
                            </td>
                        </tr>
                    <?php endforeach ?>

                    <?php if (!count($audits['items'])) : ?>
                        <tr><td colspan="5" class="text-center hint py-4">{{ _l('cdn.common.nothing') }}</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>

        <div class="mt-3"><?= $audits['links']('cdn.pagination') ?></div>
    </div>
</div>
@endsection
