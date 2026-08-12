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
 * `php terminal cdn gc` and friends do the same things interactively, with
 * output. This one is quiet unless something goes wrong.
 */

use App\Cdn\Metrics;
use App\Cdn\Purger;
use App\Cdn\Storage;
use App\Cdn\Support;
use App\Models\Cdn\Uploads;
use zFramework\Core\GlobalCache;

$started = microtime(true);
$did     = [];

try {
    $config = (array) config('cdn.gc');

    # Once a day, whoever gets here first. A marker in the shared cache rather
    # than a separate crontab entry: one schedule to get wrong instead of two.
    $daily = date('Y-m-d');
    $ranToday = GlobalCache::cache('cdn.cron.daily', fn() => null, 172800) === $daily;

    if (!$ranToday) {
        GlobalCache::remove('cdn.cron.daily');
        GlobalCache::cache('cdn.cron.daily', fn() => $daily, 172800);

        if ($config['stat-rollup'] ?? true) {
            # Yesterday, and today so far - so the dashboard is not a day behind
            # for anyone looking at it in the afternoon.
            $did['rollup'] = Metrics::rollup(date('Y-m-d', strtotime('-1 day'))) + Metrics::rollup($daily);
        }

        if ($config['log-pruning'] ?? true) $did['pruned'] = Metrics::prune();
    }

    if ($config['expired-uploads'] ?? true) {
        $model   = new Uploads;
        $expired = $model->where('expires_at', '<', date('Y-m-d H:i:s'))->where('status', '!=', 'completed')->closureMode(false)->get();

        foreach ($expired as $upload) @unlink($upload['temp_path']);
        if (count($expired)) $model->whereIn('id', array_column($expired, 'id'))->delete();

        $did['uploads'] = count($expired);
    }

    if ($config['variant-eviction'] ?? true) {
        $evicted = Purger::evict();
        $did['variants'] = $evicted['evicted'];
    }

    if ($config['orphan-objects'] ?? true) {
        $collected = Purger::collect();
        $did['objects'] = $collected['deleted'];
    }

    # Temporary files whose session is gone - a process that died between
    # writing bytes and writing the row that owns them.
    $stale = 0;
    foreach (glob(Storage::tempRoot() . '/*') ?: [] as $file) {
        if (!is_file($file) || filemtime($file) > time() - 86400) continue;
        @unlink($file);
        $stale++;
    }
    $did['temp'] = $stale;
} catch (\Throwable $exception) {
    # A cron that fails silently is a cron that is not running. The framework's
    # handler writes the trace; this line is what a `tail` on the cron mail
    # shows.
    errorHandler($exception);
    echo 'cdn cron failed: ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}

echo 'cdn cron: ' . json_encode($did) . ' in ' . round((microtime(true) - $started) * 1000) . ' ms' . PHP_EOL;
