<?php

/**
 * Pagination for the panel.
 *
 * The framework's default one draws its arrows with FontAwesome, which the
 * panel does not load - so they came out as empty circles. Bootstrap Icons
 * here, and no first/last jumps: with a page count in the middle they are two
 * more targets that answer a question nobody asked.
 */
?>
<ul class="pagination pagination-sm mb-0 justify-content-center">
    <li class="page-item <?= $current_page == 1 ? 'disabled' : null ?>">
        <a href="<?= str_replace("change_page_$uniqueID", ($current_page - 1), $url) ?>" class="page-link" aria-label="Previous">
            <i class="bi bi-chevron-left"></i>
        </a>
    </li>

    <?php foreach ($pages as $page) : ?>
        <?php if ($page['type'] == 'page') : ?>
            <li class="page-item <?= $page['current'] ? 'active' : null ?>">
                <a class="page-link" href="<?= $page['url'] ?>"><?= $page['page'] ?></a>
            </li>
        <?php elseif ($page['type'] == 'dot') : ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif ?>
    <?php endforeach ?>

    <li class="page-item <?= $current_page == $page_count ? 'disabled' : null ?>">
        <a href="<?= str_replace("change_page_$uniqueID", ($current_page + 1), $url) ?>" class="page-link" aria-label="Next">
            <i class="bi bi-chevron-right"></i>
        </a>
    </li>
</ul>
