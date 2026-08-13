@extends('cdn.public')
@section('title', 'Content delivery')

@section('body')
<section class="hero py-5">
    <div class="container py-4">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <h1 class="mb-3">Upload a file. Get a URL that is fast everywhere.</h1>

                <p class="lead mb-4">
                    Storage, caching and image processing behind one address. Drop in a photo and ask for any size,
                    format or crop by changing the URL — the work happens once, every request after is a cached read.
                </p>

                <div class="url-demo mb-4">
                    https://{{ $_SERVER['HTTP_HOST'] ?? 'cdn.example.com' }}/cdn/<b>photos</b>/hero.jpg<span class="q">?w=1200&amp;fit=cover&amp;format=webp</span>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                    @if($registration)
                    <a href="{{ route('auth-form') }}#signup" class="btn btn-primary">Create an account</a>
                    <a href="{{ route('auth-form') }}" class="btn btn-outline-secondary">Sign in</a>
                    @else
                    <a href="{{ route('auth-form') }}" class="btn btn-primary">Sign in</a>
                    @endif
                </div>

                @if(!$registration)
                <p class="hint mt-3 mb-0">
                    <i class="bi bi-lock"></i> This installation is private — accounts are created by its operator.
                </p>
                @endif
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-body">
                        <div class="label mb-3">One file, three URLs</div>

                        <?php foreach ([['?w=80', '80px', 34], ['?w=400&amp;fit=cover', '400px, cropped', 62], ['', 'original', 100]] as [$query, $caption, $width]) : ?>
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <div style="width: <?= $width ?>%; height: 26px; border-radius: 5px;
                                            background: linear-gradient(90deg, var(--surface-3), var(--line));"></div>
                                <span class="hint text-nowrap"><?= $caption ?></span>
                            </div>
                            <div class="mono hint mb-3" style="font-size: .75rem">…/hero.jpg<?= $query ?></div>
                        <?php endforeach ?>

                        <hr class="my-3" style="border-color: var(--line)">

                        <p class="hint mb-0">
                            No build step, no resizing script, no second copy to keep in sync. The URL is the API.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="container py-5">
    <div class="row g-3">
        <?php
        $features = [
            ['bi-images', 'Images on demand', 'Width, height, crop, quality, webp or avif — set by query parameter. Each combination is built once and cached; browsers that read avif get avif, the rest get what they can.'],
            ['bi-lightning-charge', 'Built to be cached', 'Strong ETags, correct 304s, range requests for video and resumable downloads. A repeat visit costs a few hundred bytes instead of a megabyte.'],
            ['bi-shield-lock', 'Public or private', 'Per bucket: open to everyone, signed URLs that expire, or API-only. Plus hotlink protection, so your images are not somebody else\'s bandwidth bill.'],
            ['bi-cloud-arrow-up', 'Upload however you like', 'Drag into the panel, POST from your server, hand over a URL for us to fetch, or send a large file in chunks that survive a dropped connection.'],
            ['bi-graph-up', 'You can see what happened', 'Requests, transfer, cache hit ratio, and a log of what was served or refused — with the reason attached.'],
            ['bi-code-slash', 'An API for everything', 'Anything the panel does, a key can do: upload, list, delete, purge, sign, read usage. Scope a key to one bucket and one job.'],
        ];

        foreach ($features as [$icon, $title, $text]) : ?>
            <div class="col-md-4">
                <div class="feature">
                    <i class="bi <?= $icon ?>"></i>
                    <h6><?= $title ?></h6>
                    <p><?= $text ?></p>
                </div>
            </div>
        <?php endforeach ?>
    </div>
</section>
@endsection
