@extends('cdn.main')
@section('title')<?= _l('cdn.operator.console') ?>@endsection
@section('lede')<?= _l('cdn.operator.console-lede') ?>@endsection

@section('body')
<?php include(BASE_PATH . '/resource/views/cdn/partials/operator-nav.php') ?>

<div class="alert alert-warning small">
    <?= _l('cdn.console.warning', ['timeout' => $timeout]) ?>
</div>

<div class="card">
    <div class="card-body">

        <form id="console-form" class="row g-2 align-items-center mb-3">
            <?= csrf() ?>

            <div class="col-auto">
                <select name="script" id="console-script" class="form-select form-select-sm notranslate" translate="no" style="width: 130px">
                    <option value="cdn">php cdn</option>
                    <option value="terminal">php terminal</option>
                </select>
            </div>

            <div class="col">
                <input name="command" id="console-command" class="form-control form-control-sm mono notranslate"
                       translate="no" autocomplete="off" spellcheck="false"
                       placeholder="{{ _l('cdn.console.holder') }}">
            </div>

            <div class="col-auto">
                <button class="btn btn-sm btn-primary" id="console-run">{{ _l('cdn.console.run') }}</button>
            </div>
        </form>

        <?php # What each script offers, read from the script itself. Clicking
              # one fills the box rather than running it - a command list is a
              # reminder of what exists, not a row of triggers. ?>
        <?php foreach ($scripts as $script => $commands) : ?>
            <div class="console-allow mb-2" data-for="<?= $script ?>">
                <span class="hint small me-1">{{ _l('cdn.console.commands') }}</span>
                <?php foreach ($commands as $command) : ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 mb-1 mono notranslate" translate="no"
                            data-fill="<?= e((string) $command, false) ?>"><?= e((string) $command, false) ?></button>
                <?php endforeach ?>
                <?php if (!count($commands)) : ?>
                    <span class="hint small">{{ _l('cdn.console.no-commands') }}</span>
                <?php endif ?>
            </div>
        <?php endforeach ?>

        <pre class="console-out mono notranslate" translate="no" id="console-out"></pre>
    </div>
</div>
@endsection

@section('footer')
<script>
    (function () {
        const form   = document.getElementById('console-form');
        const script = document.getElementById('console-script');
        const input  = document.getElementById('console-command');
        const out    = document.getElementById('console-out');
        const run    = document.getElementById('console-run');
        const url    = <?= json_encode(route('cdn-admin.operator.console.run')) ?>;

        function escapeHtml(text) {
            const box = document.createElement('div');
            box.textContent = text;
            return box.innerHTML;
        }

        function write(html) {
            out.insertAdjacentHTML('beforeend', html);
            out.scrollTop = out.scrollHeight;
        }

        // Only the selected script's allowlist.
        function showAllowed() {
            document.querySelectorAll('.console-allow').forEach(function (row) {
                row.style.display = row.dataset.for === script.value ? '' : 'none';
            });
        }

        script.addEventListener('change', showAllowed);
        showAllowed();

        document.querySelectorAll('[data-fill]').forEach(function (button) {
            button.addEventListener('click', function () {
                input.value = button.dataset.fill + ' ';
                input.focus();
            });
        });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            const line = (script.value + ' ' + input.value).trim();

            run.disabled = true;

            // The typed line is the operator's own text, so it is escaped here.
            // The output is escaped on the server, where the colour markup is
            // put back afterwards.
            write('<span class="console-echo">$ php ' + escapeHtml(line) + '</span>\n');

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                const payload = await response.json();

                write((payload.output || '').replace(/\s+$/, '')
                    + '\n<span class="console-exit">' + <?= json_encode(_l('cdn.console.exit')) ?>
                    + ' ' + (payload.code === null ? '—' : payload.code) + '</span>\n\n');
            } catch (thrown) {
                write('<span class="console-exit">' + <?= json_encode(_l('cdn.console.failed')) ?> + '</span>\n\n');
            }

            run.disabled = false;
        });
    })();
</script>
@endsection
