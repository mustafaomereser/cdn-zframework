<!DOCTYPE html>
<html lang="{{ zFramework\Core\Facades\Lang::$locale }}" data-bs-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.title') }} — @yield('title')</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <style>
        body { background: #fff; color: #1c1f23; }
        .hero { background: linear-gradient(180deg, #f7f9fc 0%, #fff 100%); border-bottom: 1px solid #eceff3; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .url-demo { background: #16181d; color: #d5dae0; border-radius: .5rem; padding: .9rem 1.1rem; overflow-x: auto; }
        .url-demo .q { color: #6cb6ff; }
        .feature { border: 1px solid #eceff3; border-radius: .7rem; padding: 1.25rem; height: 100%; }
        .feature i { font-size: 1.4rem; color: #0d6efd; }
        footer { border-top: 1px solid #eceff3; }
    </style>
    @yield('header')
</head>

<body>
    <nav class="navbar navbar-expand border-bottom bg-white">
        <div class="container">
            <a class="navbar-brand fw-semibold" href="/">
                <i class="bi bi-hdd-network text-primary"></i> {{ config('app.title') }}
            </a>
            <div class="ms-auto d-flex gap-2">
                @if(zFramework\Core\Facades\Auth::check())
                <a href="{{ route('cdn-admin.dashboard') }}" class="btn btn-primary btn-sm">Panel</a>
                @else
                <a href="{{ route('auth-form') }}" class="btn btn-outline-secondary btn-sm">Sign in</a>
                @endif
            </div>
        </div>
    </nav>

    @yield('body')

    <footer class="py-4 mt-5">
        <div class="container d-flex justify-content-between small text-secondary">
            <span>{{ config('app.title') }}</span>
            <span class="mono">zFramework v{{ FRAMEWORK_VERSION }} · PHP {{ PHP_VERSION }}</span>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    @yield('footer')
</body>

</html>
