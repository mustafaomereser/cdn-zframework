@extends('cdn.main')
@section('title', 'Activity')
@section('lede', 'What was served, what was refused and why, and what you have cleared.')

@section('body')

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-requests">Requests</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-purges">Cache clears</button></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="pane-requests">

        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-3">
                <select name="bucket" class="form-select form-select-sm" data-autosubmit>
                    <option value="">All buckets</option>
                    @foreach($buckets as $bucket)
                    <option value="{{ $bucket['id'] }}" {{ request('bucket') == $bucket['id'] ? 'selected' : '' }}>{{ $bucket['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select name="cache" class="form-select form-select-sm" data-autosubmit>
                    <option value="">Anything</option>
                    <option value="hit"         {{ request('cache') == 'hit' ? 'selected' : '' }}>Served from cache</option>
                    <option value="miss"        {{ request('cache') == 'miss' ? 'selected' : '' }}>Read from disk</option>
                    <option value="transformed" {{ request('cache') == 'transformed' ? 'selected' : '' }}>Image generated</option>
                    <option value="pulled"      {{ request('cache') == 'pulled' ? 'selected' : '' }}>Fetched from origin</option>
                    <option value="denied"      {{ request('cache') == 'denied' ? 'selected' : '' }}>Refused</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-center">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="errors" value="1" id="errors"
                           data-autosubmit {{ request('errors') ? 'checked' : '' }}>
                    <label class="form-check-label small" for="errors">Problems only</label>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>When</th>
                            <th>Path</th>
                            <th>Result</th>
                            <th class="text-end">Sent</th>
                            <th class="text-end">ms</th>
                            <th>From</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs['items'] as $log)
                        <tr>
                            <td class="small text-nowrap text-secondary">{{ $log['created_at'] }}</td>
                            <td class="mono small truncate" title="{{ $log['path'] }}">{{ $log['path'] }}</td>
                            <td class="small text-nowrap">
                                <span class="badge text-bg-{{ $log['status'] < 300 ? 'success' : ($log['status'] < 400 ? 'info' : ($log['status'] < 500 ? 'warning' : 'danger')) }}">
                                    {{ $log['status'] }}
                                </span>
                                <span class="text-secondary">{{ $log['cache'] }}</span>
                            </td>
                            <td class="text-end small">{{ File::humanFileSize($log['bytes']) }}</td>
                            <td class="text-end small">{{ $log['duration'] }}</td>
                            <td class="small text-secondary truncate" title="{{ $log['agent'] }}">
                                {{ $log['ip'] }}
                                @if($log['referer'])<div class="truncate">← {{ $log['referer'] }}</div>@endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4 small">
                                Nothing recorded yet.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3"><?= $logs['links']('cdn.pagination') ?></div>

        <p class="hint mt-2">
            A refused request says why in the path column — an expired signature, a blocked referer, a rate limit.
        </p>
    </div>

    <div class="tab-pane fade" id="pane-purges">
        <div class="alert alert-light border small">
            Clearing a bucket removes the image sizes generated from it and makes every future request build fresh
            ones. It cannot reach copies already sitting in visitors' browsers — only time, or a different URL, does
            that.
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>When</th>
                            <th>What</th>
                            <th class="text-end">Versions removed</th>
                            <th class="text-end">Freed</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($purges as $purge)
                        <tr>
                            <td class="small text-nowrap text-secondary">{{ $purge['created_at'] }}</td>
                            <td class="small">
                                <span class="badge text-bg-light border">{{ $purge['type'] }}</span>
                                <span class="mono">{{ $purge['target'] }}</span>
                            </td>
                            <td class="text-end">{{ number_format((int) $purge['variants']) }}</td>
                            <td class="text-end">{{ File::humanFileSize($purge['bytes']) }}</td>
                            <td class="small text-secondary">{{ $purge['issued_by'] }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4 small">Nothing cleared yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
