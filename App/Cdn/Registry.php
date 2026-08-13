<?php

namespace App\Cdn;

use App\Models\Cdn\Buckets;
use App\Models\Cdn\Projects;
use zFramework\Core\GlobalCache;

/**
 * Bucket and project lookups, cached.
 *
 * Every delivered request needs both rows and neither changes more than a few
 * times a day, so reading them from the database per request is the clearest
 * waste on the whole path. They go in GlobalCache - APCu locally, Redis across
 * servers - and every write path calls forget().
 *
 * Files are deliberately *not* cached here. They change with every upload, the
 * lookup is a single row on a covering index, and an invalidation bug there
 * serves the wrong bytes rather than the wrong policy.
 */
class Registry
{
    /**
     * Within one request, the same bucket is asked for more than once - guard,
     * delivery, logging. This is cheaper than APCu for the second look.
     */
    private static array $local = [];

    /**
     * A bucket by its project's slug and its own.
     *
     * Both halves are needed: a bucket slug is only unique inside its project,
     * which is what lets every account have a bucket called "photos".
     *
     * @param string $projectSlug
     * @param string $slug
     * @return array|null
     */
    public static function bucket(string $projectSlug, string $slug): ?array
    {
        $projectSlug = strtolower(trim($projectSlug));
        $slug        = strtolower(trim($slug));

        if ($projectSlug === '' || $slug === '') return null;

        $key = "b:$projectSlug/$slug";
        if (array_key_exists($key, self::$local)) return self::$local[$key];

        $row = GlobalCache::cache(
            'cdn.bucket.' . md5("$projectSlug/$slug"),
            function () use ($projectSlug, $slug) {
                $project = (new Projects)->where('slug', $projectSlug)->closureMode(false)->first();
                if (!$project) return null;

                return (new Buckets)->where('project_id', $project['id'])->where('slug', $slug)->closureMode(false)->first() ?: null;
            },
            (int) Support::config('cache.registry-ttl', 300)
        );

        return self::$local[$key] = ($row ?: null);
    }

    /**
     * @param int $id
     * @return array|null
     */
    public static function project(int $id): ?array
    {
        if (!$id) return null;
        if (array_key_exists("p:$id", self::$local)) return self::$local["p:$id"];

        $row = GlobalCache::cache(
            "cdn.project.$id",
            fn() => (new Projects)->closureMode(false)->find((string) $id) ?: null,
            (int) Support::config('cache.registry-ttl', 300)
        );

        return self::$local["p:$id"] = ($row ?: null);
    }

    /**
     * Drop a bucket from the cache.
     *
     * Called by anything that writes to the row - a purge bumps cache_version,
     * and a stale copy of that would keep serving derivatives that were just
     * invalidated.
     *
     * Takes the bucket row rather than a slug, because the cache key now needs
     * the project too and every caller already has the row in hand.
     *
     * @param array|null $bucket
     * @return void
     */
    public static function forgetBucket(?array $bucket): void
    {
        if (!$bucket || empty($bucket['slug'])) return;

        $project = self::project((int) ($bucket['project_id'] ?? 0));
        if (!$project) return;

        $key = strtolower($project['slug'] . '/' . $bucket['slug']);

        GlobalCache::remove('cdn.bucket.' . md5($key));
        unset(self::$local["b:$key"]);
    }

    /**
     * @param int $id
     * @return void
     */
    public static function forgetProject(int $id): void
    {
        GlobalCache::remove("cdn.project.$id");
        unset(self::$local["p:$id"]);
    }

    /**
     * Clear what this request has looked up. Only matters in a long-running
     * worker, where the statics outlive the request.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$local = [];
    }
}
