@extends('cdn.main')
@section('title', 'Files')
@section('lede', 'Everything you have stored. Click one for its URL and the sizes you can ask for.')

@section('actions')
<a href="{{ route('cdn-admin.dashboard') }}" class="btn btn-primary btn-sm">
    <i class="bi bi-cloud-arrow-up"></i> Upload
</a>
@endsection

@section('body')

<form method="GET" class="row g-2 mb-3">
    <div class="col-md-3">
        <select name="bucket" class="form-select form-select-sm" data-autosubmit>
            <option value="">All buckets</option>
            @foreach($buckets as $bucket)
            <option value="{{ $bucket['id'] }}" {{ request('bucket') == $bucket['id'] ? 'selected' : '' }}>{{ $bucket['name'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <input name="q" class="form-control form-control-sm" placeholder="Search by path…" value="{{ request('q') ?: '' }}">
    </div>
    <div class="col-md-2">
        <button class="btn btn-outline-secondary btn-sm w-100">Search</button>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Path</th>
                    <th>Type</th>
                    <th class="text-end">Size</th>
                    <th class="text-end">Requests</th>
                    <th>Added</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($files['items'] as $file)
                <tr>
                    <td>
                        <a href="{{ route('cdn-admin.files.show', ['id' => $file['id']]) }}" class="mono text-decoration-none truncate d-block">
                            {{ $file['path'] }}
                        </a>
                    </td>
                    <td class="small text-secondary">
                        {{ $file['mime'] }}
                        @if($file['width'])<div>{{ $file['width'] }}×{{ $file['height'] }}</div>@endif
                    </td>
                    <td class="text-end">{{ File::humanFileSize($file['size']) }}</td>
                    <td class="text-end">{{ number_format((int) $file['downloads']) }}</td>
                    <td class="small text-secondary">{{ $file['created_at'] }}</td>
                    <td class="text-end">
                        <form action="{{ route('cdn-admin.files.delete', ['id' => $file['id']]) }}" method="POST"
                              data-confirm="Delete {{ $file['path'] }}? Any page using its URL will break.">
                            <?= csrf() ?>
                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center text-secondary py-4 small">
                        Nothing here. <a href="{{ route('cdn-admin.dashboard') }}">Upload something</a>.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3"><?= $files['links']('cdn.pagination') ?></div>
@endsection
