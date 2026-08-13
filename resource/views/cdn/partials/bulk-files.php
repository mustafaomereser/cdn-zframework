<?php

/**
 * The bar that appears when files are ticked.
 *
 * Shared by the panel's file list and the operator's, because the two lists do
 * the same two things to a selection and a second copy is a second thing to fix.
 *
 * It is a form of its own rather than one wrapping the table: the table is
 * inside a filter form on both pages, and a form inside a form is not a form.
 * The checkboxes are collected into it on submit instead.
 *
 * @var string $bulkAction  Route to post to.
 * @var array  $bulkTargets Buckets to offer, grouped by project name. Empty
 *                          hides the move control entirely.
 */

$bulkTargets ??= [];
?>

<div class="bulk-bar" id="bulk-bar" hidden>
    <form method="POST" action="<?= $bulkAction ?>" id="bulk-form" class="d-flex align-items-center gap-2 flex-wrap">
        <?= csrf() ?>
        <input type="hidden" name="action" value="delete" id="bulk-action">

        <span class="count"><b id="bulk-count">0</b> <?= _l('cdn.files.selected') ?></span>

        <?php if (count($bulkTargets)) : ?>
            <select name="target" class="form-select form-select-sm" style="max-width: 260px" data-plain>
                <option value=""><?= _l('cdn.files.move-to') ?></option>
                <?php foreach ($bulkTargets as $bulkProject => $bulkList) : ?>
                    <?php if (!count($bulkList)) continue ?>
                    <optgroup label="<?= e((string) $bulkProject, false) ?>">
                        <?php foreach ($bulkList as $bulkBucket) : ?>
                            <option value="<?= $bulkBucket['id'] ?>"><?= e($bulkBucket['name'], false) ?></option>
                        <?php endforeach ?>
                    </optgroup>
                <?php endforeach ?>
            </select>

            <button class="btn btn-sm btn-outline-secondary" data-bulk="move" disabled id="bulk-move">
                <i class="bi bi-arrow-right-square"></i> <?= _l('cdn.files.move') ?>
            </button>
        <?php endif ?>

        <button class="btn btn-sm btn-outline-danger" data-bulk="delete"
                data-confirm="<?= e(_l('cdn.files.delete-confirm'), false) ?>">
            <i class="bi bi-trash"></i> <?= _l('cdn.common.delete') ?>
        </button>

        <button type="button" class="btn btn-sm btn-link" id="bulk-clear"><?= _l('cdn.files.clear-selection') ?></button>

        <?php # The move changes every URL these files have. Said before, not
              # after - there is no redirect left behind. ?>
        <span class="hint small ms-auto"><?= _l('cdn.files.move-note') ?></span>
    </form>
</div>

<script>
    (function () {
        const bar    = document.getElementById('bulk-bar');
        const form   = document.getElementById('bulk-form');
        const action = document.getElementById('bulk-action');
        const count  = document.getElementById('bulk-count');
        const move   = document.getElementById('bulk-move');
        const target = form.querySelector('[name=target]');

        const boxes = () => Array.from(document.querySelectorAll('.file-pick'));
        const ticked = () => boxes().filter(box => box.checked);

        function refresh() {
            const n = ticked().length;

            count.textContent = n;
            bar.hidden = n === 0;

            if (move) move.disabled = !target.value;

            const all = document.getElementById('pick-all');
            if (all) {
                all.checked = n > 0 && n === boxes().length;
                all.indeterminate = n > 0 && n < boxes().length;
            }
        }

        document.addEventListener('change', function (event) {
            if (event.target.id === 'pick-all') {
                boxes().forEach(box => { box.checked = event.target.checked; });
            }

            if (event.target.classList.contains('file-pick') || event.target.id === 'pick-all' || event.target === target) refresh();
        });

        document.getElementById('bulk-clear')?.addEventListener('click', function () {
            boxes().forEach(box => { box.checked = false; });
            refresh();
        });

        // Which button was pressed decides the verb.
        form.querySelectorAll('[data-bulk]').forEach(function (button) {
            button.addEventListener('click', function () { action.value = button.dataset.bulk; });
        });

        form.addEventListener('submit', function (event) {
            const picked = ticked();

            if (!picked.length) return event.preventDefault();

            if (action.value === 'move' && !target.value) return event.preventDefault();

            // The checkboxes live in the table, which is inside the filter
            // form. They are copied in here rather than moved, so the page is
            // unchanged if the submit is cancelled by the confirm below.
            form.querySelectorAll('.bulk-id').forEach(node => node.remove());

            picked.forEach(function (box) {
                const hidden = document.createElement('input');

                hidden.type      = 'hidden';
                hidden.name      = 'files[]';
                hidden.value     = box.value;
                hidden.className = 'bulk-id';

                form.appendChild(hidden);
            });
        });

        refresh();
    })();
</script>
