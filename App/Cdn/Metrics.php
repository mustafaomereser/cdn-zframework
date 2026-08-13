<?php

namespace App\Cdn;

use App\Models\Cdn\AccessLogs;
use App\Models\Cdn\Stats;
use zFramework\Core\Facades\DB;
use zFramework\Core\Facades\Defer;
use zFramework\Core\Facades\Queue;

/**
 * Access logging, counters and rollups.
 *
 * None of this may happen while the visitor is waiting. `defer` runs it after
 * the response has been written; `queue` hands it to a worker. Either way the
 * bytes have already left before a row is inserted.
 *
 * Two separate things live here on purpose. The access log is *sampled* - it is
 * for seeing patterns, and one request in twenty shows the same pattern for a
 * twentieth of the disk. The counters are *not* sampled: bandwidth is billed
 * and quota is enforced against it, so an estimate is not good enough.
 */
class Metrics
{
    /**
     * Counter deltas accumulated during this request, flushed together.
     */
    private static array $counters = [];

    /**
     * Register a delivered request to be recorded after the response.
     *
     * The factory is what makes this awkward shape necessary: how many bytes
     * were actually transferred is only known once the body has been written,
     * and a ranged or aborted transfer is not the file's size. So the caller
     * hands over a closure that is evaluated at flush time - after the socket
     * work is done - rather than an array built too early.
     *
     * @param \Closure $factory Returns [entry, counters].
     * @return void
     */
    public static function deferred(\Closure $factory): void
    {
        Defer::after(function () use ($factory) {
            [$entry, $counters] = $factory();
            self::commit((array) $entry, (array) $counters);
        }, 'cdn-metrics');
    }

    /**
     * Record one delivered request, here and now.
     *
     * @param array $entry    Columns for cdn_access_logs, minus created_at.
     * @param array $counters project_id / bucket_id / file_id / bytes
     * @return void
     */
    public static function commit(array $entry, array $counters = []): void
    {
        if (count($counters)) self::count($counters);

        $driver = (string) Support::config('logging.driver', 'defer');

        if (!Support::config('logging.enabled', true) || $driver === 'off') {
            self::flushCounters();
            return;
        }

        $sample = (float) Support::config('logging.sample', 1);
        $write  = $sample >= 1 || (mt_rand() / mt_getrandmax()) < $sample;

        # The weight is what makes a sampled log still add up: one recorded
        # request in twenty stands for twenty, and the rollup multiplies by it.
        $entry['weight']     = $sample > 0 && $sample < 1 ? (int) round(1 / $sample) : 1;
        $entry['created_at'] = date('Y-m-d H:i:s');

        if (!$write) {
            self::flushCounters();
            return;
        }

        if (!Support::config('logging.store-ua', true))      unset($entry['agent']);
        if (!Support::config('logging.store-referer', true)) unset($entry['referer']);

        # This already runs after the response, so 'queue' only buys durability
        # across a crash between here and the insert.
        if ($driver === 'queue') Queue::push([self::class, 'handle'], $entry);
        else self::write($entry);

        self::flushCounters();
    }

    /**
     * Record a request from a path that has no deferred stage - the management
     * API, mostly, where the response is small and already sent by the time
     * this is reached.
     *
     * @param array $entry
     * @param array $counters
     * @return void
     */
    public static function record(array $entry, array $counters = []): void
    {
        Defer::after(fn() => self::commit($entry, $counters), 'cdn-metrics');
    }

    /**
     * Queue entry point.
     *
     * @param array $payload
     * @return void
     */
    public function handle(array $payload): void
    {
        self::write($payload);
    }

    /**
     * @param array $entry
     * @return void
     */
    private static function write(array $entry): void
    {
        try {
            foreach (['path', 'referer', 'agent'] as $column) {
                if (isset($entry[$column])) $entry[$column] = mb_substr((string) $entry[$column], 0, 255);
            }

            (new AccessLogs)->insert($entry, just_insert: true);
        } catch (\Throwable $e) {
            # A full log table must not turn a served asset into a 500. The
            # response is already sent by this point anyway.
            if (function_exists('errorHandler')) errorHandler($e);
        }
    }

    /**
     * Accumulate counter deltas for this request.
     *
     * @param array $counters
     * @return void
     */
    public static function count(array $counters): void
    {
        $bytes = (int) ($counters['bytes'] ?? 0);

        foreach (['project_id', 'bucket_id', 'file_id'] as $key) {
            $id = (int) ($counters[$key] ?? 0);
            if (!$id) continue;
            self::$counters[$key][$id] = (self::$counters[$key][$id] ?? 0) + $bytes;
        }
    }

    /**
     * Write the accumulated deltas.
     *
     * Raw UPDATE … col = col + n rather than a read-modify-write: two requests
     * finishing at once would otherwise each write the value they read, and one
     * of the two transfers would vanish from the bill.
     *
     * @return void
     */
    public static function flushCounters(): void
    {
        if (!count(self::$counters) || !Support::config('logging.counters', true)) {
            self::$counters = [];
            return;
        }

        $counters       = self::$counters;
        self::$counters = [];

        try {
            $db     = new DB;
            $period = date('Y-m');

            foreach ($counters['project_id'] ?? [] as $id => $bytes) {
                # The monthly counter resets by comparing periods rather than by
                # a scheduled job: a project nobody touched for three months
                # should not need a cron run to have the right number.
                $db->prepare(
                    "UPDATE cdn_projects
                        SET bandwidth_used = IF(bandwidth_period = :period, bandwidth_used + :bytes, :bytes),
                            bandwidth_period = :period2
                      WHERE id = :id",
                    ['period' => $period, 'bytes' => $bytes, 'period2' => $period, 'id' => $id]
                );
            }

            foreach ($counters['bucket_id'] ?? [] as $id => $bytes)
                $db->prepare("UPDATE cdn_buckets SET bandwidth_used = bandwidth_used + :bytes WHERE id = :id", ['bytes' => $bytes, 'id' => $id]);

            foreach ($counters['file_id'] ?? [] as $id => $bytes)
                $db->prepare(
                    "UPDATE cdn_files SET downloads = downloads + 1, bytes_served = bytes_served + :bytes, last_accessed_at = :now WHERE id = :id",
                    ['bytes' => $bytes, 'now' => date('Y-m-d H:i:s'), 'id' => $id]
                );
        } catch (\Throwable $e) {
            if (function_exists('errorHandler')) errorHandler($e);
        }
    }

