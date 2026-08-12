@extends('cdn.main')
@section('title', 'Access Log')

@section('body')
<form method="GET" class="row g-2 mb-3">
    <div class="col-md-3">
        <select name="bucket" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All buckets</option>
            @foreach($buckets as $bucket)
            <option value="{{ $bucket['id'] }}" {{ request('bucket') == $bucket['id'] ? 'selected' : '' }}>{{ $bucket['name'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <select name="cache" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">Any outcome</option>
            <?php foreach (['hit', 'miss', 'transformed', 'pulled', 'denied', 'bypass'] as $state) : ?>
                <option value="{{ $state }}" {{ request('cache') == $state ? 'selected' : '' }}>{{ $state }}</option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="col-md-2">
        <input name="status" class="form-control form-control-sm" placeholder="Status" value="{{ request('status') ?: '' }}">
    </div>
    <div class="col-md-1">
        <button class="btn btn-outline-secondary btn-sm w-100">Filter</button>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Time</th>
                    <th>Path</th>
                    <th>Status</th>
                    <th>Outcome</th>
                    <th class="text-end">Bytes</th>
                    <th class="text-end">ms</th>
                    <th>Client</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs['items'] as $log)
                <tr>
                    <td class="small text-nowrap text-secondary">{{ $log['created_at'] }}</td>
                    <td class="mono small truncate">{{ $log['path'] }}</td>
                    <td>
                        <span class="badge text-bg-{{ $log['status'] < 300 ? 'success' : ($log['status'] < 400 ? 'info' : ($log['status'] < 500 ? 'warning' : 'danger')) }}">
                            {{ $log['status'] }}
                        </span>
                    </td>
                    <td class="small">{{ $log['cache'] }}</td>
                    <td class="text-end small">{{ File::humanFileSize($log['bytes']) }}</td>
                    <td class="text-end small">{{ $log['duration'] }}</td>
                    <td class="small text-secondary truncate" title="{{ $log['agent'] }}">
                        {{ $log['ip'] }}
                        @if($log['referer'])<div class="truncate">← {{ $log['referer'] }}</div>@endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-secondary py-4 small">
                        Nothing logged. Check <code>cdn.logging.enabled</code>, and note the sample rate.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3"><?= $logs['links']()  ?></div>
@endsection
