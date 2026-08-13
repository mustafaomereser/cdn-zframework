<?php

namespace App\Cdn;

use App\Models\Cdn\Buckets;
use App\Models\Cdn\Files;
use App\Models\Cdn\Purges;
use App\Models\Cdn\Variants;
use zFramework\Core\Facades\DB;

/**
 * Invalidation.
 *
 * There are two kinds here and they behave differently, which is worth being
 * explicit about because "purge" is where CDNs surprise people:
 *
 *   - Derivatives are ours. Deleting them, or bumping the bucket's cache
 *     version so every signature changes, takes effect on the next request.
 *   - Copies in browsers and in whatever cache sits in front are not ours. A
 *     purge cannot reach them; only the URL changing, or a short max-age, can.
 *
 * So a bucket meant to be overwritten in place wants a modest cache_ttl and
 * immutable off. A bucket whose URLs carry a content hash can cache for a year
 * and never purge anything.
 */
class Purger
{
    /**
     * Every derivative of one file.
     *
     * @param int $fileId
     * @return array{variants:int,bytes:int}
     */
    public static function variantsOf(int $fileId): array
    {
        $model    = new Variants;
        $variants = $model->where('file_id', $fileId)->closureMode(false)->get();

        $bytes = 0;
        foreach ($variants as $variant) {
            Storage::deleteVariant($variant['storage_path']);
            $bytes += (int) $variant['size'];
        }

        if (count($variants)) $model->where('file_id', $fileId)->delete();

        return ['variants' => count($variants), 'bytes' => $bytes];
    }

    /**
     * One path in a bucket.
     *
     * @param array  $bucket
     * @param string $path
     * @param string $by
     * @return array
     */
    public static function path(array $bucket, string $path, string $by = 'system'): array
    {
        $path = Support::normalizePath($path);
        if ($path === false) return ['ok' => false, 'error' => 'invalid-path'];

        $file = (new Files)->where('bucket_id', $bucket['id'])->where('path', $path)->closureMode(false)->first();
        if (!$file) return ['ok' => false, 'error' => 'not-found'];

        $result = self::variantsOf((int) $file['id']);
        self::log($bucket, 'path', $path, 1, $result, $by);

        return ['ok' => true, 'files' => 1] + $result;
    }

    /**
     * Everything under a path prefix.
     *
     * @param array  $bucket
     * @param string $prefix
     * @param string $by
     * @return array
     */
    public static function prefix(array $bucket, string $prefix, string $by = 'system'): array
    {
        $prefix = ltrim(str_replace('\\', '/', $prefix), '/');
        if (strstr($prefix, '..')) return ['ok' => false, 'error' => 'invalid-prefix'];

        # LIKE with the wildcard appended rather than a regex: it is the form
        # that can use the (bucket_id, path) index.
        $files = (new Files)
            ->where('bucket_id', $bucket['id'])
            ->where('path', 'LIKE', $prefix . '%')
            ->closureMode(false)
            ->get();

        $variants = 0;
        $bytes    = 0;

        foreach ($files as $file) {
            $result   = self::variantsOf((int) $file['id']);
            $variants += $result['variants'];
            $bytes    += $result['bytes'];
        }

        self::log($bucket, 'prefix', $prefix, count($files), ['variants' => $variants, 'bytes' => $bytes], $by);

        return ['ok' => true, 'files' => count($files), 'variants' => $variants, 'bytes' => $bytes];
    }

    /**
     * Files carrying a tag.
     *
     * @param array  $bucket
     * @param string $tag
     * @param string $by
     * @return array
     */
    public static function tag(array $bucket, string $tag, string $by = 'system'): array
    {
        # A json column search: the tag list is short and this is an
        # administrative action, so a scan of one bucket is acceptable where a
        # separate tag table would not be worth its joins.
        $files = (new Files)
            ->where('bucket_id', $bucket['id'])
            ->whereRaw("JSON_SEARCH(tags, 'one', :tag) IS NOT NULL", ['tag' => $tag])
            ->closureMode(false)
            ->get();

        $variants = 0;
        $bytes    = 0;

        foreach ($files as $file) {
            $result   = self::variantsOf((int) $file['id']);
            $variants += $result['variants'];
            $bytes    += $result['bytes'];
        }

        self::log($bucket, 'tag', $tag, count($files), ['variants' => $variants, 'bytes' => $bytes], $by);

        return ['ok' => true, 'files' => count($files), 'variants' => $variants, 'bytes' => $bytes];
    }

    /**
     * A whole bucket.
     *
     * The version bump is the cheap half and takes effect immediately: every
     * derivative signature changes, so nothing stored can be found again. The
     * files are then deleted for the disk's sake, not for correctness.
     *
     * @param array $bucket
     * @param string $by
     * @return array
     */
    public static function bucket(array $bucket, string $by = 'system'): array
    {
        (new DB)->prepare("UPDATE cdn_buckets SET cache_version = cache_version + 1 WHERE id = :id", ['id' => $bucket['id']]);

        # The cached copy still carries the old version, and the version is part
        # of every derivative signature - without this the purge would appear to
        # do nothing until the cache expired.
        Registry::forgetBucket($bucket);

        $model    = new Variants;
        $variants = $model->where('bucket_id', $bucket['id'])->closureMode(false)->get();

        $bytes = 0;
        foreach ($variants as $variant) {
            Storage::deleteVariant($variant['storage_path']);
            $bytes += (int) $variant['size'];
        }

        if (count($variants)) $model->where('bucket_id', $bucket['id'])->delete();

        self::log($bucket, 'bucket', $bucket['slug'], 0, ['variants' => count($variants), 'bytes' => $bytes], $by);
        Webhook::fire($bucket, 'purge', ['bucket' => $bucket['slug'], 'type' => 'bucket', 'variants' => count($variants)]);

        return ['ok' => true, 'files' => 0, 'variants' => count($variants), 'bytes' => $bytes];
    }

