<?php

namespace App\Cdn;

/**
 * Where bytes live.
 *
 * Objects are content addressed: <root>/ab/cd/<sha256>. The path is derived
 * from the content and nothing else, which gives three things for free -
 * deduplication, an immutable URL that can never serve stale bytes, and a
 * layout with no directory large enough to be slow to list.
 *
 * A disk is a driver plus a root. Only 'local' ships; adding one means
 * implementing the same handful of methods, which is why every call site goes
 * through here rather than touching the filesystem itself.
 */
class Storage
{
    /**
     * Configuration for a disk.
     *
     * @param string|null $disk
     * @return array
     */
    public static function disk(?string $disk = null): array
    {
        $disk  = $disk ?: (string) Support::config('storage.disk', 'local');
        $disks = (array) Support::config('storage.disks', []);

        if (!isset($disks[$disk])) throw new \RuntimeException("CDN disk '$disk' is not configured.");

        return ['name' => $disk] + $disks[$disk];
    }

    /**
     * @param string|null $disk
     * @return string
     */
    public static function root(?string $disk = null): string
    {
        return rtrim(str_replace('\\', '/', self::disk($disk)['root']), '/');
    }

    /**
     * The path an object with this hash is stored at, relative to the disk root.
     *
     * @param string $hash
     * @return string
     */
    public static function objectPath(string $hash): string
    {
        $hash   = strtolower($hash);
        $fanout = max(0, (int) Support::config('storage.fanout', 2));

        $parts = [];
        for ($level = 0; $level < $fanout; $level++) $parts[] = substr($hash, $level * 2, 2);
        $parts[] = $hash;

        return implode('/', $parts);
    }

    /**
     * @param string      $relative
     * @param string|null $disk
     * @return string
     */
    public static function absolute(string $relative, ?string $disk = null): string
    {
        return self::root($disk) . '/' . ltrim(str_replace('\\', '/', $relative), '/');
    }

