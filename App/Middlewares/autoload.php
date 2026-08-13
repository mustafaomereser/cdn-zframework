<?php

namespace App\Middlewares;

use zFramework\Core\Middleware;

/**
 * Runs before the route table is consulted - at boot, and again at the start of
 * every later request when the process is a long-running worker.
 *
 * That second property is what makes it the right place for the CDN's
 * per-request reset. The framework resets its own statics from a list inside
 * zFramework/run.php; adding application classes to that list would put
 * application code in a file the next framework release overwrites. Resetting
 * at the start of a request is equivalent to flushing at the end, and it lives
 * here, where it survives an upgrade.
 */

# Under FPM the process dies with the request and there is nothing to reset.
# In a worker, leaving these would hand one visitor's resolved API key, cached
# bucket rows and byte counter to the next.
if (PHP_SAPI === 'cli') {
    \App\Cdn\Credentials::flushRequestState();
    \App\Cdn\Registry::flushRequestState();
    \App\Cdn\Delivery::flushRequestState();

    # The resolved project, above all: leaving it would show one account the
    # next one's files.
    \App\Cdn\Tenant::flushRequestState();
}

/**
 * The delivery path is excluded from the global middlewares. Two reasons, and
 * the second is the important one:
 *
 *   - Neither applies. An asset response renders no view and reads no
 *     translation, so resolving a locale and registering view directives is
 *     work with no reader.
 *   - Language sets a cookie. A response carrying Set-Cookie is treated as
 *     private by most shared caches, so leaving it in place would quietly stop
 *     any proxy in front of this from caching a single asset - and put a
 *     Set-Cookie on every image on the page besides.
 *
 * Compared against the raw path rather than uri(): this runs before the route
 * is matched, and it must be cheap.
 */

$prefix = rtrim((string) (config('cdn.delivery.url-prefix') ?: '/cdn'), '/');
$path   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

if (str_starts_with((string) $path, "$prefix/")) return;

$list = [
    Language::class,
    // ViewDirectives::class
];

Middleware::middleware($list);
