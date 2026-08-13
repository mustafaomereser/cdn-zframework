@extends('cdn.main')
@section('title', 'Bucket')
@section('lede', 'A bucket is a folder with rules. The defaults are sensible — the rest is there when you need it.')

@section('body')
<?php $referers = Support::json($bucket['referers'] ?? null); ?>

<form action="{{ route('cdn-admin.buckets.save') }}" method="POST" style="max-width: 760px">
    <?= csrf() ?>
    <?php if (isset($bucket['id'])) : ?><input type="hidden" name="id" value="<?= $bucket['id'] ?>"><?php endif ?>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Name</label>
                    <input name="name" class="form-control" value="{{ $bucket['name'] ?? '' }}" placeholder="Website images" required>
                    <div class="form-text">Only you see this.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">URL name</label>
                    <div class="input-group">
                        <span class="input-group-text mono">/cdn/</span>
                        <input name="slug" class="form-control mono" value="{{ $bucket['slug'] ?? '' }}" placeholder="images" required>
                    </div>
                    <div class="form-text">
                        @if(isset($bucket['id']))
                        Changing this breaks every URL already in use.
                        @else
                        Appears in every URL. Must be unused across the whole service.
                        @endif
                    </div>
                </div>
            </div>

            <hr>

            <label class="form-label">Who can open these URLs?</label>

            <div class="form-check">
                <input class="form-check-input" type="radio" name="visibility" value="public" id="v-public"
                       {{ ($bucket['visibility'] ?? 'public') == 'public' ? 'checked' : '' }}>
                <label class="form-check-label" for="v-public">
                    <strong>Anyone with the link</strong>
                    <div class="hint">Normal for site images, styles and downloads.</div>
                </label>
            </div>

            <div class="form-check mt-2">
                <input class="form-check-input" type="radio" name="visibility" value="signed" id="v-signed"
                       {{ ($bucket['visibility'] ?? '') == 'signed' ? 'checked' : '' }}>
                <label class="form-check-label" for="v-signed">
                    <strong>Only signed links, which expire</strong>
                    <div class="hint">For invoices, private documents, paid downloads.</div>
                </label>
            </div>

            <div class="form-check mt-2">
                <input class="form-check-input" type="radio" name="visibility" value="private" id="v-private"
                       {{ ($bucket['visibility'] ?? '') == 'private' ? 'checked' : '' }}>
                <label class="form-check-label" for="v-private">
                    <strong>Nobody — API access only</strong>
                    <div class="hint">Storage with no public door at all.</div>
                </label>
            </div>

            <hr>

            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="transform" value="1" id="transform"
                       {{ !isset($bucket['transform']) || $bucket['transform'] ? 'checked' : '' }}>
                <label class="form-check-label" for="transform">
                    <strong>Allow resizing images from the URL</strong>
                    <div class="hint">Lets <code>?w=400</code> and friends work. Turn off for buckets that hold no images.</div>
                </label>
            </div>
        </div>
    </div>

    <div class="accordion mb-3" id="advanced">
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#advanced-body">
                    Advanced
                </button>
            </h2>
            <div id="advanced-body" class="accordion-collapse collapse" data-bs-parent="#advanced">
                <div class="accordion-body">

                    <h6>Caching</h6>
                    <div class="row g-3 mb-2">
                        <div class="col-md-6">
                            <label class="form-label">Browsers may keep a copy for</label>
                            <select name="cache_ttl" class="form-select">
                                <?php foreach ([300 => '5 minutes', 3600 => '1 hour', 86400 => '1 day', 604800 => '1 week', 2592000 => '30 days', 31536000 => '1 year'] as $seconds => $label) : ?>
                                    <option value="{{ $seconds }}" {{ (int) ($bucket['cache_ttl'] ?? 31536000) === $seconds ? 'selected' : '' }}>{{ $label }}</option>
                                <?php endforeach ?>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="immutable" value="1" id="immutable"
                                       {{ !empty($bucket['immutable']) ? 'checked' : '' }}>
                                <label class="form-check-label" for="immutable">Never re-check (immutable)</label>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-light border small">
                        A long cache is faster and cheaper, but nothing you do here can reach a copy already sitting in
                        somebody's browser. If you overwrite files in place, keep this short. If your filenames change
                        when the content changes, make it a year.
                    </div>

                    <h6 class="mt-4">Hotlink protection</h6>
                    <p class="hint">Stops other sites embedding your files and spending your bandwidth.</p>

                    <select name="referer_mode" class="form-select mb-2">
                        <option value="off"   {{ ($referers['mode'] ?? 'off') == 'off' ? 'selected' : '' }}>Off — anyone may embed</option>
                        <option value="allow" {{ ($referers['mode'] ?? '') == 'allow' ? 'selected' : '' }}>Only these sites may embed</option>
                        <option value="deny"  {{ ($referers['mode'] ?? '') == 'deny' ? 'selected' : '' }}>These sites may not embed</option>
                    </select>

                    <input name="referer_list" class="form-control mono mb-2" placeholder="example.com, shop.example.com"
                           value="{{ implode(', ', (array) ($referers['list'] ?? [])) }}">

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="referer_empty" value="1" id="referer_empty"
                               {{ ($referers['allow-empty'] ?? true) ? 'checked' : '' }}>
                        <label class="form-check-label small" for="referer_empty">
                            Still allow requests that say where they came from — typing the URL directly, and most
                            privacy settings, send nothing. Turning this off blocks real people.
                        </label>
                    </div>

                    <h6 class="mt-4">Restrictions</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Largest file (bytes, 0 = default)</label>
                            <input name="max_file_size" type="number" class="form-control" value="{{ $bucket['max_file_size'] ?? 0 }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Only these extensions</label>
                            <input name="allowed_ext" class="form-control mono" placeholder="jpg, png, webp — empty for any"
                                   value="{{ implode(', ', Support::json($bucket['allowed_ext'] ?? null)) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Only these content types</label>
                            <input name="allowed_mimes" class="form-control mono" placeholder="image/*, application/pdf"
                                   value="{{ implode(', ', Support::json($bucket['allowed_mimes'] ?? null)) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Browser access from (CORS)</label>
                            <input name="cors" class="form-control mono" placeholder="* or app.example.com"
                                   value="{{ implode(', ', Support::json($bucket['cors'] ?? null)) }}">
                        </div>
                    </div>

                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" name="signed_only" value="1" id="signed_only"
                               {{ !empty($bucket['signed_only']) ? 'checked' : '' }}>
                        <label class="form-check-label small" for="signed_only">
                            Require a signature even though the bucket is public — useful to stop strangers generating
                            thousands of image sizes.
                        </label>
                    </div>

                    <h6 class="mt-4">Mirror another server</h6>
                    <p class="hint">
                        With an address here, a file nobody uploaded is fetched from there the first time it is asked
                        for, then served from here. Leave empty for a normal bucket.
                    </p>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Origin URL</label>
                            <input name="origin_url" class="form-control mono" placeholder="https://origin.example.com/assets"
                                   value="{{ $bucket['origin_url'] ?? '' }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Re-check after (seconds)</label>
                            <input name="origin_ttl" type="number" class="form-control" value="{{ $bucket['origin_ttl'] ?? 86400 }}">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-primary">Save</button>
        <a href="{{ route('cdn-admin.buckets') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endsection
