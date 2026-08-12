@extends('cdn.main')
@section('title', 'Dashboard')

@section('body')
<?php


$hitRatio = ($totals['hits'] + $totals['misses']) > 0
    ? round($totals['hits'] / ($totals['hits'] + $totals['misses']) * 100, 1)
    : null;

$peak = max(1, max(array_map(fn($day) => (int) $day['requests'], $series ?: [['requests' => 0]])));
?>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat">
            <div class="label">Transfer, 30 days</div>
            <div class="value">{{ File::humanFileSize($totals['bytes']) }}</div>
            <div class="small text-secondary">{{ number_format($totals['requests']) }} requests</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat">
            <div class="label">Cache hit ratio</div>
            <div class="value">{{ $hitRatio === null ? '—' : $hitRatio . '%' }}</div>
            <div class="small text-secondary">
                {{ number_format($totals['hits']) }} hit / {{ number_format($totals['misses']) }} miss
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat">
            <div class="label">Stored</div>
            <div class="value">{{ File::humanFileSize($project['storage_used']) }}</div>
            <div class="small text-secondary">
                @if($project['storage_quota'] > 0)
                of {{ File::humanFileSize($project['storage_quota']) }} quota
                @else
                no quota set
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat">
            <div class="label">Derivatives</div>
            <div class="value">{{ number_format((int) ($variants['count'] ?? 0)) }}</div>
            <div class="small text-secondary">{{ File::humanFileSize((int) ($variants['bytes'] ?? 0)) }} on disk</div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-baseline mb-3">
            <h6 class="mb-0">Requests per day</h6>
            <span class="small text-secondary">
                Today so far: {{ number_format((int) ($today['requests'] ?? 0)) }} requests,
                {{ File::humanFileSize((int) ($today['bytes'] ?? 0)) }}
            </span>
        </div>

        @if(count($series))
        <div class="spark" title="Daily request count">
            @foreach($series as $day)
            <div style="height: {{ max(2, round(((int) $day['requests'] / $peak) * 100)) }}%"
                 title="{{ $day['date'] }} — {{ number_format((int) $day['requests']) }} requests"></div>
            @endforeach
        </div>
        <div class="d-flex justify-content-between small text-secondary mt-1">
            <span>{{ $series[0]['date'] }}</span>
            <span>{{ end($series)['date'] }}</span>
        </div>
        @else
        <p class="text-secondary mb-0 small">
            Nothing rolled up yet. The daily rollup runs from
            <code>php terminal cdn rollup</code> — today's traffic is in the counter above until it does.
        </p>
        @endif
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="mb-3">Buckets</h6>

                @forelse($buckets as $bucket)
                <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                    <div>
                        <a href="{{ route('cdn-admin.files') }}?bucket={{ $bucket['id'] }}" class="fw-medium text-decoration-none">
                            {{ $bucket['name'] }}
                        </a>
                        <div class="small text-secondary mono">
                            /{{ $bucket['slug'] }}
                            <span class="badge text-bg-{{ $bucket['visibility'] == 'public' ? 'success' : ($bucket['visibility'] == 'signed' ? 'warning' : 'secondary') }}">
                                {{ $bucket['visibility'] }}
                            </span>
                            @if($bucket['origin_url'])<span class="badge text-bg-info">origin pull</span>@endif
                        </div>
                    </div>
                    <div class="text-end small">
                        <div>{{ File::humanFileSize($bucket['storage_used']) }}</div>
                        <div class="text-secondary">{{ number_format((int) $bucket['files_count']) }} files</div>
                    </div>
                </div>
                @empty
                <p class="text-secondary small mb-0">
                    No buckets yet. <a href="{{ route('cdn-admin.buckets.create') }}">Create one</a> — a bucket is the
                    first path segment of every URL it serves.
                </p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="mb-3">Most requested</h6>

                @forelse($popular as $file)
                <div class="d-flex align-items-center justify-content-between py-1">
                    <a href="{{ route('cdn-admin.files.show', ['id' => $file['id']]) }}" class="truncate small text-decoration-none mono">
                        {{ $file['path'] }}
                    </a>
                    <span class="small text-secondary ms-2">{{ number_format((int) $file['downloads']) }}</span>
                </div>
                @empty
                <p class="text-secondary small mb-0">Nothing served yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