    /**
     * Every bucket in a project.
     *
     * @param int    $projectId
     * @param string $by
     * @return array
     */
    public static function project(int $projectId, string $by = 'system'): array
    {
        $buckets  = (new Buckets)->where('project_id', $projectId)->closureMode(false)->get();
        $variants = 0;
        $bytes    = 0;

        foreach ($buckets as $bucket) {
            $result   = self::bucket($bucket, $by);
            $variants += $result['variants'];
            $bytes    += $result['bytes'];
        }

        return ['ok' => true, 'buckets' => count($buckets), 'variants' => $variants, 'bytes' => $bytes];
    }

    /**
     * Derivatives nobody has asked for in a while, oldest first, until the
     * cache is back under its size cap.
     *
     * The original is still there, so an evicted derivative costs one rebuild -
     * which is why this is safe to be aggressive with.
     *
     * @param int|null $maxBytes
     * @return array{evicted:int,bytes:int}
     */
    public static function evict(?int $maxBytes = null): array
    {
        $maxBytes = $maxBytes ?? (int) Support::config('transform.cache.max-size', 0);
        $ttl      = (int) Support::config('transform.cache.ttl', 0);

        $model   = new Variants;
        $evicted = 0;
        $freed   = 0;

        # Unused for longer than the ttl: gone whatever the size says.
        if ($ttl > 0) {
            $stale = $model
                ->whereRaw('(last_accessed_at IS NULL AND created_at < :before) OR last_accessed_at < :before2', [
                    'before'  => date('Y-m-d H:i:s', time() - $ttl),
                    'before2' => date('Y-m-d H:i:s', time() - $ttl),
                ])
                ->limit(5000)
                ->closureMode(false)
                ->get();

            foreach ($stale as $variant) {
                Storage::deleteVariant($variant['storage_path']);
                $model->where('id', $variant['id'])->delete();
                $evicted++;
                $freed += (int) $variant['size'];
            }
        }

        if ($maxBytes <= 0) return ['evicted' => $evicted, 'bytes' => $freed];

        $total = (int) ((new DB)->prepare("SELECT COALESCE(SUM(size), 0) AS total FROM cdn_variants")->fetch(\PDO::FETCH_ASSOC)['total'] ?? 0);
        if ($total <= $maxBytes) return ['evicted' => $evicted, 'bytes' => $freed];

        # Least recently used first. COALESCE so a derivative that has never been
        # served sorts as oldest - it is the one nobody wanted.
        $candidates = $model
            ->orderBy(['last_accessed_at' => 'ASC', 'id' => 'ASC'])
            ->limit(20000)
            ->closureMode(false)
            ->get();

        foreach ($candidates as $variant) {
            if ($total <= $maxBytes) break;

            Storage::deleteVariant($variant['storage_path']);
            $model->where('id', $variant['id'])->delete();

            $total -= (int) $variant['size'];
            $freed += (int) $variant['size'];
            $evicted++;
        }

        return ['evicted' => $evicted, 'bytes' => $freed];
    }

    /**
     * Delete stored objects nothing references any more.
     *
     * The grace period matters: a file deleted and re-uploaded within it keeps
     * its bytes, so the common "replace this image" flow does not move data.
     *
     * @param int $graceSeconds
     * @return array{deleted:int,bytes:int}
     */
    public static function collect(int $graceSeconds = 3600): array
    {
        $model   = new \App\Models\Cdn\Objects;
        $orphans = $model
            ->where('refs', '<=', 0)
            ->where('orphan_at', '<', date('Y-m-d H:i:s', time() - max(0, $graceSeconds)))
            ->limit(5000)
            ->closureMode(false)
            ->get();

        $deleted = 0;
        $bytes   = 0;

        foreach ($orphans as $object) {
            # Checked again against the live table: a row may have been created
            # between the query above and now, and deleting the bytes then would
            # break a file that looks perfectly healthy.
            $referenced = (new Files)->where('hash', $object['hash'])->count();
            if ($referenced > 0) {
                (new DB)->prepare("UPDATE cdn_objects SET refs = :refs, orphan_at = NULL WHERE id = :id", ['refs' => $referenced, 'id' => $object['id']]);
                continue;
            }

            Storage::delete($object['storage_path'], $object['disk']);
            $model->where('id', $object['id'])->delete();

            $deleted++;
            $bytes += (int) $object['size'];
        }

        return ['deleted' => $deleted, 'bytes' => $bytes];
    }

    /**
     * @param array  $bucket
     * @param string $type
     * @param string $target
     * @param int    $files
     * @param array  $result
     * @param string $by
     * @return void
     */
    private static function log(array $bucket, string $type, string $target, int $files, array $result, string $by): void
    {
        try {
            (new Purges)->insert([
                'project_id' => $bucket['project_id'] ?? null,
                'bucket_id'  => $bucket['id'] ?? null,
                'type'       => $type,
                'target'     => mb_substr($target, 0, 255),
                'files'      => $files,
                'variants'   => (int) ($result['variants'] ?? 0),
                'bytes'      => (int) ($result['bytes'] ?? 0),
                'issued_by'  => mb_substr($by, 0, 120),
                # No request under the CLI, and ip() reads $_SERVER directly.
                'ip'         => PHP_SAPI === 'cli' ? null : ip(),
            ], just_insert: true);
        } catch (\Throwable $e) {
            if (function_exists('errorHandler')) errorHandler($e);
        }
    }
}
