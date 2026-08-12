<?php

namespace App\Middlewares;

use zFramework\Core\Middleware;

/**
 * Global middlewares, run before the route table is consulted.
 *
 * The delivery path is excluded from all of them. Two reasons, and the second
 * is the important one:
 *
 *   - Neither applies. An asset response renders no view and reads no
 *     translation, so resolving a locale and registering view directives is
 *     work with no reader.
 *   - Language sets a cookie. A response carrying Set-Cookie is treated as
 *     private by most shared caches, so leaving it in place would quietly stop
 *     any proxy in front of this from caching a single asset - and put a
 *     Set-Cookie on every image on the page besides.
 *
 * Compared against the raw path rather than uri(): this runs at boot, before
 * the route is matched, and it must be cheap.
 */

$prefix = rtrim((string) (config('cdn.delivery.url-prefix') ?: '/cdn'), '/');
$path   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

if (str_starts_with((string) $path, "$prefix/")) return;

$list = [
    Language::class,
    ViewDirectives::class
];

Middleware::middleware($list);
