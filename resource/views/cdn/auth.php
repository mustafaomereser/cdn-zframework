@extends('cdn.public')
@section('title', 'Sign in')

@section('body')
<div class="container py-5" style="max-width: 460px">

    <ul class="nav nav-pills nav-fill mb-4" id="auth-tabs">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#pane-signin">Sign in</button>
        </li>
        @if($registration)
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-signup" id="tab-signup">Create account</button>
        </li>
        @endif
    </ul>

    <div id="auth-error" class="alert alert-danger d-none py-2 small"></div>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="pane-signin">
            <form class="auth-form" action="{{ route('sign-in') }}" method="POST">
                <?= csrf() ?>
                <input type="hidden" name="next" value="{{ $next }}">

                <div class="mb-3">
                    <label class="form-label">E-mail</label>
                    <input name="email" type="email" class="form-control" required autofocus>
                </div>

                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input name="password" type="password" class="form-control" required>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="keep-logged-in" value="1" id="keep">
                    <label class="form-check-label small" for="keep">Keep me signed in</label>
                </div>

                <button class="btn btn-primary w-100">Sign in</button>
            </form>
        </div>

        @if($registration)
        <div class="tab-pane fade" id="pane-signup">
            <form class="auth-form" action="{{ route('sign-up') }}" method="POST">
                <?= csrf() ?>
                <input type="hidden" name="next" value="{{ $next }}">

                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input name="username" class="form-control" required>
                    <div class="form-text">Used to name your project. You can change the display name later.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">E-mail</label>
                    <input name="email" type="email" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input name="password" type="password" class="form-control" minlength="8" required>
                    <div class="form-text">At least 8 characters.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Password again</label>
                    <input name="re-password" type="password" class="form-control" minlength="8" required>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="terms" value="1" id="terms" required>
                    <label class="form-check-label small" for="terms">
                        I accept that what I upload is my responsibility.
                    </label>
                </div>

                <button class="btn btn-primary w-100">Create account</button>

                <p class="small text-secondary mt-3 mb-0">
                    You get a project, a first bucket and a URL to serve from, straight away.
                </p>
            </form>
        </div>
        @endif
    </div>
</div>
@endsection

@section('footer')
<script>
    // The endpoints answer json - they are the same ones the API uses - so the
    // form is submitted with fetch and the reply decides where to go. A plain
    // form post would leave the browser looking at a page of json.
    document.querySelectorAll('.auth-form').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();

            const button = form.querySelector('button[type=submit], button:not([type])');
            const error  = document.getElementById('auth-error');

            button.disabled = true;
            error.classList.add('d-none');

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                const payload = await response.json();

                if (payload.status) return window.location = payload.redirect || '/panel';

                // Validation failures come back as alerts on the payload.
                const messages = Object.values(payload.alerts || {}).map(alert => alert[1]).filter(Boolean);

                error.textContent = messages.length ? messages.join(' ') : 'Could not complete that.';
                error.classList.remove('d-none');
            } catch (thrown) {
                error.textContent = 'Network error - please try again.';
                error.classList.remove('d-none');
            }

            button.disabled = false;
        });
    });

    // /auth#signup opens the second tab, so the landing page can link to it.
    if (location.hash === '#signup') document.getElementById('tab-signup')?.click();
</script>
@endsection
