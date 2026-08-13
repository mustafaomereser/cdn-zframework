@extends('cdn.main')
@section('title', 'Buckets')
@section('lede', 'A bucket is a folder with rules. Its name is the first part of every URL it serves.')

@section('actions')
<a href="{{ route('cdn-admin.buckets.create') }}" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg"></i> New bucket
</a>
@endsection

@section('body')
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Bucket</th>
                    <th>Visibility</th>
                    <th>Cache</th>
                    <th class="text-end">Files</th>
                    <th class="text-end">Stored</th>
                    <th class="text-end">Served</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($buckets as $bucket)
                <tr>
                    <td>
                        <div class="fw-medium">{{ $bucket['name'] }}</div>
                        <div class="small text-secondary mono">
                            {{ rtrim(config('cdn.delivery.url-prefix'), '/') }}/{{ $bucket['slug'] }}/…
                        </div>
                        @if($bucket['origin_url'])
                        <div class="small text-info mono">↳ {{ $bucket['origin_url'] }}</div>
                        @endif
                    </td>
                    <td>
                        <span class="badge text-bg-{{ $bucket['visibility'] == 'public' ? 'success' : ($bucket['visibility'] == 'signed' ? 'warning' : 'secondary') }}">
                            {{ $bucket['visibility'] }}
                        </span>
                        @if($bucket['transform'])<span class="badge text-bg-light border">transform</span>@endif
                    </td>
                    <td class="small">
                        {{ $bucket['cache_ttl'] > 0 ? round($bucket['cache_ttl'] / 3600, 1) . ' h' : 'no-store' }}
                        @if($bucket['immutable'])<div class="text-secondary">immutable</div>@endif
                        <div class="text-secondary">v{{ $bucket['cache_version'] }}</div>
                    </td>
                    <td class="text-end">{{ number_format((int) $bucket['files_count']) }}</td>
                    <td class="text-end">{{ File::humanFileSize($bucket['storage_used']) }}</td>
                    <td class="text-end">{{ File::humanFileSize($bucket['bandwidth_used']) }}</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('cdn-admin.buckets.edit', ['id' => $bucket['id']]) }}" class="btn btn-sm btn-outline-secondary">Edit</a>

                        <form action="{{ route('cdn-admin.buckets.purge', ['id' => $bucket['id']]) }}" method="POST" class="d-inline"
                              data-confirm="Delete every derivative in this bucket and bump its cache version?">
                            <?= csrf()  ?>
                            <button class="btn btn-sm btn-outline-warning">Purge</button>
                        </form>

                        <form action="{{ route('cdn-admin.buckets.delete', ['id' => $bucket['id']]) }}" method="POST" class="d-inline"
                              data-confirm="Delete this bucket and every file in it? This cannot be undone.">
                            <?= csrf()  ?>
                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-secondary py-4 small">
                        No buckets yet.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
