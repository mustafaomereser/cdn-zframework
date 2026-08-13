<!DOCTYPE html>
<html lang="{{ zFramework\Core\Facades\Lang::$locale }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') · {{ config('app.title') }}</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ asset('/assets/css/cdn.css') }}">
    <style>body { background: var(--surface); }</style>
    @yield('header')
</head>

<body>
    <nav class="public-nav">
        <div class="container d-flex align-items-center justify-content-between">
            <a class="brand" href="/"><i class="bi bi-hdd-network"></i> {{ config('app.title') }}</a>

            <div class="d-flex align-items-center gap-3">
                <a href="{{ route('docs') }}" class="hint">Docs</a>

                @if(zFramework\Core\Facades\Auth::check())
                <a href="{{ route('cdn-admin.dashboard') }}" class="btn btn-primary btn-sm">Open panel</a>
                @else
                <a href="{{ route('auth-form') }}" class="btn btn-outline-secondary btn-sm">Sign in</a>
                @endif
            </div>
        </div>
    </nav>

    @yield('body')

    <footer class="site py-4 mt-5">
        <div class="container d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="hint">{{ config('app.title') }}</span>
            <span class="hint mono">zFramework v{{ FRAMEWORK_VERSION }} · PHP {{ PHP_VERSION }}</span>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    @yield('footer')
</body>

</html>