    /**
     * Note that a derivative was served, without a row read.
     *
     * @param int $id
     * @return void
     */
    public static function variantHit(int $id): void
    {
        if (!$id) return;

        Defer::after(function () use ($id) {
            try {
                (new DB)->prepare("UPDATE cdn_variants SET hits = hits + 1, last_accessed_at = :now WHERE id = :id", ['now' => date('Y-m-d H:i:s'), 'id' => $id]);
            } catch (\Throwable) {
            }
        }, 'cdn-variant-hit');
    }

    /**
     * Fold a day of access logs into cdn_stats.
     *
     * Idempotent: running it twice for the same day overwrites rather than
     * accumulates, so a cron that fires late or twice cannot inflate a month.
     *
     * @param string|null $date Y-m-d, yesterday by default.
     * @return int Rows written.
     */
    public static function rollup(?string $date = null): int
    {
        $date = $date ?: date('Y-m-d', strtotime('-1 day'));
        $db   = new DB;

        $rows = $db->prepare(
            "SELECT project_id, bucket_id,
                    SUM(weight)                                          AS requests,
                    SUM(bytes * weight)                                  AS bytes,
                    SUM(IF(cache = 'hit', weight, 0))                    AS hits,
                    SUM(IF(cache IN ('miss','pulled'), weight, 0))       AS misses,
                    SUM(IF(cache = 'transformed', weight, 0))            AS transforms,
                    SUM(IF(status >= 500, weight, 0))                    AS errors,
                    SUM(IF(status IN (403,429,509), weight, 0))          AS denied,
                    COUNT(DISTINCT ip)                                   AS visitors
               FROM cdn_access_logs
              WHERE created_at >= :from AND created_at < :to
              GROUP BY project_id, bucket_id",
            ['from' => "$date 00:00:00", 'to' => date('Y-m-d', strtotime("$date +1 day")) . ' 00:00:00']
        )->fetchAll(\PDO::FETCH_ASSOC);

        $model   = new Stats;
        $written = 0;

        foreach ($rows as $row) {
            if (!$row['bucket_id']) continue;

            $values = [
                'requests'   => (int) $row['requests'],
                'bytes'      => (int) $row['bytes'],
                'hits'       => (int) $row['hits'],
                'misses'     => (int) $row['misses'],
                'transforms' => (int) $row['transforms'],
                'errors'     => (int) $row['errors'],
                'denied'     => (int) $row['denied'],
                'visitors'   => (int) $row['visitors'],
            ];

            $existing = $model->where('date', $date)->where('bucket_id', $row['bucket_id'])->closureMode(false)->first();

            if ($existing) $model->where('id', $existing['id'])->update($values);
            else $model->insert($values + ['date' => $date, 'project_id' => (int) $row['project_id'], 'bucket_id' => (int) $row['bucket_id']], just_insert: true);

            $written++;
        }

        return $written;
    }

    /**
     * Delete access logs older than the retention window.
     *
     * Deleted in batches: one DELETE over a month of rows locks the table for
     * as long as it takes, and the table is being written to continuously.
     *
     * @param int|null $days
     * @return int
     */
    public static function prune(?int $days = null): int
    {
        $days = $days ?? (int) Support::config('logging.keep-days', 30);
        if ($days <= 0) return 0;

        $before  = date('Y-m-d H:i:s', strtotime("-$days days"));
        $db      = new DB;
        $deleted = 0;

        do {
            $statement = $db->prepare("DELETE FROM cdn_access_logs WHERE created_at < :before LIMIT 5000", ['before' => $before]);
            $count     = $statement->rowCount();
            $deleted  += $count;
        } while ($count >= 5000);

        return $deleted;
    }

    /**
     * Traffic for a dashboard: one row per day.
     *
     * @param int|null $bucketId
     * @param int      $days
     * @param int|null $projectId Scope to one tenant. Null is the whole
     *                            installation, which only an operator sees.
     * @return array
     */
    public static function series(?int $bucketId = null, int $days = 30, ?int $projectId = null): array
    {
        $model = (new Stats)
            ->where('date', '>=', date('Y-m-d', strtotime('-' . max(1, $days) . ' days')))
            ->closureMode(false)
            ->orderBy(['date' => 'ASC']);

        if ($bucketId)  $model->where('bucket_id', $bucketId);
        if ($projectId) $model->where('project_id', $projectId);

        $series = [];
        foreach ($model->get() as $row) {
            $day = $row['date'];
            $series[$day] ??= ['date' => $day, 'requests' => 0, 'bytes' => 0, 'hits' => 0, 'misses' => 0, 'errors' => 0];

            foreach (['requests', 'bytes', 'hits', 'misses', 'errors'] as $column) $series[$day][$column] += (int) $row[$column];
        }

        return array_values($series);
    }
}
