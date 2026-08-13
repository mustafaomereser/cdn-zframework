@extends('cdn.public')
@section('title', 'Content delivery')

@section('body')
<section class="hero py-5">
    <div class="container py-4">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <h1 class="fw-semibold mb-3">Upload a file. Get a URL that is fast everywhere.</h1>

                <p class="lead text-secondary mb-4">
                    Storage, caching and image processing behind one address. Drop in a photo and ask for any size,
                    format or crop by changing the URL — the work happens once and every request after that is a
                    cached read.
                </p>

                <div class="url-demo mono mb-4">
                    https://{{ $_SERVER['HTTP_HOST'] ?? 'cdn.example.com' }}/cdn/<b>assets</b>/photos/hero.jpg<span class="q">?w=1200&amp;fit=cover&amp;format=webp</span>
                </div>

                <div class="d-flex gap-2">
                    @if($registration)
                    <a href="{{ route('auth-form') }}#signup" class="btn btn-primary">Create an account</a>
                    <a href="{{ route('auth-form') }}" class="btn btn-outline-secondary">Sign in</a>
                    @else
                    <a href="{{ route('auth-form') }}" class="btn btn-primary">Sign in</a>
                    @endif
                </div>

                @if(!$registration)
                <p class="small text-secondary mt-3 mb-0">
                    <i class="bi bi-lock"></i> This installation is private — accounts are created by its operator.
                </p>
                @endif
            </div>

            <div class="col-lg-5">
                <div class="border rounded-3 p-3 bg-white shadow-sm">
                    <div class="small text-secondary mb-2">Same photo, three URLs</div>

                    <div class="d-flex gap-2 align-items-end">
                        <div class="text-center">
                            <div class="bg-body-secondary rounded" style="width: 56px; height: 56px"></div>
                            <div class="mono small text-secondary mt-1">?w=56</div>
                        </div>
                        <div class="text-center">
                            <div class="bg-body-secondary rounded" style="width: 96px; height: 96px"></div>
                            <div class="mono small text-secondary mt-1">?w=96</div>
                        </div>
                        <div class="text-center flex-grow-1">
                            <div class="bg-body-secondary rounded" style="height: 140px"></div>
                            <div class="mono small text-secondary mt-1">original</div>
                        </div>
                    </div>

                    <hr>

                    <div class="small text-secondary">
                        No build step, no resizing script, no second copy to keep in sync. The URL is the API.
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="container py-5">
    <div class="row g-3">
        <div class="col-md-4">
            <div class="feature">
                <i class="bi bi-images"></i>
                <h6 class="mt-2">Images on demand</h6>
                <p class="small text-secondary mb-0">
                    Width, height, crop, quality, webp or avif — set by query parameter. Each combination is built
                    once and cached; browsers that support avif get avif, the rest get what they can read.
                </p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="feature">
                <i class="bi bi-lightning-charge"></i>
                <h6 class="mt-2">Built to be cached</h6>
                <p class="small text-secondary mb-0">
                    Strong ETags, correct 304s, range requests for video and resumable downloads. A repeat visit
                    costs a few hundred bytes instead of a megabyte.
                </p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="feature">
                <i class="bi bi-shield-lock"></i>
                <h6 class="mt-2">Public or private</h6>
                <p class="small text-secondary mb-0">
                    Per-bucket rules: open to everyone, signed URLs that expire, or API-only. Plus hotlink
                    protection, so your images are not somebody else's bandwidth bill.
                </p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="feature">
                <i class="bi bi-cloud-arrow-up"></i>
                <h6 class="mt-2">Upload however you like</h6>
                <p class="small text-secondary mb-0">
                    Drag into the panel, POST from your server, hand over a URL for us to fetch, or send a large
                    file in chunks that survive a dropped connection.
                </p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="feature">
                <i class="bi bi-graph-up"></i>
                <h6 class="mt-2">You can see what happened</h6>
                <p class="small text-secondary mb-0">
                    Requests, transfer, cache hit ratio and a log of what was served, refused, or purged — with the
                    reason attached.
                </p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="feature">
                <i class="bi bi-code-slash"></i>
                <h6 class="mt-2">An API for everything</h6>
                <p class="small text-secondary mb-0">
                    Anything the panel does, a key can do: upload, list, delete, purge, sign, read usage. Scope a
                    key to one bucket and one job.
                </p>
            </div>
        </div>
    </div>
</section>
@endsection
