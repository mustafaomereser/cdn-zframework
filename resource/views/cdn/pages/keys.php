@extends('cdn.main')
@section('title')<?= _l('cdn.keys.title') ?>@endsection
@section('lede')<?= _l('cdn.keys.lede') ?>@endsection

@section('body')
<?php $base = host() . rtrim((string) config('cdn.api.route'), '/') . '/v1'; ?>

<?php if (isset($created)) : ?>
<div class="alert alert-success">
    <h6 class="alert-heading">{{ _l('cdn.keys.created') }}</h6>
    <p class="small mb-2">
        {{ _l('cdn.keys.created-note') }}
    </p>

    <div class="input-group input-group-sm mb-2">
        <span class="input-group-text" style="width: 110px">{{ _l('cdn.keys.access') }}</span>
        <input class="form-control mono" value="{{ $created['access'] }}" readonly>
        <button class="btn btn-outline-secondary" data-copy="{{ $created['access'] }}">{{ _l('cdn.common.copy') }}</button>
    </div>

    <div class="input-group input-group-sm mb-0">
        <span class="input-group-text" style="width: 110px">{{ _l('cdn.keys.secret') }}</span>
        <input class="form-control mono" value="{{ $created['secret'] }}" readonly>
        <button class="btn btn-outline-secondary" data-copy="{{ $created['secret'] }}">{{ _l('cdn.common.copy') }}</button>
    </div>
</div>
<?php endif ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>{{ _l('cdn.keys.name') }}</th>
                            <th>{{ _l('cdn.common.project') }}</th>
                            <th>{{ _l('cdn.keys.access') }}</th>
                            <th>{{ _l('cdn.keys.scopes') }}</th>
                            <th class="text-end">{{ _l('cdn.common.requests') }}</th>
                            <th>{{ _l('cdn.keys.last-used') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($keys as $key)
                        <tr class="{{ $key['status'] != 'active' ? 'opacity-50' : '' }}">
                            <td>
                                {{ $key['name'] }}
                                @if($key['status'] != 'active')<span class="badge text-bg-secondary">{{ $key['status'] }}</span>@endif
                                @if($key['expires_at'])<div class="small text-secondary">{{ _l('cdn.keys.expires-at', ['date' => $key['expires_at']]) }}</div>@endif
                            </td>
                            <td class="small notranslate" translate="no">{{ App\Cdn\Tenant::project($key['project_id'])['name'] }}</td>
                            <td class="mono small notranslate" translate="no">{{ $key['access_key'] }}</td>
                            <td class="small">
                                @foreach(App\Cdn\Support::json($key['scopes']) ?: ['read'] as $scope)
                                <span class="badge text-bg-light border">{{ $scope }}</span>
                                @endforeach
                            </td>
                            <td class="text-end">{{ number_format((int) $key['requests']) }}</td>
                            <td class="small text-secondary">{{ $key['last_used_at'] ?: '—' }}</td>
                            <td class="text-end">
                                @if($key['status'] == 'active')
                                <form action="{{ route('cdn-admin.keys.revoke', ['id' => $key['id']]) }}" method="POST"
                                      data-confirm="{{ _l('cdn.keys.confirm-revoke') }}">
                                    <?= csrf()  ?>
                                    <button class="btn btn-sm btn-outline-danger">{{ _l('cdn.common.revoke') }}</button>
                                </form>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="text-center text-secondary py-4 small">{{ _l('cdn.keys.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h6 class="mb-3">{{ _l('cdn.keys.new') }}</h6>

                <form action="{{ route('cdn-admin.keys.create') }}" method="POST">
                    <?= csrf()  ?>

                    <div class="mb-3">
                        <label class="form-label">{{ _l('cdn.keys.name') }}</label>
                        <input name="name" class="form-control form-control-sm" placeholder="{{ _l('cdn.keys.name-holder') }}" required>
                    </div>

                    <?php if (count($projects) > 1) : ?>
                        <div class="mb-3">
                            <label class="form-label">{{ _l('cdn.common.project') }}</label>
                            <select name="project" class="form-select form-select-sm">
                                @foreach($projects as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ _l('cdn.keys.project-help') }}</div>
                        </div>
                    <?php endif ?>

                    <div class="mb-3">
                        <label class="form-label">{{ _l('cdn.keys.scopes') }}</label>
                        <?php foreach (['read', 'upload', 'delete', 'purge'] as $scope) : ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="scopes[]" value="{{ $scope }}"
                                       id="scope-{{ $scope }}" {{ $scope == 'read' ? 'checked' : '' }}>
                                <label class="form-check-label small" for="scope-{{ $scope }}">{{ _l('cdn.keys.scope.' . $scope) }}</label>
                            </div>
                        <?php endforeach ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">{{ _l('cdn.keys.buckets') }}</label>
                        <?php # An empty select2 multi-select renders as a blank box; the
                              # placeholder is what tells you the empty state means
                              # "everything" rather than "broken". ?>
                        <select name="buckets[]" class="form-select form-select-sm" multiple
                                data-placeholder="{{ _l('cdn.keys.buckets-any') }}">
                            @foreach($buckets as $bucket)
                            <option value="{{ $bucket['id'] }}">{{ $bucket['name'] }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">{{ _l('cdn.keys.buckets-help') }}</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">{{ _l('cdn.keys.addresses') }}</label>
                        <input name="allowed_ips" class="form-control form-control-sm mono" placeholder="1.2.3.4, 10.0.0.0/8">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">{{ _l('cdn.keys.expires') }}</label>
                        <input name="expires_at" type="datetime-local" class="form-control form-control-sm">
                    </div>

                    <button class="btn btn-primary btn-sm w-100">{{ _l('cdn.keys.create') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <h6>{{ _l('cdn.keys.code') }}</h6>
        <p class="hint">
            <?= _l('cdn.keys.code-lede') ?>
        </p>

        <label class="form-label small mb-1">{{ _l('cdn.keys.api-address') }}</label>
        <div class="input-group input-group-sm mb-3" style="max-width: 520px">
            <input class="form-control mono" value="{{ $base }}" readonly>
            <button class="btn btn-outline-secondary" data-copy="{{ $base }}"><i class="bi bi-clipboard"></i> {{ _l('cdn.common.copy') }}</button>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="label mb-2">{{ _l('cdn.keys.code-upload') }}</div>
                <pre class="small bg-light p-3 rounded mb-0"><code>curl -X POST {{ $base }}/files \
  -H "X-Cdn-Key: cdn_..." \
  -H "X-Cdn-Secret: ..." \
  -F bucket=your-bucket \
  -F path=photos/hero.jpg \
  -F file=@hero.jpg</code></pre>
            </div>

            <div class="col-lg-6">
                <div class="label mb-2">{{ _l('cdn.keys.code-response') }}</div>
                <pre class="small bg-light p-3 rounded mb-0"><code>{
  "ok": true,
  "files": [{
    "path": "photos/hero.jpg",
    "size": 384022,
    "url": "…/cdn/&lt;project&gt;/your-bucket/photos/hero.jpg"
  }]
}</code></pre>
            </div>
        </div>

        <p class="hint mt-3 mb-0">
            <?= _l('cdn.keys.code-more', ['docs' => '<a href="' . route('docs') . '" target="_blank">' . _l('cdn.keys.docs') . '</a>']) ?>
        </p>
    </div>
</div>
@endsection
