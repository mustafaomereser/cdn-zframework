<?php

/**
 * Router for the built-in development server.
 *
 * `php -S` without one serves a file when the path maps to an existing file and
 * 404s otherwise - it does not fall back to a front controller the way Apache's
 * rewrite does. That is fine for an application whose urls have no extensions,
 * and wrong for a CDN, where every url ends in .png or .mp4 and none of them
 * are files in the document root.
 *
 * Returning false hands the request back to the server, which serves the real
 * file itself - so /assets/css/style.css stays a static read rather than
 * booting the framework.
 *
 * Only the dev server reads this. In production the rewrite in .htaccess (or
 * nginx's try_files) does the same job.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . str_replace('\\', '/', (string) $path);

if ($path !== '/' && is_file($file)) return false;

require __DIR__ . '/index.php';
