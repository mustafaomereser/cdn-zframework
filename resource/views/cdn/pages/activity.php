@extends('cdn.main')
@section('title')<?= _l('cdn.activity.title') ?>@endsection
@section('lede')<?= _l('cdn.activity.lede') ?>@endsection

@section('body')

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-requests">{{ _l('cdn.activity.requests') }}</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-purges">{{ _l('cdn.activity.purges') }}</button></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="pane-requests">

        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-3">
                <select name="bucket" class="form-select form-select-sm" data-autosubmit>
                    <option value="">{{ _l('cdn.files.all') }}</option>
                    @foreach($buckets as $bucket)
                    <option value="{{ $bucket['id'] }}" {{ request('bucket') == $bucket['id'] ? 'selected' : '' }}>{{ $bucket['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select name="cache" class="form-select form-select-sm" data-autosubmit>
                    <option value="">{{ _l('cdn.activity.anything') }}</option>
                    <option value="hit"         {{ request('cache') == 'hit' ? 'selected' : '' }}>{{ _l('cdn.activity.cache.hit') }}</option>
                    <option value="miss"        {{ request('cache') == 'miss' ? 'selected' : '' }}>{{ _l('cdn.activity.cache.miss') }}</option>
                    <option value="transformed" {{ request('cache') == 'transformed' ? 'selected' : '' }}>{{ _l('cdn.activity.cache.transformed') }}</option>
                    <option value="pulled"      {{ request('cache') == 'pulled' ? 'selected' : '' }}>{{ _l('cdn.activity.cache.pulled') }}</option>
                    <option value="denied"      {{ request('cache') == 'denied' ? 'selected' : '' }}>{{ _l('cdn.activity.cache.denied') }}</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-center">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="errors" value="1" id="errors"
                           data-autosubmit {{ request('errors') ? 'checked' : '' }}>
                    <label class="form-check-label small" for="errors">{{ _l('cdn.activity.errors-only') }}</label>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>{{ _l('cdn.activity.when') }}</th>
                            <th>{{ _l('cdn.files.path') }}</th>
                            <th>{{ _l('cdn.activity.result') }}</th>
                            <th class="text-end">{{ _l('cdn.activity.sent') }}</th>
                            <th class="text-end">ms</th>
                            <th>{{ _l('cdn.activity.from') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs['items'] as $log)
                        <tr>
                            <td class="small text-nowrap text-secondary">{{ $log['created_at'] }}</td>
                            <td class="mono small truncate notranslate" translate="no" title="{{ $log['path'] }}">{{ $log['path'] }}</td>
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
                                {{ _l('cdn.activity.empty') }}
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3"><?= $logs['links']('cdn.pagination') ?></div>

        <p class="hint mt-2">
            {{ _l('cdn.activity.note') }}
        </p>
    </div>

    <div class="tab-pane fade" id="pane-purges">
        <div class="alert alert-light border small">
            {{ _l('cdn.activity.purge-note') }}
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>{{ _l('cdn.activity.when') }}</th>
                            <th>{{ _l('cdn.activity.what') }}</th>
                            <th class="text-end">{{ _l('cdn.activity.removed') }}</th>
                            <th class="text-end">{{ _l('cdn.activity.freed') }}</th>
                            <th>{{ _l('cdn.activity.by') }}</th>
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
                        <tr><td colspan="5" class="text-center text-secondary py-4 small">{{ _l('cdn.activity.purge-empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
