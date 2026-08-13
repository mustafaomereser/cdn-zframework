@extends('cdn.main')
@section('title')<?= _l('cdn.dashboard.title') ?>@endsection
@section('lede')<?= _l('cdn.dashboard.lede') ?>@endsection

@section('body')
<?php
$hitRatio = ($totals['hits'] + $totals['misses']) > 0
    ? round($totals['hits'] / ($totals['hits'] + $totals['misses']) * 100)
    : null;

$peak = max(1, max(array_map(fn($day) => (int) $day['requests'], $series ?: [['requests' => 0]])));
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

<div class="card mb-4">
    <div class="card-body">
        <form action="{{ route('cdn-admin.files.upload') }}" method="POST" enctype="multipart/form-data">
            <?= csrf() ?>

            <div class="dropzone mb-3" id="dropzone">
                <i class="bi bi-cloud-arrow-up"></i>
                <div class="mt-2">
                    <label class="btn btn-primary btn-sm mb-0">
                        {{ _l('cdn.dashboard.choose') }} <input type="file" name="files[]" multiple hidden id="file-input">
                    </label>
                    <span class="hint ms-2">{{ _l('cdn.dashboard.drag') }}</span>
                </div>
                <div class="hint mt-2 notranslate" translate="no" id="file-list"></div>
            </div>

            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1">{{ _l('cdn.dashboard.into') }}</label>
                    <select name="bucket" class="form-select form-select-sm">
                        @foreach($buckets as $bucket)
                        <option value="{{ $bucket['id'] }}">{{ $bucket['name'] }} — /{{ Tenant::projectOf($bucket)['slug'] }}/{{ $bucket['slug'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label small mb-1">
                        {{ _l('cdn.dashboard.folder') }} <span class="hint">({{ _l('cdn.common.optional') }})</span>
                    </label>
                    <input name="path" class="form-control form-control-sm mono" placeholder="photos/2026">
                </div>

                <div class="col-md-3">
                    <label class="form-label small mb-1">{{ _l('cdn.dashboard.or-url') }}</label>
                    <input name="url" class="form-control form-control-sm mono" placeholder="https://…">
                </div>

                <div class="col-md-1">
                    <button class="btn btn-primary btn-sm w-100">{{ _l('cdn.common.upload') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
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
            <div class="label">{{ _l('cdn.dashboard.last-30') }}</div>
            <div class="value notranslate" translate="no">{{ File::humanFileSize($totals['bytes']) }}</div>
            <div class="hint">
                <span class="notranslate" translate="no">{{ number_format($totals['requests']) }}</span> {{ _l('cdn.common.requests') }}
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
    <div class="col-md-3 col-6">
        <div class="stat">
            <div class="label">{{ _l('cdn.dashboard.stored') }}</div>
            <div class="value notranslate" translate="no">{{ File::humanFileSize($usage['used']) }}</div>
            <div class="hint">
                @if($usage['quota'] > 0)
                {{ _l('cdn.common.of') }} <span class="notranslate" translate="no">{{ File::humanFileSize($usage['quota']) }}</span>
                @else
                {{ _l('cdn.dashboard.no-limit') }}
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">{{ _l('cdn.dashboard.recent') }}</h6>
                    <a href="{{ route('cdn-admin.files') }}" class="small text-decoration-none">{{ _l('cdn.dashboard.all-files') }}</a>
                </div>

                @forelse($files as $file)
                <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                    <a href="{{ route('cdn-admin.files.show', ['id' => $file['id']]) }}"
                       class="mono small truncate text-decoration-none notranslate" translate="no">
                        {{ $file['path'] }}
                    </a>
                    <span class="hint text-nowrap ms-3">
                        <span class="notranslate" translate="no">{{ File::humanFileSize($file['size']) }}</span> ·
                        <span class="notranslate" translate="no">{{ number_format((int) $file['downloads']) }}</span> {{ _l('cdn.common.hits') }}
                    </span>
                </div>
                @empty
                <p class="hint mb-0">{{ _l('cdn.dashboard.nothing-yet') }}</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="mb-3">{{ _l('cdn.dashboard.your-buckets') }}</h6>

                @foreach($buckets as $bucket)
                <div class="d-flex align-items-center justify-content-between py-1">
                    <div class="truncate">
                        <a href="{{ route('cdn-admin.files') }}?bucket={{ $bucket['id'] }}"
                           class="text-decoration-none notranslate" translate="no">{{ $bucket['name'] }}</a>
                        <span class="badge text-bg-{{ $bucket['visibility'] == 'public' ? 'success' : ($bucket['visibility'] == 'signed' ? 'warning' : 'secondary') }}">
                            {{ _l('cdn.visibility.' . $bucket['visibility']) }}
                        </span>
                    </div>
                    <span class="hint notranslate" translate="no">{{ number_format((int) $bucket['files_count']) }}</span>
                </div>
                @endforeach

                <a href="{{ route('cdn-admin.buckets') }}" class="small text-decoration-none d-block mt-2">{{ _l('cdn.dashboard.manage') }}</a>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h6 class="mb-2">{{ _l('cdn.dashboard.traffic') }}</h6>

                @if(count($series))
                <div class="spark">
                    @foreach($series as $day)
                    <div style="height: {{ max(2, round(((int) $day['requests'] / $peak) * 100)) }}%"
                         title="{{ $day['date'] }} — {{ number_format((int) $day['requests']) }}"></div>
                    @endforeach
                </div>
                @else
                <p class="hint mb-0">{{ _l('cdn.dashboard.no-traffic') }}</p>
                @endif
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
