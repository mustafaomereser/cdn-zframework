@extends('cdn.public')
@section('title')<?= _l('cdn.auth.signin') ?>@endsection

@section('body')
<div class="container py-5" style="max-width: 440px">

    <div class="text-center mb-4">
        <h4 class="mb-1">{{ _l('cdn.auth.welcome') }}</h4>
        <div class="hint">{{ _l('cdn.auth.lede') }}</div>
    </div>

    <div class="auth-card">

    <ul class="nav nav-pills nav-fill mb-4" id="auth-tabs">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#pane-signin">{{ _l('cdn.auth.signin') }}</button>
        </li>
        @if($registration)
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-signup" id="tab-signup">{{ _l('cdn.auth.signup') }}</button>
        </li>
        @endif
    </ul>

    <?php # Server-rendered: the validator reports by putting alerts in the
          # session and redirecting here, and the public layout does not load
          # the toast library. ?>
    <?php foreach ($alerts as $alert) : ?>
        <div class="alert alert-<?= $alert[0] === 'success' ? 'success' : ($alert[0] === 'danger' ? 'danger' : 'info') ?> py-2 small">
            <?= e((string) $alert[1], false) ?>
        </div>
    <?php endforeach ?>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="pane-signin">
            <form action="{{ route('sign-in') }}" method="POST">
                <?= csrf() ?>
                <input type="hidden" name="next" value="{{ $next }}">

                <div class="mb-3">
                    <label class="form-label">{{ _l('cdn.auth.email') }}</label>
                    <input name="email" type="email" class="form-control" required autofocus>
                </div>

                <div class="mb-3">
                    <label class="form-label">{{ _l('cdn.auth.password') }}</label>
                    <input name="password" type="password" class="form-control" required>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="keep-logged-in" value="1" id="keep">
                    <label class="form-check-label small" for="keep">{{ _l('cdn.auth.keep') }}</label>
                </div>

                <button class="btn btn-primary w-100">{{ _l('cdn.auth.signin') }}</button>
            </form>
        </div>

        @if($registration)
        <div class="tab-pane fade" id="pane-signup">
            <form action="{{ route('sign-up') }}" method="POST">
                <input type="hidden" name="tab" value="signup">
                <?= csrf() ?>
                <input type="hidden" name="next" value="{{ $next }}">

                <div class="mb-3">
                    <label class="form-label">{{ _l('cdn.auth.username') }}</label>
                    <input name="username" class="form-control" required>
                    <div class="form-text">{{ _l('cdn.auth.username-help') }}</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">{{ _l('cdn.auth.email') }}</label>
                    <input name="email" type="email" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">{{ _l('cdn.auth.password') }}</label>
                    <input name="password" type="password" class="form-control" minlength="8" required>
                    <div class="form-text">{{ _l('cdn.auth.password-help') }}</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">{{ _l('cdn.auth.password2') }}</label>
                    <input name="re-password" type="password" class="form-control" minlength="8" required>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="terms" value="1" id="terms" required>
                    <label class="form-check-label small" for="terms">
                        {{ _l('cdn.auth.terms') }}
                    </label>
                </div>

                <button class="btn btn-primary w-100">{{ _l('cdn.auth.signup') }}</button>

                <p class="hint mt-3 mb-0">{{ _l('cdn.auth.signup-note') }}</p>
            </form>
        </div>
        @endif
    </div>

    </div>
</div>
@endsection

@section('footer')
<script>
    // /auth#signup opens the second tab, so the landing page can link to it.
    if (location.hash === '#signup') document.getElementById('tab-signup')?.click();

    // A wrong password lands back here on the sign-in tab; a rejected sign-up
    // should land back on the sign-up one rather than on a tab the person was
    // not looking at.
    if (document.querySelector('.alert-danger') && new URLSearchParams(location.search).get('tab') === 'signup') {
        document.getElementById('tab-signup')?.click();
    }
</script>
@endsection
