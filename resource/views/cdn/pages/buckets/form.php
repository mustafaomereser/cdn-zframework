@extends('cdn.main')
@section('title', 'Bucket')

@section('body')
<?php


$referers = Support::json($bucket['referers'] ?? null);
$value    = fn($key, $default = '') => e($bucket[$key] ?? $default, false);
?>

<form action="{{ route('cdn-admin.buckets.save') }}" method="POST">
    <?= csrf()  ?>
    <?php if (isset($bucket['id'])) : ?><input type="hidden" name="id" value="<?= $bucket['id'] ?>"><?php endif ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-3">Identity</h6>

                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input name="name" class="form-control" value="{{ $bucket['name'] ?? '' }}" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Slug</label>
                        <input name="slug" class="form-control mono" value="{{ $bucket['slug'] ?? '' }}" required>
                        <div class="form-text">
                            The first path segment of every URL in this bucket. Changing it breaks every existing link.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Visibility</label>
                        <select name="visibility" class="form-select">
                            <option value="public" {{ ($bucket['visibility'] ?? 'public') == 'public' ? 'selected' : '' }}>Public — anyone with the URL</option>
                            <option value="signed" {{ ($bucket['visibility'] ?? '') == 'signed' ? 'selected' : '' }}>Signed — a valid signature required</option>
                            <option value="private" {{ ($bucket['visibility'] ?? '') == 'private' ? 'selected' : '' }}>Private — API only, never served publicly</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-3">Caching</h6>

                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">max-age (seconds)</label>
                            <input name="cache_ttl" type="number" class="form-control" value="{{ $bucket['cache_ttl'] ?? 31536000 }}">
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="immutable" value="1" id="immutable"
                                       {{ !empty($bucket['immutable']) ? 'checked' : '' }}>
                                <label class="form-check-label" for="immutable">Immutable</label>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-light border small mt-3 mb-0">
                        <strong>Immutable</strong> tells a browser never to revalidate — correct when the URL contains a
                        content hash, wrong when you intend to overwrite the same path. A purge cannot reach a copy that
                        is already in somebody's browser; only a new URL, or a short max-age, can.
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h6 class="mb-3">Origin pull</h6>

                    <div class="row g-3">
                        <div class="col-8">
                            <label class="form-label">Origin URL</label>
                            <input name="origin_url" class="form-control mono" placeholder="https://origin.example.com/assets"
                                   value="{{ $bucket['origin_url'] ?? '' }}">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Refetch after (s)</label>
                            <input name="origin_ttl" type="number" class="form-control" value="{{ $bucket['origin_ttl'] ?? 86400 }}">
                        </div>
                    </div>

                    <div class="form-text mt-2">
                        With this set, a miss is fetched from upstream and stored — nobody has to upload anything.
                        Leave empty for an ordinary bucket.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-3">Access</h6>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="transform" value="1" id="transform"
                               {{ !isset($bucket['transform']) || $bucket['transform'] ? 'checked' : '' }}>
                        <label class="form-check-label" for="transform">Allow image transforms</label>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="signed_only" value="1" id="signed_only"
                               {{ !empty($bucket['signed_only']) ? 'checked' : '' }}>
                        <label class="form-check-label" for="signed_only">Require a signature even when public</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Hotlink protection</label>
                        <select name="referer_mode" class="form-select mb-2">
                            <option value="off"   {{ ($referers['mode'] ?? 'off') == 'off' ? 'selected' : '' }}>Off</option>
                            <option value="allow" {{ ($referers['mode'] ?? '') == 'allow' ? 'selected' : '' }}>Allow only these referers</option>
                            <option value="deny"  {{ ($referers['mode'] ?? '') == 'deny' ? 'selected' : '' }}>Deny these referers</option>
                        </select>
                        <input name="referer_list" class="form-control mono" placeholder="example.com, cdn.example.com"
                               value="{{ implode(', ', (array) ($referers['list'] ?? [])) }}">
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="referer_empty" value="1" id="referer_empty"
                                   {{ ($referers['allow-empty'] ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label small" for="referer_empty">
                                Allow requests with no Referer — direct navigation and most privacy settings send none
                            </label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">CORS origins</label>
                        <input name="cors" class="form-control mono" placeholder="* or example.com, app.example.com"
                               value="{{ implode(', ', App\Cdn\Support::json($bucket['cors'] ?? null)) }}">
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-3">Limits</h6>

                    <div class="mb-3">
                        <label class="form-label">Max file size (bytes, 0 = default)</label>
                        <input name="max_file_size" type="number" class="form-control" value="{{ $bucket['max_file_size'] ?? 0 }}">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Allowed extensions</label>
                        <input name="allowed_ext" class="form-control mono" placeholder="jpg, png, webp — empty for any"
                               value="{{ implode(', ', App\Cdn\Support::json($bucket['allowed_ext'] ?? null)) }}">
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Allowed mime types</label>
                        <input name="allowed_mimes" class="form-control mono" placeholder="image/*, application/pdf"
                               value="{{ implode(', ', App\Cdn\Support::json($bucket['allowed_mimes'] ?? null)) }}">
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary">Save</button>
                <a href="{{ route('cdn-admin.buckets') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </div>
</form>
@endsection
