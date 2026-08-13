@extends('cdn.main')
@section('title', 'Overview')
@section('lede', 'Drop a file in, copy its URL out. Everything else is optional.')

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
        <h5>Make a bucket first</h5>
        <p class="hint">
            A bucket is a folder with rules — how long browsers cache it, whether the URLs are public, what may be
            uploaded. Its name becomes the first part of every URL it serves.
        </p>
        <a href="{{ route('cdn-admin.buckets.create') }}" class="btn btn-primary">Create a bucket</a>
    </div>
</div>
@else

<div class="card mb-4">
    <div class="card-body">
        <form action="{{ route('cdn-admin.files.upload') }}" method="POST" enctype="multipart/form-data">
            <?= csrf() ?>

            <div class="dropzone mb-3" id="dropzone">
                <i class="bi bi-cloud-arrow-up" style="font-size: 1.6rem; color: #7b8794"></i>
                <div class="mt-2">
                    <label class="btn btn-primary btn-sm mb-0">
                        Choose files <input type="file" name="files[]" multiple hidden id="file-input">
                    </label>
                    <span class="hint ms-2">or drag them here</span>
                </div>
                <div class="hint mt-2" id="file-list"></div>
            </div>

            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Into bucket</label>
                    <select name="bucket" class="form-select form-select-sm">
                        @foreach($buckets as $bucket)
                        <option value="{{ $bucket['id'] }}">{{ $bucket['name'] }} — /{{ Tenant::projectOf($bucket)['slug'] }}/{{ $bucket['slug'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label small mb-1">Folder <span class="hint">(optional)</span></label>
                    <input name="path" class="form-control form-control-sm mono" placeholder="photos/2026">
                </div>

                <div class="col-md-3">
                    <label class="form-label small mb-1">…or paste a URL to fetch</label>
                    <input name="url" class="form-control form-control-sm mono" placeholder="https://…">
                </div>

                <div class="col-md-1">
                    <button class="btn btn-primary btn-sm w-100">Upload</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat">
            <div class="label">Today</div>
            <div class="value">{{ number_format((int) ($today['requests'] ?? 0)) }}</div>
            <div class="hint">requests · {{ File::humanFileSize((int) ($today['bytes'] ?? 0)) }}</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat">
            <div class="label">Last 30 days</div>
            <div class="value">{{ File::humanFileSize($totals['bytes']) }}</div>
            <div class="hint">{{ number_format($totals['requests']) }} requests</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat">
            <div class="label">Served from cache</div>
            <div class="value">{{ $hitRatio === null ? '—' : $hitRatio . '%' }}</div>
            <div class="hint">higher is cheaper and faster</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat">
            <div class="label">Stored</div>
            <div class="value">{{ File::humanFileSize($usage['used']) }}</div>
            <div class="hint">
                @if($usage['quota'] > 0)
                of {{ File::humanFileSize($usage['quota']) }}
                @else
                no limit set
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
                    <h6 class="mb-0">Recent files</h6>
                    <a href="{{ route('cdn-admin.files') }}" class="small text-decoration-none">All files →</a>
                </div>

                @forelse($files as $file)
                <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                    <a href="{{ route('cdn-admin.files.show', ['id' => $file['id']]) }}" class="mono small truncate text-decoration-none">
                        {{ $file['path'] }}
                    </a>
                    <span class="hint text-nowrap ms-3">
                        {{ File::humanFileSize($file['size']) }} · {{ number_format((int) $file['downloads']) }} hits
                    </span>
                </div>
                @empty
                <p class="hint mb-0">Nothing uploaded yet — the box above is where to start.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="mb-3">Your buckets</h6>

                @foreach($buckets as $bucket)
                <div class="d-flex align-items-center justify-content-between py-1">
                    <div class="truncate">
                        <a href="{{ route('cdn-admin.files') }}?bucket={{ $bucket['id'] }}" class="text-decoration-none">{{ $bucket['name'] }}</a>
                        <span class="badge text-bg-{{ $bucket['visibility'] == 'public' ? 'success' : ($bucket['visibility'] == 'signed' ? 'warning' : 'secondary') }}">
                            {{ $bucket['visibility'] }}
                        </span>
                    </div>
                    <span class="hint">{{ number_format((int) $bucket['files_count']) }}</span>
                </div>
                @endforeach

                <a href="{{ route('cdn-admin.buckets') }}" class="small text-decoration-none d-block mt-2">Manage buckets →</a>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h6 class="mb-2">Traffic, 30 days</h6>

                @if(count($series))
                <div class="spark">
                    @foreach($series as $day)
                    <div style="height: {{ max(2, round(((int) $day['requests'] / $peak) * 100)) }}%"
                         title="{{ $day['date'] }} — {{ number_format((int) $day['requests']) }} requests"></div>
                    @endforeach
                </div>
                @else
                <p class="hint mb-0">
                    Nothing summarised yet. Daily figures appear once traffic has been rolled up overnight;
                    today's numbers are in the boxes above meanwhile.
                </p>
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
