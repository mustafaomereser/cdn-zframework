@extends('cdn.main')
@section('title', 'Files')

@section('body')
<div class="card mb-3">
    <div class="card-body">
        <form action="{{ route('cdn-admin.files.upload') }}" method="POST" enctype="multipart/form-data" class="row g-2 align-items-end">
            <?= csrf()  ?>

            <div class="col-md-3">
                <label class="form-label small mb-1">Bucket</label>
                <select name="bucket" class="form-select form-select-sm" required>
                    @foreach($buckets as $bucket)
                    <option value="{{ $bucket['id'] }}">{{ $bucket['name'] }} (/{{ $bucket['slug'] }})</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label small mb-1">Files</label>
                <input type="file" name="files[]" class="form-control form-control-sm" multiple>
            </div>

            <div class="col-md-3">
                <label class="form-label small mb-1">…or a URL to fetch</label>
                <input name="url" class="form-control form-control-sm mono" placeholder="https://…">
            </div>

            <div class="col-md-2">
                <label class="form-label small mb-1">Path prefix</label>
                <input name="path" class="form-control form-control-sm mono" placeholder="images/2026">
            </div>

            <div class="col-md-1">
                <button class="btn btn-primary btn-sm w-100">Upload</button>
            </div>
        </form>
    </div>
</div>

<form method="GET" class="row g-2 mb-3">
    <div class="col-md-3">
        <select name="bucket" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All buckets</option>
            @foreach($buckets as $bucket)
            <option value="{{ $bucket['id'] }}" {{ request('bucket') == $bucket['id'] ? 'selected' : '' }}>{{ $bucket['name'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <input name="q" class="form-control form-control-sm" placeholder="Search path…" value="{{ request('q') ?: '' }}">
    </div>
    <div class="col-md-1">
        <button class="btn btn-outline-secondary btn-sm w-100">Filter</button>
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
                    <th class="text-end">Served</th>
                    <th>Uploaded</th>
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
                    <td class="text-end">{{ File::humanFileSize($file['bytes_served']) }}</td>
                    <td class="small text-secondary">{{ $file['created_at'] }}</td>
                    <td class="text-end">
                        <form action="{{ route('cdn-admin.files.delete', ['id' => $file['id']]) }}" method="POST"
                              data-confirm="Delete {{ $file['path'] }}?">
                            <?= csrf()  ?>
                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-secondary py-4 small">No files.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3"><?= $files['links']()  ?></div>
@endsection
