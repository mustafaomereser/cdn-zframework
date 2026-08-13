@extends('cdn.public')
@section('title')<?= _l('cdn.language.title', ['language' => $name]) ?>@endsection

@section('body')
<div class="container py-5" style="max-width: 520px">

    <div class="auth-card text-center">

        <div class="build-mark"><i class="bi bi-translate"></i></div>

        <h5 class="mb-1 notranslate" translate="no"><?= $name ?></h5>
        <p class="hint mb-4">{{ _l('cdn.language.lede') }}</p>

        <div class="progress build-bar mb-2" role="progressbar">
            <div class="progress-bar" id="build-bar" style="width: 0%"></div>
        </div>

        <div class="d-flex justify-content-between small mb-4">
            <span class="hint" id="build-state">{{ _l('cdn.language.working') }}</span>
            <span class="hint mono" id="build-count"><?= $progress['done'] ?> / <?= $progress['total'] ?></span>
        </div>

        <div id="build-error" class="alert alert-warning small d-none mb-4">
            {{ _l('cdn.language.failed') }}
        </div>

        <p class="hint small mb-3">{{ _l('cdn.language.note') }}</p>

        <a href="<?= htmlspecialchars($next) ?>" class="btn btn-outline-secondary btn-sm" id="build-cancel">
            {{ _l('cdn.language.cancel') }}
        </a>

        <form id="build-form" class="d-none"><?= csrf() ?></form>
    </div>

</div>
@endsection

@section('footer')
<script>
    // One chunk per request. The server decides how big a chunk is; this only
    // keeps asking until it says it is done.
    //
    // Sequential on purpose - two of these in flight would translate the same
    // strings twice, and the server's lock would make the second one a wasted
    // round trip anyway.
    (function () {
        const url    = <?= json_encode(route('language.build', ['lang' => $code])) ?>;
        const done   = <?= json_encode(route('language', ['lang' => $code]) . '?next=' . urlencode($next)) ?>;
        const form   = document.getElementById('build-form');
        const bar    = document.getElementById('build-bar');
        const count  = document.getElementById('build-count');
        const state  = document.getElementById('build-state');
        const error  = document.getElementById('build-error');
        const texts  = <?= json_encode([
                            'working' => _l('cdn.language.working'),
                            'ready'   => _l('cdn.language.ready'),
                            'retry'   => _l('cdn.language.retry'),
                        ], JSON_UNESCAPED_UNICODE) ?>;

        let misses = 0;

        function draw(payload) {
            const percent = payload.total ? Math.round((payload.done / payload.total) * 100) : 0;

            bar.style.width  = percent + '%';
            count.textContent = payload.done + ' / ' + payload.total;
        }

        async function step() {
            let payload;

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                payload = await response.json();
            } catch (thrown) {
                payload = null;
            }

            // A chunk that translated nothing, or a request that did not come
            // back at all. The service rate limits by address, so the answer is
            // to wait rather than to ask harder.
            if (!payload || payload.stalled) {
                error.classList.remove('d-none');
                state.textContent = texts.retry;

                if (++misses > 8) return;

                return setTimeout(step, 4000 * misses);
            }

            misses = 0;
            error.classList.add('d-none');
            state.textContent = texts.working;

            draw(payload);

            // Another tab - or another visitor - is building this one. Watch
            // their progress rather than duplicating it.
            if (payload.waiting) return setTimeout(step, 1500);

            if (payload.ready) {
                state.textContent = texts.ready;
                bar.style.width   = '100%';

                // Straight on to where they were going, now in their language.
                return window.location = done;
            }

            step();
        }

        step();
    })();
</script>
@endsection
