@extends('cdn.main')
@section('title')<?= _l('cdn.dashboard.title') ?>@endsection
@section('lede')<?= _l('cdn.dashboard.lede') ?>@endsection

@section('body')
<?php
/**
 * Four bands, in the order somebody actually needs them:
 *
 *   1. Upload, because that is what this page is opened to do.
 *   2. The four numbers that say what it costs and how it is going.
 *   3. Traffic over the month, full width - it was a sparkline in a side card,
 *      which is a chart nobody can read a date off.
 *   4. What is in there: recent files, and the buckets they went into.
 *
 * It used to be the same material in a 7/5 split with the numbers above it and
 * the chart wedged under a bucket list, which is how a page ends up feeling
 * scattered while containing nothing extra.
 */
$hitRatio = ($totals['hits'] + $totals['misses']) > 0
    ? round($totals['hits'] / ($totals['hits'] + $totals['misses']) * 100)
    : null;

$peak = max(1, max(array_map(fn($day) => (int) $day['requests'], $series ?: [['requests' => 0]])));

$share     = $usage['quota'] > 0 ? min(100, round($usage['used'] / $usage['quota'] * 100)) : 0;
$sentShare = $usage['bandwidth-quota'] > 0 ? min(100, round($usage['bandwidth'] / $usage['bandwidth-quota'] * 100)) : 0;
?>

@if(!count($buckets))
<div class="card mb-4">
    <div class="empty">
        <i class="bi bi-folder-plus"></i>
        <h5>{{ _l('cdn.dashboard.empty-title') }}</h5>
        <p class="hint">{{ _l('cdn.dashboard.empty-text') }}</p>
        <a href="{{ route('cdn-admin.buckets.create') }}" class="btn btn-primary">{{ _l('cdn.dashboard.empty-action') }}</a>
    </div>
</div>
@else

