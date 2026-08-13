<?php

namespace App\Cdn;

use zFramework\Core\Facades\DB;
use zFramework\Core\Helpers\File;

/**
 * What the machine is, as far as PHP can see from inside it.
 *
 * Every number here is read at request time and nothing is cached: this page is
 * opened when somebody wants to know what is true now - usually because
 * something is wrong - and a cached answer to that is worse than no answer.
 *
 * Nothing here shells out. Where a figure is only available from the operating
 * system, it is read from a file the OS keeps (`/proc/meminfo`) or from a PHP
 * function, and where neither exists the row says so instead of guessing. A
 * panel that runs `free -m` is a panel that stops working on the host where
 * exec is disabled, which is most of them.
 */
class System
{
    /**
     * @return array<string,array<string,string|null>>
     */
    public static function info(): array
    {
        return [
            'php'    => self::php(),
            'server' => self::server(),
            'memory' => self::memory(),
            'db'     => self::database(),
        ];
    }

    /**
     * @return array
     */
    private static function php(): array
    {
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;

        $out = [
            'version'      => PHP_VERSION,
            'sapi'         => PHP_SAPI,
            'memory-limit' => (string) ini_get('memory_limit'),
            'max-execution' => ((int) ini_get('max_execution_time') ?: 0) . 's',
            'upload-max'   => (string) ini_get('upload_max_filesize'),
            'post-max'     => (string) ini_get('post_max_size'),
            'timezone'     => date_default_timezone_get(),
            'time'         => date('Y-m-d H:i:s'),
        ];

        if ($opcache) {
            $used = (int) ($opcache['memory_usage']['used_memory'] ?? 0);
            $free = (int) ($opcache['memory_usage']['free_memory'] ?? 0);

            $out['opcache'] = ($opcache['opcache_enabled'] ?? false) ? 'on' : 'off';
            $out['opcache-memory'] = $used + $free > 0
                ? File::humanFileSize($used) . ' / ' . File::humanFileSize($used + $free)
                : null;
            $out['opcache-hits'] = isset($opcache['opcache_statistics']['opcache_hit_rate'])
                ? round((float) $opcache['opcache_statistics']['opcache_hit_rate'], 1) . '%'
                : null;
        } else {
            $out['opcache'] = 'off';
        }

        return $out;
    }

    /**
     * @return array
     */
    private static function server(): array
    {
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;

        return [
            'os'       => PHP_OS_FAMILY . ' · ' . php_uname('r'),
            'host'     => php_uname('n'),
            'arch'     => php_uname('m'),
            'software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? '—'),
            'cpus'     => (string) (self::cpus() ?: '—'),

            # Windows has no load average and PHP says so by not having the
            # function. An empty row is more honest than three zeroes.
            'load'     => $load ? implode('  ', array_map(fn($n) => number_format((float) $n, 2), $load)) : null,
            'uptime'   => self::uptime(),
        ];
    }

    /**
     * Physical memory, where the operating system will say.
     *
     * @return array
     */
    private static function memory(): array
    {
        $out = [
            'php-usage' => File::humanFileSize(memory_get_usage(true)),
            'php-peak'  => File::humanFileSize(memory_get_peak_usage(true)),
        ];

        $meminfo = self::meminfo();

        if ($meminfo) {
            $total     = (int) ($meminfo['MemTotal'] ?? 0);
            $available = (int) ($meminfo['MemAvailable'] ?? $meminfo['MemFree'] ?? 0);

            $out['total']     = File::humanFileSize($total);
            $out['available'] = File::humanFileSize($available);
            $out['used']      = File::humanFileSize(max(0, $total - $available));
            $out['share']     = $total > 0 ? round((1 - $available / $total) * 100) . '%' : null;

            if (isset($meminfo['SwapTotal']) && (int) $meminfo['SwapTotal'] > 0) {
                $out['swap'] = File::humanFileSize((int) $meminfo['SwapTotal'] - (int) ($meminfo['SwapFree'] ?? 0))
                    . ' / ' . File::humanFileSize((int) $meminfo['SwapTotal']);
            }
        }

        return $out;
    }

    /**
     * @return array
     */
    private static function database(): array
    {
        try {
            $version = (new DB)->prepare('SELECT VERSION() AS v')->fetch(\PDO::FETCH_ASSOC)['v'] ?? null;
        } catch (\Throwable $thrown) {
            return ['version' => null, 'error' => $thrown->getMessage()];
        }

        return ['version' => $version];
    }

    /**
     * /proc/meminfo, in bytes, or null where there is no such thing.
     *
     * @return array|null
     */
    private static function meminfo(): ?array
    {
        if (!is_readable('/proc/meminfo')) return null;

        $out = [];

        foreach (explode("\n", (string) @file_get_contents('/proc/meminfo')) as $line) {
            if (!preg_match('/^(\w+):\s+(\d+)\s*kB/i', $line, $match)) continue;

            # Reported in kibibytes.
            $out[$match[1]] = (int) $match[2] * 1024;
        }

        return count($out) ? $out : null;
    }

