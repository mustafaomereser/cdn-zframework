<?php

/**
 * The tabs across the operator pages.
 *
 * One file rather than a copy per page: they are the only navigation between
 * four pages that are otherwise unrelated, and a tab that exists on three of
 * them is worse than no tab at all.
 */

use App\Cdn\Runner;

$tabs = [
    'cdn-admin.operator.users'    => ['bi-people',   'users'],
    'cdn-admin.operator.projects' => ['bi-boxes',    'projects'],
    'cdn-admin.operator.system'   => ['bi-cpu',      'system'],
    'cdn-admin.operator.audits'   => ['bi-list-ul',  'audits'],
];

if (Runner::enabled()) $tabs['cdn-admin.operator.console'] = ['bi-terminal', 'console'];

$path = uri();
?>

<ul class="nav nav-pills op-tabs mb-3">
    <?php foreach ($tabs as $route => [$icon, $key]) :
        $target = parse_url(route($route), PHP_URL_PATH);
        $active = rtrim($path, '/') === rtrim($target, '/');
    ?>
        <li class="nav-item">
            <a class="nav-link <?= $active ? 'active' : '' ?>" href="<?= route($route) ?>">
                <i class="bi <?= $icon ?>"></i> <?= _l("cdn.operator.$key") ?>
            </a>
        </li>
    <?php endforeach ?>
</ul>
