@extends('cdn.main')
@section('title', 'API Keys')

@section('body')

<?php if (isset($created)) : ?>
<div class="alert alert-success">
    <h6 class="alert-heading">Key created</h6>
    <p class="small mb-2">
        This is the only time the secret is shown. It is stored hashed, so it cannot be recovered — if it is lost,
        revoke the key and issue another.
    </p>

    <div class="input-group input-group-sm mb-2">
        <span class="input-group-text" style="width: 110px">Access key</span>
        <input class="form-control mono" value="{{ $created['access'] }}" readonly>
        <button class="btn btn-outline-secondary" data-copy="{{ $created['access'] }}">Copy</button>
    </div>

    <div class="input-group input-group-sm mb-0">
        <span class="input-group-text" style="width: 110px">Secret</span>
        <input class="form-control mono" value="{{ $created['secret'] }}" readonly>
        <button class="btn btn-outline-secondary" data-copy="{{ $created['secret'] }}">Copy</button>
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
                            <th>Name</th>
                            <th>Access key</th>
                            <th>Scopes</th>
                            <th class="text-end">Requests</th>
                            <th>Last used</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($keys as $key)
                        <tr class="{{ $key['status'] != 'active' ? 'opacity-50' : '' }}">
                            <td>
                                {{ $key['name'] }}
                                @if($key['status'] != 'active')<span class="badge text-bg-secondary">{{ $key['status'] }}</span>@endif
                                @if($key['expires_at'])<div class="small text-secondary">expires {{ $key['expires_at'] }}</div>@endif
                            </td>
                            <td class="mono small">{{ $key['access_key'] }}</td>
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
                                      data-confirm="Revoke this key? Anything using it stops working immediately.">
                                    <?= csrf()  ?>
                                    <button class="btn btn-sm btn-outline-danger">Revoke</button>
                                </form>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="text-center text-secondary py-4 small">No keys yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h6 class="mb-3">New key</h6>

                <form action="{{ route('cdn-admin.keys.create') }}" method="POST">
                    <?= csrf()  ?>

                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input name="name" class="form-control form-control-sm" placeholder="Deploy pipeline" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Scopes</label>
                        <?php foreach (['read' => 'Read and list', 'upload' => 'Upload', 'delete' => 'Delete', 'purge' => 'Purge', 'admin' => 'Everything'] as $scope => $label) : ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="scopes[]" value="{{ $scope }}"
                                       id="scope-{{ $scope }}" {{ $scope == 'read' ? 'checked' : '' }}>
                                <label class="form-check-label small" for="scope-{{ $scope }}">{{ $label }}</label>
                            </div>
                        <?php endforeach ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Buckets</label>
                        <select name="buckets[]" class="form-select form-select-sm" multiple size="4">
                            @foreach($buckets as $bucket)
                            <option value="{{ $bucket['id'] }}">{{ $bucket['name'] }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Select none for every bucket in the project.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Allowed addresses</label>
                        <input name="allowed_ips" class="form-control form-control-sm mono" placeholder="1.2.3.4, 10.0.0.0/8">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Expires</label>
                        <input name="expires_at" type="datetime-local" class="form-control form-control-sm">
                    </div>

                    <button class="btn btn-primary btn-sm w-100">Create key</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
