@extends('cdn.main')
@section('title')<?= _l('cdn.files.title') ?>@endsection
@section('lede')<?= _l('cdn.files.lede') ?>@endsection

@section('actions')
<a href="{{ route('cdn-admin.dashboard') }}" class="btn btn-primary btn-sm">
    <i class="bi bi-cloud-arrow-up"></i> {{ _l('cdn.common.upload') }}
</a>
@endsection

@section('body')

<form method="GET" class="row g-2 mb-3">
    <div class="col-md-3">
        <select name="bucket" class="form-select form-select-sm" data-autosubmit>
            <option value="">{{ _l('cdn.files.all') }}</option>
            @foreach($buckets as $bucket)
            <option value="{{ $bucket['id'] }}" {{ request('bucket') == $bucket['id'] ? 'selected' : '' }}>{{ $bucket['name'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <input name="q" class="form-control form-control-sm" placeholder="{{ _l('cdn.files.search') }}" value="{{ request('q') ?: '' }}">
    </div>
    <div class="col-md-2">
        <button class="btn btn-outline-secondary btn-sm w-100">{{ _l('cdn.common.search') }}</button>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>{{ _l('cdn.files.path') }}</th>
                    <th>{{ _l('cdn.common.bucket') }}</th>
                    <th>{{ _l('cdn.files.type') }}</th>
                    <th class="text-end">{{ _l('cdn.files.size') }}</th>
                    <th class="text-end">{{ _l('cdn.common.requests') }}</th>
                    <th>{{ _l('cdn.files.added') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($files['items'] as $file)
                <tr>
                    <?php
                    # Where this file actually lives. A path with no bucket and
                    # no project next to it is a path in some directory
                    # somewhere, and the list was full of them.
                    $fileBucket  = Tenant::bucket((int) $file['bucket_id']);
                    $fileProject = Tenant::projectOf($fileBucket);
                    ?>
                    <td>
                        <a href="{{ route('cdn-admin.files.show', ['id' => $file['id']]) }}" class="mono text-decoration-none truncate d-block notranslate" translate="no">
                            {{ $file['path'] }}
                        </a>
                    </td>

                    <td class="small">
                        <a class="notranslate" translate="no" href="{{ route('cdn-admin.files') }}?bucket={{ $fileBucket['id'] }}">
                            {{ $fileBucket['name'] }}
                        </a>
                        <div class="hint">
                            <a class="notranslate" translate="no" href="{{ route('cdn-admin.projects.show', ['id' => $fileProject['id']]) }}">
                                {{ $fileProject['name'] }}
                            </a>
                        </div>
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
                              data-confirm="{{ _l('cdn.files.confirm-delete', ['path' => $file['path']]) }}">
                            <?= csrf() ?>
                            <button class="btn btn-sm btn-outline-danger">{{ _l('cdn.common.delete') }}</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-secondary py-4 small">
                        {{ _l('cdn.files.empty') }} <a href="{{ route('cdn-admin.dashboard') }}">{{ _l('cdn.files.empty-action') }}</a>.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3"><?= $files['links']('cdn.pagination') ?></div>
@endsection
