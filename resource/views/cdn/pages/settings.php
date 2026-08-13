@extends('cdn.main')
@section('title')<?= _l('cdn.settings.title') ?>@endsection
@section('lede')<?= _l('cdn.settings.lede') ?>@endsection

@section('body')

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-body">
                <h6>{{ _l('cdn.settings.projects') }}</h6>
                <p class="hint">
                    A project is a namespace in your URLs. Bucket names only have to be unique inside one, so two
                    projects can both have a bucket called <code>photos</code>.
                </p>

                @foreach($projects as $project)
                <div class="border rounded p-3 mb-2" style="border-color: var(--line) !important">
                    <form action="{{ route('cdn-admin.projects.save', ['id' => $project['id']]) }}" method="POST">
                        <?= csrf() ?>
                        <div class="input-group input-group-sm mb-2">
                            <input name="name" class="form-control" value="{{ $project['name'] }}" required>
                            <button class="btn btn-outline-secondary">{{ _l('cdn.common.rename') }}</button>
                        </div>
                    </form>

                    <div class="mono hint notranslate" translate="no">{{ rtrim(config('cdn.delivery.url-prefix'), '/') }}/{{ $project['slug'] }}/…</div>

                    <div class="d-flex justify-content-between small mt-2">
                        <span class="hint">{{ _l('cdn.settings.stored') }}</span>
                        <span>
                            {{ File::humanFileSize($project['storage_used']) }}
                            @if($project['storage_quota'] > 0)
                            <span class="hint">of {{ File::humanFileSize($project['storage_quota']) }}</span>
                            @endif
                        </span>
                    </div>

                    <div class="d-flex justify-content-between small">
                        <span class="hint">{{ _l('cdn.settings.transfer') }}</span>
                        <span>
                            {{ File::humanFileSize($project['bandwidth_used']) }}
                            @if($project['bandwidth_quota'] > 0)
                            <span class="hint">of {{ File::humanFileSize($project['bandwidth_quota']) }}</span>
                            @endif
                        </span>
                    </div>
                </div>
                @endforeach

                <form action="{{ route('cdn-admin.projects.create') }}" method="POST" class="mt-3">
                    <?= csrf() ?>
                    <label class="form-label">{{ _l('cdn.settings.add') }}</label>
                    <div class="input-group input-group-sm">
                        <input name="name" class="form-control" placeholder="{{ _l('cdn.settings.add-holder') }}" required>
                        <button class="btn btn-primary">{{ _l('cdn.common.create') }}</button>
                    </div>
                    <div class="form-text">
                        The URL name is taken from this and cannot be changed afterwards — it is in every address the
                        project serves.
                    </div>
                </form>
            </div>
        </div>

    </div>

    <div class="col-lg-6">
        <?php if ($system) : ?>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <h6 class="mb-0">{{ _l('cdn.settings.installation') }}</h6>
                        <span class="badge text-bg-primary">operator</span>
                    </div>
                    <p class="hint">What this machine can actually do — config can ask for things it cannot deliver.</p>

                    <dl class="row small mb-0">
                        <dt class="col-5 text-secondary">Image engine</dt>
                        <dd class="col-7">
                            @if($system['capabilities']['driver'] == 'none')
                            <span class="badge text-bg-danger">none</span>
                            <div class="text-secondary">No gd or imagick — resizing is skipped and originals are served.</div>
                            @else
                            <span class="badge text-bg-success">{{ $system['capabilities']['driver'] }}</span>
                            @endif
                        </dd>

                        <dt class="col-5 text-secondary">Can write</dt>
                        <dd class="col-7">
                            @foreach($system['capabilities']['formats'] as $format => $supported)
                            <span class="badge text-bg-{{ $supported ? 'success' : 'secondary' }}">{{ $format }}</span>
                            @endforeach
                        </dd>

                        <dt class="col-5 text-secondary">Shared cache</dt>
                        <dd class="col-7">
                            <span class="badge text-bg-{{ $system['capabilities']['redis'] ? 'success' : 'secondary' }}">
                                redis {{ $system['capabilities']['redis'] ? 'on' : 'off' }}
                            </span>
                            <span class="badge text-bg-{{ $system['capabilities']['apcu'] ? 'success' : 'secondary' }}">
                                apcu {{ $system['capabilities']['apcu'] ? 'on' : 'off' }}
                            </span>
                        </dd>

                        <dt class="col-5 text-secondary">Content sniffing</dt>
                        <dd class="col-7">
                            <span class="badge text-bg-{{ $system['capabilities']['finfo'] ? 'success' : 'danger' }}">
                                finfo {{ $system['capabilities']['finfo'] ? 'available' : 'missing' }}
                            </span>
                        </dd>
                    </dl>

                    <hr>

                    @foreach($system['disks'] as $name => $disk)
                    <div class="d-flex justify-content-between small">
                        <span class="mono truncate" title="{{ $disk['root'] }}">{{ $name }}</span>
                        <span>
                            @if($disk['writable'] === false)<span class="badge text-bg-danger">not writable</span>@endif
                            @if($disk['free'] !== null){{ File::humanFileSize($disk['free']) }} free @endif
                        </span>
                    </div>
                    @endforeach

                    <div class="d-flex justify-content-between small mt-1">
                        <span class="text-secondary">Generated images</span>
                        <span>{{ number_format($system['variants']['files']) }} files, {{ File::humanFileSize($system['variants']['bytes']) }}</span>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-3">{{ _l('cdn.settings.all-projects') }}</h6>

                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Project</th><th>URL</th><th class="text-end">Stored</th><th class="text-end">Transfer</th></tr></thead>
                            <tbody>
                                @foreach($system['projects'] as $row)
                                <tr>
                                    <td class="truncate">{{ $row['name'] }}</td>
                                    <td class="mono hint">/{{ $row['slug'] }}/</td>
                                    <td class="text-end">{{ File::humanFileSize($row['storage_used']) }}</td>
                                    <td class="text-end">{{ File::humanFileSize($row['bandwidth_used']) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h6 class="mb-2">{{ _l('cdn.settings.maintenance') }}</h6>
                    <p class="hint">Run from cron. <code>cron/cdn.php</code> ships with the schedule.</p>

                    <pre class="small mb-0 bg-light p-3 rounded"><code>php cdn gc        # unused files, expired uploads
php cdn rollup    # yesterday's traffic into the charts
php cdn prune     # trim the request log
php cdn verify    # check every record against the disk</code></pre>
                </div>
            </div>
        <?php else : ?>
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-2">{{ _l('cdn.settings.quotas') }}</h6>
                    <p class="hint mb-0">
                        Storage is what you have uploaded. Transfer is what visitors have downloaded this month, and
                        it resets on the first. If either fills up, uploads or delivery stop until there is room —
                        the operator of this installation can raise them.
                    </p>
                </div>
            </div>
        <?php endif ?>
    </div>
</div>
@endsection
