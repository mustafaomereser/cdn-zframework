@extends('cdn.main')
@section('title', 'Purges')

@section('body')
<div class="alert alert-light border small">
    A purge removes derivatives held here and changes the cache key so nothing stored can be found again. It cannot
    reach a copy already in a browser or in a cache in front of this server — only the URL changing, or a shorter
    <code>max-age</code>, does that.
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>When</th>
                    <th>Type</th>
                    <th>Target</th>
                    <th class="text-end">Files</th>
                    <th class="text-end">Derivatives</th>
                    <th class="text-end">Freed</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody>
                @forelse($purges['items'] as $purge)
                <tr>
                    <td class="small text-nowrap text-secondary">{{ $purge['created_at'] }}</td>
                    <td><span class="badge text-bg-light border">{{ $purge['type'] }}</span></td>
                    <td class="mono small truncate">{{ $purge['target'] }}</td>
                    <td class="text-end">{{ number_format((int) $purge['files']) }}</td>
                    <td class="text-end">{{ number_format((int) $purge['variants']) }}</td>
                    <td class="text-end">{{ File::humanFileSize($purge['bytes']) }}</td>
                    <td class="small text-secondary">{{ $purge['issued_by'] }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-secondary py-4 small">Nothing purged yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3"><?= $purges['links']()  ?></div>
@endsection