    /**
     * Create a directory if it is missing.
     *
     * The is_dir() check is not redundant with mkdir's recursive mode: that one
     * stats every segment of the path on every call, and this runs per stored
     * object.
     *
     * @param string $directory
     * @return bool
     */
    public static function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) return true;
        return @mkdir($directory, 0755, true) || is_dir($directory);
    }

    /**
     * @param string      $relative
     * @param string|null $disk
     * @return bool
     */
    public static function exists(string $relative, ?string $disk = null): bool
    {
        return is_file(self::absolute($relative, $disk));
    }

    /**
     * @param string      $relative
     * @param string|null $disk
     * @return int
     */
    public static function size(string $relative, ?string $disk = null): int
    {
        $path = self::absolute($relative, $disk);
        return is_file($path) ? (int) filesize($path) : 0;
    }

    /**
     * @param string      $relative
     * @param string|null $disk
     * @return string|false
     */
    public static function read(string $relative, ?string $disk = null): string|false
    {
        return @file_get_contents(self::absolute($relative, $disk));
    }

    /**
     * Write bytes to a relative path, atomically.
     *
     * Written beside the target and renamed in: a reader can only ever see the
     * whole file or no file, never a half-written one. Two uploads of identical
     * content race here by design, and either winner is correct - the bytes are
     * the same, that is what content addressing means.
     *
     * @param string      $relative
     * @param string      $contents
     * @param string|null $disk
     * @return bool
     */
    public static function write(string $relative, string $contents, ?string $disk = null): bool
    {
        $path = self::absolute($relative, $disk);
        if (!self::ensureDirectory(dirname($path))) return false;

        $temporary = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $contents) === false) return false;

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            return is_file($path);
        }

        return true;
    }

    /**
     * Move an already-written file (an upload, a completed assembly) into place.
     *
     * @param string      $source
     * @param string      $relative
     * @param string|null $disk
     * @return bool
     */
    public static function place(string $source, string $relative, ?string $disk = null): bool
    {
        $path = self::absolute($relative, $disk);
        if (!self::ensureDirectory(dirname($path))) return false;

        # Already stored: identical content, so the incoming copy is redundant.
        if (is_file($path)) {
            @unlink($source);
            return true;
        }

        if (@rename($source, $path)) return true;

        # rename() fails across volumes; a copy is the fallback, not the default.
        if (@copy($source, $path)) {
            @unlink($source);
            return true;
        }

        return false;
    }

    /**
     * @param string      $relative
     * @param string|null $disk
     * @return bool
     */
    public static function delete(string $relative, ?string $disk = null): bool
    {
        $path = self::absolute($relative, $disk);
        if (!is_file($path)) return false;

        $deleted = @unlink($path);

        # Prune the two fanout levels behind it while they are empty, so a disk
        # that has been through a lot of churn does not keep 65k empty
        # directories around.
        $directory = dirname($path);
        $root      = self::root($disk);
        for ($level = 0; $level < (int) Support::config('storage.fanout', 2); $level++) {
            if ($directory === $root || !is_dir($directory)) break;
            if (!@rmdir($directory)) break;
            $directory = dirname($directory);
        }

        return $deleted;
    }

    /**
     * Directory holding derivatives.
     *
     * @return string
     */
    public static function variantRoot(): string
    {
        return rtrim(str_replace('\\', '/', (string) Support::config('storage.variants', BASE_PATH . '/storage/cdn/variants')), '/');
    }

    /**
     * @param string $signature
     * @param string $extension
     * @return string
     */
    public static function variantPath(string $signature, string $extension): string
    {
        return substr($signature, 0, 2) . '/' . substr($signature, 2, 2) . "/$signature." . ltrim($extension, '.');
    }

    /**
     * @param string $relative
     * @return string
     */
    public static function variantAbsolute(string $relative): string
    {
        return self::variantRoot() . '/' . ltrim($relative, '/');
    }

    /**
     * @param string $relative
     * @param string $contents
     * @return bool
     */
    public static function writeVariant(string $relative, string $contents): bool
    {
        $path = self::variantAbsolute($relative);
        if (!self::ensureDirectory(dirname($path))) return false;

        $temporary = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $contents) === false) return false;
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            return is_file($path);
        }

        return true;
    }

    /**
     * @param string $relative
     * @return bool
     */
    public static function deleteVariant(string $relative): bool
    {
        $path = self::variantAbsolute($relative);
        return is_file($path) ? @unlink($path) : false;
    }

    /**
     * Directory for uploads that have not finished arriving.
     *
     * @return string
     */
    public static function tempRoot(): string
    {
        $root = rtrim(str_replace('\\', '/', (string) Support::config('storage.temp', BASE_PATH . '/storage/cdn/temp')), '/');
        self::ensureDirectory($root);
        return $root;
    }

    /**
     * @param string $uploadId
     * @return string
     */
    public static function tempPath(string $uploadId): string
    {
        return self::tempRoot() . '/' . preg_replace('/[^a-f0-9]/i', '', $uploadId) . '.part';
    }

    /**
     * sha256 of a file, read in chunks.
     *
     * hash_file() rather than hash(file_get_contents()): a 2 GB upload should
     * not need 2 GB of memory to be identified.
     *
     * @param string $path
     * @return string|false
     */
    public static function hashFile(string $path): string|false
    {
        return @hash_file('sha256', $path);
    }

    /**
     * Total bytes under a directory, and the file count.
     *
     * Walks the tree, so it belongs in maintenance commands and the dashboard -
     * not on the delivery path.
     *
     * @param string $directory
     * @return array{bytes:int,files:int}
     */
    public static function measure(string $directory): array
    {
        if (!is_dir($directory)) return ['bytes' => 0, 'files' => 0];

        $bytes = 0;
        $files = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $entry) {
            if (!$entry->isFile()) continue;
            $bytes += $entry->getSize();
            $files++;
        }

        return ['bytes' => $bytes, 'files' => $files];
    }

    /**
     * Free space on the volume a disk lives on, or null when unknown.
     *
     * @param string|null $disk
     * @return float|null
     */
    public static function freeSpace(?string $disk = null): ?float
    {
        $root = self::root($disk);
        if (!is_dir($root) && !self::ensureDirectory($root)) return null;

        $free = @disk_free_space($root);
        return $free === false ? null : (float) $free;
    }
}
