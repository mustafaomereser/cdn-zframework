@extends('cdn.main')
@section('title')<?= _l('cdn.buckets.title') ?>@endsection
@section('lede')<?= _l('cdn.buckets.lede') ?>@endsection

@section('actions')
<a href="{{ route('cdn-admin.buckets.create') }}" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg"></i> {{ _l('cdn.buckets.new') }}
</a>
@endsection

@section('body')
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>{{ _l('cdn.common.bucket') }}</th>
                    <th>{{ _l('cdn.common.project') }}</th>
                    <th>{{ _l('cdn.buckets.form.who') }}</th>
                    <th>{{ _l('cdn.buckets.cache') }}</th>
                    <th class="text-end">{{ _l('cdn.common.files') }}</th>
                    <th class="text-end">{{ _l('cdn.settings.stored') }}</th>
                    <th class="text-end">{{ _l('cdn.buckets.served') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($buckets as $bucket)
                <tr>
                    <?php $bucketProject = Tenant::projectOf($bucket); ?>
                    <td>
                        <a class="fw-medium notranslate" translate="no" href="{{ route('cdn-admin.files') }}?bucket={{ $bucket['id'] }}">
                            {{ $bucket['name'] }}
                        </a>
                        <div class="small text-secondary mono notranslate" translate="no">
                            {{ rtrim(config('cdn.delivery.url-prefix'), '/') }}/{{ $bucketProject['slug'] }}/{{ $bucket['slug'] }}/…
                        </div>
                        @if($bucket['origin_url'])
                        <div class="small text-info mono">↳ {{ $bucket['origin_url'] }}</div>
                        @endif
                    </td>

                    <?php # Which project this belongs to, and a way into it. A
                          # bucket list that does not say is a list of names
                          # somebody has to already know the answer for. ?>
                    <td class="small">
                        <a class="notranslate" translate="no" href="{{ route('cdn-admin.projects.show', ['id' => $bucketProject['id']]) }}">
                            {{ $bucketProject['name'] }}
                        </a>
                    </td>
                    <td>
                        <span class="badge text-bg-{{ $bucket['visibility'] == 'public' ? 'success' : ($bucket['visibility'] == 'signed' ? 'warning' : 'secondary') }}">
                            {{ _l('cdn.visibility.' . $bucket['visibility']) }}
                        </span>
                        @if($bucket['transform'])<span class="badge text-bg-light border">transform</span>@endif
                    </td>
                    <td class="small">
                        {{ $bucket['cache_ttl'] > 0 ? round($bucket['cache_ttl'] / 3600, 1) . ' h' : _l('cdn.buckets.no-store') }}
                        @if($bucket['immutable'])<div class="text-secondary">{{ _l('cdn.buckets.immutable') }}</div>@endif
                        <div class="text-secondary">v{{ $bucket['cache_version'] }}</div>
                    </td>
                    <td class="text-end">{{ number_format((int) $bucket['files_count']) }}</td>
                    <td class="text-end">{{ File::humanFileSize($bucket['storage_used']) }}</td>
                    <td class="text-end">{{ File::humanFileSize($bucket['bandwidth_used']) }}</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('cdn-admin.buckets.edit', ['id' => $bucket['id']]) }}" class="btn btn-sm btn-outline-secondary">{{ _l('cdn.common.edit') }}</a>

                        <form action="{{ route('cdn-admin.buckets.purge', ['id' => $bucket['id']]) }}" method="POST" class="d-inline"
                              data-confirm="{{ _l('cdn.buckets.confirm-purge') }}">
                            <?= csrf()  ?>
                            <button class="btn btn-sm btn-outline-warning">{{ _l('cdn.common.purge') }}</button>
                        </form>

                        <form action="{{ route('cdn-admin.buckets.delete', ['id' => $bucket['id']]) }}" method="POST" class="d-inline"
                              data-confirm="{{ _l('cdn.buckets.confirm-delete') }}">
                            <?= csrf()  ?>
                            <button class="btn btn-sm btn-outline-danger">{{ _l('cdn.common.delete') }}</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="text-center text-secondary py-4 small">
                        {{ _l('cdn.buckets.empty') }}
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
