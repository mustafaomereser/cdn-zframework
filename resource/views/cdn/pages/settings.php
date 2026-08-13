@extends('cdn.main')
@section('title', 'Settings')

@section('body')
<div class="alert alert-light border small">
    Settings live in <code>config/cdn.php</code> and are read per request — edit the file, no restart. What is shown
    here is what this machine can actually do, which is the half that config cannot promise.
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="mb-3">Image processing</h6>

                <dl class="row small mb-0">
                    <dt class="col-5 text-secondary">Driver</dt>
                    <dd class="col-7">
                        @if($capabilities['driver'] == 'none')
                        <span class="badge text-bg-danger">none</span>
                        <div class="text-secondary">No gd or imagick — transforms are skipped and originals are served.</div>
                        @else
                        <span class="badge text-bg-success">{{ $capabilities['driver'] }}</span>
                        @endif
                    </dd>

                    <dt class="col-5 text-secondary">Output formats</dt>
                    <dd class="col-7">
                        @foreach($capabilities['formats'] as $format => $supported)
                        <span class="badge text-bg-{{ $supported ? 'success' : 'secondary' }}">{{ $format }}</span>
                        @endforeach
                    </dd>
                </dl>

                @if(!$capabilities['formats']['webp'] || !$capabilities['formats']['avif'])
                <div class="small text-secondary mt-3">
                    A format listed in <code>transform.formats</code> that this build cannot write is simply never
                    chosen — <code>?format=avif</code> then serves the original rather than failing.
                </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h6 class="mb-3">Extensions</h6>

                <dl class="row small mb-0">
                    <dt class="col-5 text-secondary">APCu</dt>
                    <dd class="col-7">
                        <span class="badge text-bg-{{ $capabilities['apcu'] ? 'success' : 'secondary' }}">
                            {{ $capabilities['apcu'] ? 'available' : 'missing' }}
                        </span>
                        <div class="text-secondary">Bucket lookups and rate limiting, per server.</div>
                    </dd>

                    <dt class="col-5 text-secondary">Redis</dt>
                    <dd class="col-7">
                        <span class="badge text-bg-{{ $capabilities['redis'] ? 'success' : 'secondary' }}">
                            {{ $capabilities['redis'] ? 'connected' : 'off' }}
                        </span>
                        <div class="text-secondary">Needed for shared limits across more than one server.</div>
                    </dd>

                    <dt class="col-5 text-secondary">finfo</dt>
                    <dd class="col-7">
                        <span class="badge text-bg-{{ $capabilities['finfo'] ? 'success' : 'danger' }}">
                            {{ $capabilities['finfo'] ? 'available' : 'missing' }}
                        </span>
                        <div class="text-secondary">Content sniffing on upload. Without it the client's word is taken.</div>
                    </dd>

                    <dt class="col-5 text-secondary">gzip</dt>
                    <dd class="col-7">
                        <span class="badge text-bg-{{ $capabilities['gzip'] ? 'success' : 'secondary' }}">
                            {{ $capabilities['gzip'] ? 'available' : 'missing' }}
                        </span>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="mb-3">Storage</h6>

                @foreach($disks as $name => $disk)
                <div class="mb-3">
                    <div class="d-flex justify-content-between">
                        <span class="fw-medium">{{ $name }}</span>
                        <span class="small">
                            @if($disk['writable'] === null)
                            <span class="badge text-bg-warning">not created yet</span>
                            @elseif($disk['writable'])
                            <span class="badge text-bg-success">writable</span>
                            @else
                            <span class="badge text-bg-danger">not writable</span>
                            @endif
                        </span>
                    </div>
                    <div class="small text-secondary mono text-break">{{ $disk['root'] }}</div>
                    @if($disk['free'] !== null)
                    <div class="small text-secondary">{{ File::humanFileSize($disk['free']) }} free</div>
                    @endif
                </div>
                @endforeach

                <hr>

                <div class="d-flex justify-content-between small">
                    <span class="text-secondary">Derivative cache</span>
                    <span>
                        {{ number_format($variants['files']) }} files, {{ File::humanFileSize($variants['bytes']) }}
                        @if(config('cdn.transform.cache.max-size'))
                        / {{ File::humanFileSize(config('cdn.transform.cache.max-size')) }} cap
                        @endif
                    </span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h6 class="mb-3">Maintenance</h6>

                <p class="small text-secondary">
                    Run these from cron. <code>cron/cdn.php</code> ships with the schedule.
                </p>

                <pre class="small mb-0 bg-light p-3 rounded"><code>php cdn gc        # orphans, expired uploads, eviction
php cdn rollup    # yesterday into cdn_stats
php cdn prune     # trim the access log
php cdn verify    # every row against the disk</code></pre>
            </div>
        </div>
    </div>
</div>
@endsection