<?php # 1 — upload ?>
<div class="card mb-3">
    <div class="card-body">
        <form action="{{ route('cdn-admin.files.upload') }}" method="POST" enctype="multipart/form-data">
            <?= csrf() ?>

            <div class="row g-3 align-items-stretch">
                <div class="col-lg-5">
                    <div class="dropzone h-100" id="dropzone">
                        <i class="bi bi-cloud-arrow-up"></i>
                        <div class="mt-2">
                            <label class="btn btn-primary btn-sm mb-0">
                                {{ _l('cdn.dashboard.choose') }} <input type="file" name="files[]" multiple hidden id="file-input">
                            </label>
                        </div>
                        <div class="hint mt-2">{{ _l('cdn.dashboard.drag') }}</div>
                        <div class="hint mt-1 notranslate" translate="no" id="file-list"></div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <label class="form-label small mb-1">{{ _l('cdn.dashboard.into') }}</label>
                    <select name="bucket" class="form-select form-select-sm mb-3">
                        @foreach($buckets as $bucket)
                        <option value="{{ $bucket['id'] }}">{{ $bucket['name'] }} — /{{ Tenant::projectOf($bucket)['slug'] }}/{{ $bucket['slug'] }}</option>
                        @endforeach
                    </select>

                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="form-label small mb-1">
                                {{ _l('cdn.dashboard.folder') }} <span class="hint">({{ _l('cdn.common.optional') }})</span>
                            </label>
                            <input name="path" class="form-control form-control-sm mono" placeholder="photos/2026">
                        </div>

                        <div class="col-sm-6">
                            <label class="form-label small mb-1">{{ _l('cdn.dashboard.or-url') }}</label>
                            <input name="url" class="form-control form-control-sm mono" placeholder="https://…">
                        </div>
                    </div>

                    <button class="btn btn-primary btn-sm mt-3">
                        <i class="bi bi-cloud-arrow-up"></i> {{ _l('cdn.common.upload') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php # 2 — the numbers ?>
<div class="row g-3 mb-3">
    <div class="col-md-3 col-6">
        <div class="stat">
            <div class="label">{{ _l('cdn.dashboard.stored') }}</div>
            <div class="value notranslate" translate="no">{{ File::humanFileSize($usage['used']) }}</div>
            @if($usage['quota'] > 0)
            <div class="quota <?= $share >= 95 ? 'full' : ($share >= 80 ? 'warn' : '') ?>">
                <div style="width: <?= $share ?>%"></div>
            </div>
            <div class="hint mt-1">
                {{ _l('cdn.common.of') }} <span class="notranslate" translate="no">{{ File::humanFileSize($usage['quota']) }}</span>
            </div>
            @else
            <div class="hint">{{ _l('cdn.dashboard.no-limit') }}</div>
            @endif
        </div>
    </div>

    <div class="col-md-3 col-6">
        <div class="stat">
            <div class="label">{{ _l('cdn.common.transfer') }} <span class="notranslate" translate="no"><?= date('Y-m') ?></span></div>
            <div class="value notranslate" translate="no">{{ File::humanFileSize($usage['bandwidth']) }}</div>
            @if($usage['bandwidth-quota'] > 0)
            <div class="quota <?= $sentShare >= 95 ? 'full' : ($sentShare >= 80 ? 'warn' : '') ?>">
                <div style="width: <?= $sentShare ?>%"></div>
            </div>
            <div class="hint mt-1">
                {{ _l('cdn.common.of') }} <span class="notranslate" translate="no">{{ File::humanFileSize($usage['bandwidth-quota']) }}</span>
            </div>
            @else
            <div class="hint">{{ _l('cdn.dashboard.no-limit') }}</div>
            @endif
        </div>
    </div>

    <div class="col-md-3 col-6">
        <div class="stat">
            <div class="label">{{ _l('cdn.dashboard.today') }}</div>
            <div class="value notranslate" translate="no">{{ number_format((int) ($today['requests'] ?? 0)) }}</div>
            <div class="hint">
                {{ _l('cdn.common.requests') }} ·
                <span class="notranslate" translate="no">{{ File::humanFileSize((int) ($today['bytes'] ?? 0)) }}</span>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-6">
        <div class="stat">
            <div class="label">{{ _l('cdn.dashboard.from-cache') }}</div>
            <div class="value notranslate" translate="no">{{ $hitRatio === null ? '—' : $hitRatio . '%' }}</div>
            <div class="hint">{{ _l('cdn.dashboard.from-cache-hint') }}</div>
        </div>
    </div>
</div>

<?php # 3 — traffic ?>
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">{{ _l('cdn.dashboard.traffic') }}</h6>
            <span class="hint small">
                <span class="notranslate" translate="no">{{ number_format($totals['requests']) }}</span> {{ _l('cdn.common.requests') }} ·
                <span class="notranslate" translate="no">{{ File::humanFileSize($totals['bytes']) }}</span>
            </span>
        </div>

        @if(count($series))
        <div class="chart">
            @foreach($series as $day)
            <div class="bar" style="height: {{ max(2, round(((int) $day['requests'] / $peak) * 100)) }}%"
                 title="{{ $day['date'] }} — {{ number_format((int) $day['requests']) }} {{ _l('cdn.common.requests') }}"></div>
            @endforeach
        </div>

        <?php # First and last, so the bars are a month rather than a texture. ?>
        <div class="chart-axis notranslate" translate="no">
            <span>{{ $series[0]['date'] }}</span>
            <span>{{ $series[count($series) - 1]['date'] }}</span>
        </div>
        @else
        <p class="hint mb-0">{{ _l('cdn.dashboard.no-traffic') }}</p>
        @endif
    </div>
</div>

<?php # 4 — what is in there ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">{{ _l('cdn.dashboard.recent') }}</h6>
                    <a href="{{ route('cdn-admin.files') }}" class="small">{{ _l('cdn.dashboard.all-files') }}</a>
                </div>

                @forelse($files as $file)
                <?php
                # Where it went, next to what it is called. The list used to be
                # paths with no bucket on them, which is a list of names nobody
                # can place.
                $rowBucket = Tenant::bucket((int) $file['bucket_id']);
                ?>
                <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                    <div class="min-w-0">
                        <a href="{{ route('cdn-admin.files.show', ['id' => $file['id']]) }}"
                           class="mono small truncate d-block notranslate" translate="no">{{ $file['path'] }}</a>
                        <a class="hint notranslate" translate="no" href="{{ route('cdn-admin.files') }}?bucket={{ $rowBucket['id'] }}">
                            {{ $rowBucket['name'] }}
                        </a>
                    </div>

                    <span class="hint text-nowrap ms-3 notranslate" translate="no">
                        {{ File::humanFileSize($file['size']) }} · {{ number_format((int) $file['downloads']) }}
                    </span>
                </div>
                @empty
                <p class="hint mb-0">{{ _l('cdn.dashboard.nothing-yet') }}</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">{{ _l('cdn.dashboard.your-buckets') }}</h6>
                    <a href="{{ route('cdn-admin.buckets') }}" class="small">{{ _l('cdn.dashboard.manage') }}</a>
                </div>

                @foreach($buckets as $bucket)
                <?php $rowProject = Tenant::projectOf($bucket); ?>
                <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                    <div class="min-w-0">
                        <a href="{{ route('cdn-admin.files') }}?bucket={{ $bucket['id'] }}"
                           class="notranslate" translate="no">{{ $bucket['name'] }}</a>

                        <?php # Which project it is in - the thing the bucket
                              # list never said. ?>
                        <div class="hint truncate notranslate" translate="no">
                            <a href="{{ route('cdn-admin.projects.show', ['id' => $rowProject['id']]) }}">{{ $rowProject['name'] }}</a>
                            · <span class="mono">/{{ $rowProject['slug'] }}/{{ $bucket['slug'] }}/</span>
                        </div>
                    </div>

                    <span class="text-nowrap ms-2">
                        <span class="badge text-bg-{{ $bucket['visibility'] == 'public' ? 'success' : ($bucket['visibility'] == 'signed' ? 'warning' : 'secondary') }}">
                            {{ _l('cdn.visibility.' . $bucket['visibility']) }}
                        </span>
                        <a class="btn btn-sm btn-outline-secondary ms-1" title="{{ _l('cdn.buckets.show-files') }}"
                           href="{{ route('cdn-admin.files') }}?bucket={{ $bucket['id'] }}">
                            <i class="bi bi-file-earmark"></i>
                            <span class="notranslate" translate="no">{{ number_format((int) $bucket['files_count']) }}</span>
                        </a>
                    </span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endif
@endsection

@section('footer')
<script>
    // Drag and drop writes into the same file input the button uses, so there
    // is one upload path rather than two that can disagree.
    const dropzone = document.getElementById('dropzone');
    const input    = document.getElementById('file-input');
    const list     = document.getElementById('file-list');

    const describe = () => {
        const files = Array.from(input.files || []);
        list.textContent = files.length ? files.map(file => file.name).join(', ') : '';
    };

    input?.addEventListener('change', describe);

    ['dragenter', 'dragover'].forEach(type => dropzone?.addEventListener(type, event => {
        event.preventDefault();
        dropzone.classList.add('over');
    }));

    ['dragleave', 'drop'].forEach(type => dropzone?.addEventListener(type, event => {
        event.preventDefault();
        dropzone.classList.remove('over');
    }));

    dropzone?.addEventListener('drop', event => {
        input.files = event.dataTransfer.files;
        describe();
    });
</script>
@endsection
