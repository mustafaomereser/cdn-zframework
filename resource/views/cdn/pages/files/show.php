@extends('cdn.main')
@section('title')<?= _l('cdn.files.show.title') ?>@endsection
@section('lede')<?= _l('cdn.files.show.lede') ?>@endsection

@section('body')
<?php $isImage = Support::isTransformable($file['mime']); ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <h6 class="mb-0 truncate notranslate" translate="no">{{ $file['name'] }}</h6>
                    <form action="{{ route('cdn-admin.files.delete', ['id' => $file['id']]) }}" method="POST"
                          data-confirm="{{ _l('cdn.files.show.confirm-delete') }}">
                        <?= csrf() ?>
                        <button class="btn btn-sm btn-outline-danger">{{ _l('cdn.common.delete') }}</button>
                    </form>
                </div>

                <div class="input-group input-group-sm mb-3">
                    <input class="form-control mono" value="{{ $url }}" readonly id="base-url">
                    <button class="btn btn-outline-secondary" data-copy="{{ $url }}"><i class="bi bi-clipboard"></i> {{ _l('cdn.common.copy') }}</button>
                    <a class="btn btn-outline-secondary" href="{{ $url }}" target="_blank"><i class="bi bi-box-arrow-up-right"></i></a>
                </div>

                @if($bucket['visibility'] != 'public')
                <div class="alert alert-warning small py-2">
                    This bucket needs signed URLs, so the link above expires in an hour. Generate fresh ones from
                    the API, or make the bucket public if the files are not sensitive.
                </div>
                @endif

                @if($isImage)
                <img src="{{ $url }}" class="img-fluid border rounded" alt="{{ $file['name'] }}" style="max-height: 380px" id="preview">
                @endif

                <dl class="row mb-0 mt-3 small">
                    <dt class="col-4 text-secondary">Stored at</dt>
                    <dd class="col-8 mono">{{ $bucket['slug'] }}/{{ $file['path'] }}</dd>

                    <dt class="col-4 text-secondary">Type &amp; size</dt>
                    <dd class="col-8">
                        {{ $file['mime'] }}, {{ File::humanFileSize($file['size']) }}
                        @if($file['width'])({{ $file['width'] }} × {{ $file['height'] }})@endif
                    </dd>

                    <dt class="col-4 text-secondary">Requests</dt>
                    <dd class="col-8">{{ number_format((int) $file['downloads']) }}, {{ File::humanFileSize($file['bytes_served']) }} sent</dd>

                    <dt class="col-4 text-secondary">Added</dt>
                    <dd class="col-8">{{ $file['created_at'] }}</dd>

                    <dt class="col-4 text-secondary">Fingerprint</dt>
                    <dd class="col-8 mono text-break" title="sha256 of the contents">{{ substr($file['hash'], 0, 24) }}…</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        @if($isImage && $bucket['transform'])
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="mb-1">{{ _l('cdn.files.show.ask') }}</h6>
                <p class="hint">Built the first time somebody asks, then cached. Nothing is stored twice by you.</p>

                <div class="row g-2 mb-2">
                    <div class="col-4">
                        <label class="form-label small mb-1">Width</label>
                        <input type="number" class="form-control form-control-sm t" data-key="w" placeholder="auto">
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-1">Height</label>
                        <input type="number" class="form-control form-control-sm t" data-key="h" placeholder="auto">
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-1">Fit</label>
                        <select class="form-select form-select-sm t" data-key="fit">
                            <option value="">contain</option>
                            <option value="cover">cover (crop)</option>
                            <option value="pad">pad</option>
                            <option value="fill">fill (stretch)</option>
                        </select>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small mb-1">Format</label>
                        <select class="form-select form-select-sm t" data-key="format">
                            <option value="">automatic</option>
                            <option value="webp">webp</option>
                            <option value="avif">avif</option>
                            <option value="jpg">jpg</option>
                            <option value="png">png</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Quality</label>
                        <input type="number" min="1" max="100" class="form-control form-control-sm t" data-key="q" placeholder="82">
                    </div>
                </div>

                <div class="input-group input-group-sm">
                    <input class="form-control mono" id="built-url" value="{{ $url }}" readonly>
                    <button class="btn btn-outline-secondary" id="copy-built"><i class="bi bi-clipboard"></i></button>
                    <a class="btn btn-outline-secondary" id="open-built" href="{{ $url }}" target="_blank"><i class="bi bi-box-arrow-up-right"></i></a>
                </div>

                <div class="hint mt-2">
                    Ready-made sizes:
                    <?php foreach ((array) config('cdn.transform.presets') as $name => $preset) : ?>
                        <a href="{{ $url }}{{ strstr($url, '?') ? '&' : '?' }}p={{ $name }}" target="_blank" class="badge text-bg-light border text-decoration-none">{{ $name }}</a>
                    <?php endforeach ?>
                </div>
            </div>
        </div>
        @endif

        <div class="card">
            <div class="card-body">
                <h6 class="mb-1">{{ _l('cdn.files.show.generated') }}</h6>
                <p class="hint">Sizes somebody has already asked for. Safe to clear — they rebuild on demand.</p>

                @forelse($variants as $variant)
                <div class="d-flex justify-content-between align-items-center py-1 border-bottom small">
                    <span class="mono">{{ $variant['width'] }}×{{ $variant['height'] }} {{ $variant['format'] }}</span>
                    <span class="text-secondary">
                        {{ File::humanFileSize($variant['size']) }} · {{ number_format((int) $variant['hits']) }} hits
                    </span>
                </div>
                @empty
                <p class="hint mb-0">None yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@section('footer')
<script>
    // The builder only assembles a query string - the server does the work when
    // the URL is first requested, so there is nothing to save or apply here.
    const base   = <?= json_encode($url) ?>;
    const output = document.getElementById('built-url');
    const open   = document.getElementById('open-built');
    const preview = document.getElementById('preview');

    const build = () => {
        const parameters = new URLSearchParams(base.split('?')[1] || '');

        document.querySelectorAll('.t').forEach(field => {
            const value = field.value.trim();
            if (value) parameters.set(field.dataset.key, value);
            else parameters.delete(field.dataset.key);
        });

        const query = parameters.toString();
        const url   = base.split('?')[0] + (query ? '?' + query : '');

        output.value = url;
        open.href    = url;
        if (preview) preview.src = url;
    };

    document.querySelectorAll('.t').forEach(field => field.addEventListener('input', build));

    document.getElementById('copy-built')?.addEventListener('click', function () {
        navigator.clipboard.writeText(output.value);

        const original = this.innerHTML;
        this.innerHTML = '<i class="bi bi-check2"></i>';
        setTimeout(() => this.innerHTML = original, 1500);
    });
</script>
@endsection
