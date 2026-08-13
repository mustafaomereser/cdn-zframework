@extends('cdn.public')
@section('title')<?= _l('cdn.home.title') ?>@endsection

@section('body')
<?php $host = $_SERVER['HTTP_HOST'] ?? 'cdn.example.com'; ?>

<section class="hero py-5">
    <div class="container py-4">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <h1 class="mb-3">{{ _l('cdn.home.headline') }}</h1>

                <p class="lead mb-4">{{ _l('cdn.home.lede') }}</p>

                <div class="url-demo mb-4">
                    https://<?= $host ?>/cdn/<b>your-project</b>/<b>photos</b>/hero.jpg<span class="q">?w=1200&amp;fit=cover&amp;format=webp</span>
                </div>

                <?php # The page no longer redirects a signed-in visitor to the panel,
                      # so it has to stop asking them to sign up for what they
                      # already have. ?>
                <div class="d-flex gap-2 flex-wrap">
                    @if(zFramework\Core\Facades\Auth::check())
                    <a href="{{ route('cdn-admin.dashboard') }}" class="btn btn-primary">{{ _l('cdn.home.panel') }}</a>
                    <a href="{{ route('docs') }}" class="btn btn-outline-secondary">{{ _l('cdn.home.docs') }}</a>
                    @elseif($registration)
                    <a href="{{ route('auth-form') }}#signup" class="btn btn-primary">{{ _l('cdn.home.signup') }}</a>
                    <a href="{{ route('auth-form') }}" class="btn btn-outline-secondary">{{ _l('cdn.home.signin') }}</a>
                    @else
                    <a href="{{ route('auth-form') }}" class="btn btn-primary">{{ _l('cdn.home.signin') }}</a>
                    @endif
                </div>

                @if(!$registration && !zFramework\Core\Facades\Auth::check())
                <p class="hint mt-3 mb-0">
                    <i class="bi bi-lock"></i> {{ _l('cdn.home.private') }}
                </p>
                @endif
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-body">
                        <div class="label mb-3">{{ _l('cdn.home.demo') }}</div>

                        <?php foreach ([
                            [_l('cdn.home.demo-1'), '?w=80',               22],
                            [_l('cdn.home.demo-2'), '?w=400&amp;fit=cover', 55],
                            [_l('cdn.home.demo-3'), '',                     100],
                        ] as [$caption, $query, $width]) : ?>
                            <div class="size-demo">
                                <div class="track"><div class="bar" style="width: <?= $width ?>%"></div></div>
                                <span class="caption"><?= $caption ?></span>
                            </div>
                            <div class="mono notranslate" translate="no">…/hero.jpg<?= $query ?></div>
                        <?php endforeach ?>

                        <hr style="border-color: var(--line)">

                        <p class="hint mb-0">{{ _l('cdn.home.demo-note') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

@if(!zFramework\Core\Facades\Auth::check())
<section class="container py-5">
    <div class="row g-4 align-items-start">
        <?php # Shown to visitors who have not signed up; a signed-in reader has
              # done all three already. ?>
        <?php foreach ((array) _l('cdn.home.steps') as $index => [$title, $text]) : ?>
            <div class="col-md-4">
                <div class="step">
                    <div class="n"><?= $index + 1 ?></div>
                    <div>
                        <h6><?= $title ?></h6>
                        <p><?= $text ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach ?>
    </div>
</section>
@endif

<section class="container pb-5">
    <h2 class="section-title">{{ _l('cdn.home.features-title') }}</h2>
    <p class="hint mb-4">{{ _l('cdn.home.features-lede') }}</p>

    <div class="row g-3">
        <?php
        # The icons stay here and the words come from the language file, so a
        # translator never has to know what a bootstrap icon name is.
        $icons = ['bi-images', 'bi-lightning-charge', 'bi-shield-lock', 'bi-cloud-arrow-up', 'bi-graph-up', 'bi-code-slash'];

        foreach ((array) _l('cdn.home.features') as $index => [$title, $text]) :
            $icon = $icons[$index] ?? 'bi-dot';
        ?>
            <div class="col-md-4">
                <div class="feature">
                    <div class="icon"><i class="bi <?= $icon ?>"></i></div>
                    <h6><?= $title ?></h6>
                    <p><?= $text ?></p>
                </div>
            </div>
        <?php endforeach ?>
    </div>
</section>
@endsection
