<?php

namespace App\Cdn;

use zFramework\Core\GlobalCache;

/**
 * Origin pull: a bucket that mirrors somewhere else.
 *
 * A miss becomes an upstream request, what comes back is stored as an ordinary
 * object, and every request after that is a local read. This is the difference
 * between a file host and an edge - nobody has to upload anything, the first
 * visitor to ask for a path is what puts it here.
 *
 * Two things stop that being a hole. Misses are remembered for a short while,
 * or a bot walking /1.jpg … /99999.jpg turns into that many upstream requests.
 * And the fetch itself goes through the same guard as upload-by-URL, since the
 * origin URL is configuration but the *path* comes from the visitor.
 */
class Origin
{
    /**
     * Fetch a path from the bucket's origin and store it.
     *
     * @param array  $bucket
     * @param string $path
     * @return array|null  The stored file row, or null when upstream has nothing.
     */
    public static function pull(array $bucket, string $path): ?array
    {
        if (!Support::config('origin.enabled', true)) return null;
        if (empty($bucket['origin_url'])) return null;

        $path = Support::normalizePath($path);
        if ($path === false) return null;

        # Remembered misses. Keyed by bucket and path, so one bucket's 404 does
        # not answer for another's.
        $negativeKey = 'cdn.origin.miss.' . md5($bucket['id'] . '|' . $path);
        $negativeTtl = (int) Support::config('origin.negative-ttl', 60);

        # GlobalCache has no read-without-write accessor, so the read seeds a
        # `false` under the same key. Harmless: a miss below replaces it, and a
        # hit means this path is never taken again for the ttl.
        if ($negativeTtl > 0 && GlobalCache::cache($negativeKey, fn() => false, $negativeTtl)) return null;

        $url = rtrim((string) $bucket['origin_url'], '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));

        $result = Fetcher::get($url, [
            'timeout'       => (int) Support::config('origin.timeout', 15),
            'max-size'      => (int) Support::config('origin.max-size', 0),
            'schemes'       => ['https', 'http'],
            'block-private' => true,
            'max-redirect'  => 3,
        ]);

        if (!$result['ok']) {
            if ($negativeTtl > 0) {
                GlobalCache::remove($negativeKey);
                GlobalCache::cache($negativeKey, fn() => true, $negativeTtl);
            }
            return null;
        }

        $stored = Uploader::store($bucket, $result['path'], [
            'path'        => $path,
            'name'        => basename($path),
            'mime'        => $result['mime'],
            'uploaded_by' => 'origin',
            'origin_ttl'  => (int) ($bucket['origin_ttl'] ?: Support::config('origin.negative-ttl', 86400)),
            'overwrite'   => true,
        ]);

        return $stored['ok'] ? $stored['file'] : null;
    }

    /**
     * Should this copy be checked against upstream again?
     *
     * @param array $file
     * @param array $bucket
     * @return bool
     */
    public static function stale(array $file, array $bucket): bool
    {
        if (empty($bucket['origin_url'])) return false;
        if (empty($file['origin_expires_at'])) return false;

        return strtotime($file['origin_expires_at']) < time();
    }

    /**
     * Refresh a stale copy.
     *
     * Failure keeps serving what is here when `origin.stale-on-error` is on,
     * which is almost always what you want: an origin being down should not
     * take the cached copies down with it.
     *
     * @param array $file
     * @param array $bucket
     * @return array
     */
    public static function refresh(array $file, array $bucket): array
    {
        $pulled = self::pull($bucket, $file['path']);

        if ($pulled) return $pulled;
        if (Support::config('origin.stale-on-error', true)) return $file;

        return $file;
    }
}
