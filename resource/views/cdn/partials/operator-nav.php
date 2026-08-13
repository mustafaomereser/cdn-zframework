<?php

/**
 * The tabs across the operator pages.
 *
 * Prefixed variables, for the same reason the language switcher has them: this
 * is spliced into the page rather than included in a scope of its own, so a
 * plain $path or $key would be one the page might be using itself.
 *
 * One file rather than a copy per page: they are the only navigation between
 * four pages that are otherwise unrelated, and a tab that exists on three of
 * them is worse than no tab at all.
 */

use App\Cdn\Runner;

$navTabs = [
    'cdn-admin.operator.users'    => ['bi-people',   'users'],
    'cdn-admin.operator.projects' => ['bi-boxes',    'projects'],
    'cdn-admin.operator.files'    => ['bi-file-earmark', 'files'],
    'cdn-admin.operator.system'      => ['bi-cpu',       'system'],
    'cdn-admin.operator.maintenance' => ['bi-tools',     'maintenance'],
    'cdn-admin.operator.cpanel'      => ['bi-hdd-network', 'cpanel'],
    'cdn-admin.operator.audits'   => ['bi-list-ul',  'audits'],
];

if (Runner::enabled()) $navTabs['cdn-admin.operator.console'] = ['bi-terminal', 'console'];

$navPath = uri();
?>

<ul class="nav nav-pills op-tabs mb-3">
    <?php foreach ($navTabs as $navRoute => [$navIcon, $navKey]) :
        $navTarget = parse_url(route($navRoute), PHP_URL_PATH);
        # A detail page is under its list - /admin/users/3 keeps Accounts lit -
        # except the root, which is a prefix of everything.
        $navActive = rtrim($navTarget, '/') === rtrim(parse_url(route('cdn-admin.operator.users'), PHP_URL_PATH), '/')
            ? rtrim($navPath, '/') === rtrim($navTarget, '/') || str_starts_with($navPath, rtrim($navTarget, '/') . '/users')
            : str_starts_with($navPath, rtrim($navTarget, '/'));
    ?>
        <li class="nav-item">
            <a class="nav-link <?= $navActive ? 'active' : '' ?>" href="<?= route($navRoute) ?>">
                <i class="bi <?= $navIcon ?>"></i> <?= _l("cdn.operator.$navKey") ?>
            </a>
        </li>
    <?php endforeach ?>
</ul>
