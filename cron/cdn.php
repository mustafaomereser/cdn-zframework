<?php include('cron.php');

/**
 * CDN housekeeping.
 *
 * One entry point rather than four cron lines, because the order matters: the
 * rollup has to read the log before the pruning trims it, and the collector
 * should run after evictions have released their references.
 *
 * Hourly is a reasonable default. The daily work notices for itself that it has
 * already run today, so calling this more often costs a few queries and does no
 * harm.
 *
 *   0 * * * * php /path/to/cron/cdn.php
 *
 * The work itself is in App\Cdn\Housekeeping, because this is not the only
 * thing that runs it: the operator's Installation page has a button for each
 * task, for the host where nobody set up a crontab and for the day somebody
 * wants the disk back now rather than at the top of the hour.
 *
 * `php cdn gc` and friends do the same things interactively, with output. This
 * one is quiet unless something goes wrong.
 */

use App\Cdn\Housekeeping;

$started = microtime(true);

try {
    $did = Housekeeping::run();
} catch (\Throwable $exception) {
    # A cron that fails silently is a cron that is not running. The framework's
    # handler writes the trace; this line is what a `tail` on the cron mail
    # shows.
    errorHandler($exception);
    echo 'cdn cron failed: ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}

echo 'cdn cron: ' . json_encode($did) . ' in ' . round((microtime(true) - $started) * 1000) . ' ms' . PHP_EOL;
