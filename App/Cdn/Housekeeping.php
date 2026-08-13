<?php

namespace App\Cdn;

use App\Models\Cdn\Uploads;

/**
 * The maintenance work, in one place.
 *
 * It used to live in cron/cdn.php, which meant it could only be run by whatever
 * runs cron. On a host where nobody has set that up - or on the day somebody
 * wants the disk back now rather than at the top of the hour - there was
 * nothing to press.
 *
 * So the cron script is a caller like any other, and the operator's
 * Installation page is a second one. The order still matters and is still kept:
 * the rollup reads the log before the pruning trims it, and the collector runs
 * after evictions have released their references.
 */
class Housekeeping
{
    /**
     * What can be run, in the order it has to be.
     *
     * `daily` marks the two that are pointless more than once a day - the cron
     * skips them when they have already run, the panel runs them anyway,
     * because somebody pressing a button has said they want it now.
     *
     * @return array<string,array{daily:bool}>
     */
    public static function tasks(): array
    {
        return [
            'rollup'   => ['daily' => true,  'config' => 'stat-rollup'],
            'prune'    => ['daily' => true,  'config' => 'log-pruning'],
            'uploads'  => ['daily' => false, 'config' => 'expired-uploads'],
            'variants' => ['daily' => false, 'config' => 'variant-eviction'],
            'objects'  => ['daily' => false, 'config' => 'orphan-objects'],
            'temp'     => ['daily' => false, 'config' => null],
        ];
    }

    /**
     * Run one task, or everything that is due.
     *
     * @param string|null $only   A task name, or null for all of them.
     * @param bool        $force  Run the daily ones even if they ran today.
     * @return array<string,int> What each task did.
     */
    public static function run(?string $only = null, bool $force = false): array
    {
        $config = (array) Support::config('gc', []);
        $tasks  = self::tasks();
        $did    = [];

        # Once a day, whoever gets here first - one schedule to get wrong
        # instead of a second crontab entry.
        $state    = self::state();
        $today    = date('Y-m-d');
        $ranToday = ($state['daily'] ?? null) === $today;

        foreach ($tasks as $name => $task) {
            if ($only !== null && $only !== $name) continue;

            # Turned off in config stays off, however it was asked for.
            if ($task['config'] && !($config[$task['config']] ?? true)) continue;

            if ($task['daily'] && $ranToday && !$force && $only === null) continue;

            $did[$name] = self::one($name);
        }

        # Written after the fact, so a run that threw does not count as the
        # day's.
        $state = self::state();

        if ($only === null && !$ranToday) $state['daily'] = $today;

        foreach (array_keys($did) as $name) $state['tasks'][$name] = date('Y-m-d H:i:s');

        self::state($state);

        return $did;
    }

    /**
     * @param string $name
     * @return int
     */
    private static function one(string $name): int
    {
        switch ($name) {
            case 'rollup':
                # Yesterday, and today so far - so the dashboard is not a day
                # behind for anyone looking at it in the afternoon.
                return Metrics::rollup(date('Y-m-d', strtotime('-1 day'))) + Metrics::rollup(date('Y-m-d'));

            case 'prune':
                return Metrics::prune();

            case 'uploads':
                $model   = new Uploads;
                $expired = $model->where('expires_at', '<', date('Y-m-d H:i:s'))
                    ->where('status', '!=', 'completed')
                    ->closureMode(false)
                    ->get();

                foreach ($expired as $upload) @unlink($upload['temp_path']);

                if (count($expired)) $model->whereIn('id', array_column($expired, 'id'))->delete();

                return count($expired);

            case 'variants':
                return (int) Purger::evict()['evicted'];

            case 'objects':
                return (int) Purger::collect()['deleted'];

            case 'temp':
                # Temporary files whose session is gone - a process that died
                # between writing bytes and writing the row that owns them.
                $stale = 0;

                foreach (glob(Storage::tempRoot() . '/*') ?: [] as $file) {
                    if (!is_file($file) || filemtime($file) > time() - 86400) continue;

                    @unlink($file);
                    $stale++;
                }

                return $stale;
        }

        return 0;
    }

    /**
     * When each task last ran, for the page that offers to run it.
     *
     * @return array<string,string|null>
     */
    public static function lastRun(): array
    {
        $tasks = (array) (self::state()['tasks'] ?? []);
        $out   = [];

        foreach (array_keys(self::tasks()) as $name) $out[$name] = $tasks[$name] ?? null;

        return $out;
    }

    /**
     * The little that has to be remembered between runs: the day the daily work
     * last ran, and when each task last did anything.
     *
     * A file rather than GlobalCache, which was the first attempt. GlobalCache
     * is APCu or Redis, and APCu is per process pool: the cron runs under CLI
     * php and the panel under the web SAPI, so neither can see what the other
     * wrote. The daily marker never survived to the next cron run - the daily
     * work ran every hour - and the panel's "last run" column was permanently
     * empty. Redis would work; a file works everywhere.
     *
     * Not a table either: a row per housekeeping run is a table that only ever
     * grows, and this is six timestamps.
     *
     * @param array|null $write
     * @return array
     */
    private static function state(?array $write = null): array
    {
        $path = dirname(Storage::variantRoot()) . '/housekeeping.json';

        if ($write !== null) {
            if (!is_dir($directory = dirname($path))) @mkdir($directory, 0755, true);

            @file_put_contents($path, json_encode($write, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $write;
        }

        if (!is_file($path)) return [];

        return (array) json_decode((string) @file_get_contents($path), true);
    }
}