    /**
     * How many processors, where it can be counted without asking a shell.
     *
     * @return int|null
     */
    private static function cpus(): ?int
    {
        if (is_readable('/proc/cpuinfo')) {
            $count = preg_match_all('/^processor\s*:/mi', (string) @file_get_contents('/proc/cpuinfo'));

            if ($count) return $count;
        }

        # Windows sets it for every process.
        $env = getenv('NUMBER_OF_PROCESSORS');

        return $env ? (int) $env : null;
    }

    /**
     * How many bytes this application is holding on a given disk.
     *
     * Counted from the rows for an object disk - the counters the write path
     * maintains - and measured for the two directories that have no rows.
     *
     * @param string $name
     * @param string $role
     * @return int|null
     */
    private static function usedOn(string $name, string $role): ?int
    {
        try {
            if ($role === 'objects') {
                $row = (new DB)->prepare(
                    'SELECT COALESCE(SUM(size), 0) AS bytes FROM cdn_objects WHERE disk = :disk',
                    ['disk' => $name]
                )->fetch(\PDO::FETCH_ASSOC);

                return (int) ($row['bytes'] ?? 0);
            }
        } catch (\Throwable $thrown) {
            return null;
        }

        $root = $role === 'variants' ? Storage::variantRoot() : Storage::tempRoot();

        return is_dir($root) ? (int) (Storage::measure($root)['bytes'] ?? 0) : null;
    }

    /**
     * @return string|null
     */
    private static function uptime(): ?string
    {
        if (!is_readable('/proc/uptime')) return null;

        $seconds = (int) (float) strtok((string) @file_get_contents('/proc/uptime'), ' ');

        if ($seconds <= 0) return null;

        $days  = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        return $days ? "{$days}d {$hours}h" : "{$hours}h";
    }

    /**
     * The extensions this application actually uses, and whether they are here.
     *
     * Not every loaded extension - that is a hundred rows nobody reads. These
     * are the ones something in the CDN asks for, so a missing one explains a
     * feature that is not working.
     *
     * @return array<string,bool>
     */
    public static function extensions(): array
    {
        $wanted = [
            'gd', 'imagick', 'exif',
            'apcu', 'redis',
            'fileinfo', 'curl', 'zip',
            'openssl', 'mbstring', 'intl',
            'pdo_mysql', 'zlib', 'sodium',
        ];

        $out = [];

        foreach ($wanted as $name) $out[$name] = extension_loaded($name);

        return $out;
    }

    /**
     * Every disk the CDN writes to, with what is left on it.
     *
     * @return array
     */
    public static function disks(): array
    {
        # `role` is the answer to "what is `local`" - the name is whatever
        # config calls a disk, and a name on its own tells nobody whether it is
        # a folder on this machine, a second volume, or something to do with the
        # database. It is none of the last: every one of these is a directory,
        # and the free space is the filesystem's, shared with whatever else
        # lives on that volume.
        $roots = [];

        foreach ((array) Support::config('storage.disks', []) as $name => $disk) {
            $roots[$name] = ['root' => (string) ($disk['root'] ?? ''), 'role' => 'objects'];
        }

        $roots['variants'] = ['root' => Storage::variantRoot(), 'role' => 'variants'];
        $roots['temp']     = ['root' => Storage::tempRoot(), 'role' => 'temp'];

        $out = [];

        foreach ($roots as $name => $disk) {
            $root = $disk['root'];

            if ($root === '') continue;

            $exists = is_dir($root);
            $total  = $exists ? @disk_total_space($root) : false;
            $free   = $exists ? @disk_free_space($root) : false;

            # Which filesystem it is on. Three directories under one storage
            # root are three rows saying the same free space, which reads as
            # three disks with a terabyte each. Grouped by device, it is one
            # volume with three directories on it - which is what it is.
            $stat = $exists ? @stat($root) : false;

            $out[$name] = [
                'root'     => $root,
                'role'     => $disk['role'],
                'device'   => $stat === false ? null : (string) ($stat['dev'] ?? ''),
                'exists'   => $exists,
                'writable' => $exists ? is_writable($root) : null,
                'total'    => $total === false ? null : (int) $total,
                'free'     => $free === false ? null : (int) $free,
                'share'    => ($total && $free !== false) ? (int) round((1 - $free / $total) * 100) : null,

                # What this application has put there, which is a different
                # question from what is left on the volume.
                'used'     => self::usedOn($name, $disk['role']),
            ];
        }

        return $out;
    }
}
