@extends('cdn.main')
@section('title', 'File')

@section('body')
<?php


$isImage = Support::isTransformable($file['mime']);
?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="mb-3">{{ $file['name'] }}</h6>

                <div class="input-group input-group-sm mb-3">
                    <input class="form-control mono" value="{{ $url }}" readonly>
                    <button class="btn btn-outline-secondary" data-copy="{{ $url }}">Copy</button>
                </div>

                @if($isImage)
                <img src="{{ $url }}" class="img-fluid border rounded" alt="{{ $file['name'] }}" style="max-height: 420px">
                @endif

                <dl class="row mb-0 mt-3 small">
                    <dt class="col-4 text-secondary">Path</dt>
                    <dd class="col-8 mono">{{ $file['path'] }}</dd>

                    <dt class="col-4 text-secondary">Bucket</dt>
                    <dd class="col-8">{{ $bucket['name'] }} <span class="mono">/{{ $bucket['slug'] }}</span></dd>

                    <dt class="col-4 text-secondary">Type</dt>
                    <dd class="col-8">{{ $file['mime'] }}</dd>

                    <dt class="col-4 text-secondary">Size</dt>
                    <dd class="col-8">{{ File::humanFileSize($file['size']) }}</dd>

                    @if($file['width'])
                    <dt class="col-4 text-secondary">Dimensions</dt>
                    <dd class="col-8">{{ $file['width'] }} × {{ $file['height'] }}</dd>
                    @endif

                    <dt class="col-4 text-secondary">sha256</dt>
                    <dd class="col-8 mono text-break">{{ $file['hash'] }}</dd>

                    <dt class="col-4 text-secondary">ETag</dt>
                    <dd class="col-8 mono">{{ $file['etag'] }}</dd>

                    <dt class="col-4 text-secondary">Requests</dt>
                    <dd class="col-8">{{ number_format((int) $file['downloads']) }}, {{ File::humanFileSize($file['bytes_served']) }} served</dd>

                    <dt class="col-4 text-secondary">Uploaded</dt>
                    <dd class="col-8">{{ $file['created_at'] }} @if($file['uploaded_by'])<span class="text-secondary">by {{ $file['uploaded_by'] }}</span>@endif</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        @if($isImage && $bucket['transform'])
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="mb-3">Transforms</h6>

                <div class="small text-secondary mb-2">
                    Append parameters to the URL. Each distinct combination is built once and cached.
                </div>

                <?php foreach ((array) config('cdn.transform.presets') as $name => $preset) : ?>
                    <?php $presetUrl = $url . (strstr($url, '?') ? '&' : '?') . 'p=' . $name; ?>
                    <div class="input-group input-group-sm mb-2">
                        <span class="input-group-text mono" style="width: 90px">{{ $name }}</span>
                        <input class="form-control mono" value="{{ $presetUrl }}" readonly>
                        <a class="btn btn-outline-secondary" href="{{ $presetUrl }}" target="_blank">Open</a>
                    </div>
                <?php endforeach ?>

                <div class="small text-secondary mt-3">
                    <code>?w=</code> <code>?h=</code> <code>?fit=cover|contain|fill|pad</code> <code>?q=</code>
                    <code>?format=webp|avif|jpg|png</code> <code>?dpr=</code> <code>?crop=x,y,w,h</code>
                    <code>?rotate=</code> <code>?flip=</code> <code>?blur=</code> <code>?gray=1</code>
                </div>
            </div>
        </div>
        @endif

        <div class="card">
            <div class="card-body">
                <h6 class="mb-3">Cached derivatives</h6>

                @forelse($variants as $variant)
                <div class="d-flex justify-content-between align-items-center py-1 border-bottom small">
                    <span class="mono truncate">
                        {{ $variant['width'] }}×{{ $variant['height'] }} {{ $variant['format'] }}
                    </span>
                    <span class="text-secondary">
                        {{ File::humanFileSize($variant['size']) }} · {{ number_format((int) $variant['hits']) }} hits · {{ $variant['build_ms'] }} ms
                    </span>
                </div>
                @empty
                <p class="small text-secondary mb-0">None built yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
