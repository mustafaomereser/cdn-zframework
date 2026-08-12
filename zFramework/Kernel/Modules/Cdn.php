<?php

namespace zFramework\Kernel\Modules;

use App\Cdn\Metrics;
use App\Cdn\Purger;
use App\Cdn\Registry;
use App\Cdn\Secret;
use App\Cdn\Signature;
use App\Cdn\Storage;
use App\Cdn\Support;
use App\Cdn\Uploader;
use App\Models\Cdn\ApiKeys;
use App\Models\Cdn\Buckets;
use App\Models\Cdn\Files;
use App\Models\Cdn\Projects;
use App\Models\Cdn\Uploads;
use zFramework\Core\Facades\DB;
use zFramework\Core\Helpers\File;
use zFramework\Kernel\Terminal;

/**
 * CDN operations from the command line.
 *
 * The panel is for looking; this is for the things that run on a schedule or
 * that nobody should have to click through - collecting orphans, rolling up a
 * day of traffic, checking that every row still has bytes behind it.
 */
class Cdn
{
    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::{Terminal::$commands[1]}();
    }

    /**
     * Description: Create the storage directories and a first project + bucket.
     * Usage: php terminal cdn setup [bucket=assets]
     */
    public static function setup()
    {
        Terminal::text('[color=yellow]Preparing storage…[/color]');

        $directories = [
            Storage::root(),
            Storage::variantRoot(),
            Storage::tempRoot(),
        ];

        foreach ($directories as $directory) {
            $created = Storage::ensureDirectory($directory);
            Terminal::text(($created ? '[color=green]ok  [/color] ' : '[color=red]fail[/color] ') . $directory);
        }

        # Storage lives outside the document root by design, but a
        # misconfiguration that puts it inside one is worth catching now rather
        # than when somebody fetches an object directly and skips every check.
        $public = str_replace('\\', '/', (string) (defined('PUBLIC_DIR') ? PUBLIC_DIR : base_path(config('app.public') ?: 'public_html')));
        if (strstr(str_replace('\\', '/', Storage::root()), $public))
            Terminal::text('[color=red]Warning: the object root is inside the public directory. Every access check can be bypassed by requesting the file directly.[/color]');

        $projects = new Projects;
        $project  = $projects->closureMode(false)->orderBy(['id' => 'ASC'])->first();

        if (!$project) {
            $project = $projects->insert(['name' => (string) (config('app.title') ?: 'CDN'), 'slug' => 'default']);
            Terminal::text("[color=green]Project created:[/color] {$project['slug']}");
        }

        $slug    = Terminal::$parameters['bucket'] ?? 'assets';
        $buckets = new Buckets;

        if (!$buckets->where('slug', $slug)->closureMode(false)->first()) {
            $buckets->insert([
                'project_id'  => $project['id'],
                'name'        => ucfirst($slug),
                'slug'        => $slug,
                'signing_key' => Support::token(24),
            ]);
            Terminal::text("[color=green]Bucket created:[/color] $slug");
        }

        Terminal::text("\n[color=cyan]Delivery:[/color] " . rtrim((string) config('cdn.delivery.url-prefix'), '/') . "/$slug/<path>");
        Terminal::text('[color=cyan]Panel:   [/color] ' . (config('cdn.admin.route') ?: '/cdn-admin'));
        Terminal::text('[color=cyan]Driver:  [/color] ' . \App\Cdn\Transform::driver());
    }

    /**
     * Description: Housekeeping - orphan objects, expired uploads, derivative eviction.
     * Usage: php terminal cdn gc [grace=3600]
     */
    public static function gc()
    {
        $config = (array) config('cdn.gc');

        if ($config['expired-uploads'] ?? true) {
            $model   = new Uploads;
            $expired = $model->where('expires_at', '<', date('Y-m-d H:i:s'))->where('status', '!=', 'completed')->closureMode(false)->get();

            foreach ($expired as $upload) @unlink($upload['temp_path']);
            if (count($expired)) $model->whereIn('id', array_column($expired, 'id'))->delete();

            Terminal::text('[color=green]uploads  [/color] ' . count($expired) . ' expired session(s) removed');
        }

        if ($config['variant-eviction'] ?? true) {
            $evicted = Purger::evict();
            Terminal::text('[color=green]variants [/color] ' . $evicted['evicted'] . ' evicted, ' . File::humanFileSize($evicted['bytes']) . ' freed');
        }

        if ($config['orphan-objects'] ?? true) {
            $collected = Purger::collect((int) (Terminal::$parameters['grace'] ?? 3600));
            Terminal::text('[color=green]objects  [/color] ' . $collected['deleted'] . ' collected, ' . File::humanFileSize($collected['bytes']) . ' freed');
        }

        # Anything left in temp that no session owns: a process that died between
        # writing the file and writing the row.
        $stale = 0;
        foreach (glob(Storage::tempRoot() . '/*') ?: [] as $file) {
            if (!is_file($file) || filemtime($file) > time() - 86400) continue;
            @unlink($file);
            $stale++;
        }
        Terminal::text("[color=green]temp     [/color] $stale abandoned file(s) removed");
    }

    /**
     * Description: Fold a day of access logs into cdn_stats.
     * Usage: php terminal cdn rollup [date=2026-08-12]
     */
    public static function rollup()
    {
        $date    = Terminal::$parameters['date'] ?? null;
        $written = Metrics::rollup($date);

        Terminal::text('[color=green]Rolled up[/color] ' . ($date ?: date('Y-m-d', strtotime('-1 day'))) . " - $written bucket row(s).");
    }

    /**
     * Description: Delete access logs past the retention window.
     * Usage: php terminal cdn prune [days=30]
     */
    public static function prune()
    {
        $days    = isset(Terminal::$parameters['days']) ? (int) Terminal::$parameters['days'] : null;
        $deleted = Metrics::prune($days);

        Terminal::text("[color=green]Pruned[/color] " . number_format($deleted) . ' log row(s).');
    }

    /**
     * Description: Check every file row against the disk, and recompute the counters.
     * Usage: php terminal cdn verify [--fix]
     */
    public static function verify()
    {
        $fix     = in_array('--fix', Terminal::$parameters);
        $files   = new Files;
        $missing = [];
        $checked = 0;

        foreach ($files->closureMode(false)->get() as $file) {
            $checked++;
            if (Storage::exists($file['storage_path'], $file['disk'])) continue;
            $missing[] = $file;
        }

        Terminal::text("[color=cyan]Checked[/color] " . number_format($checked) . ' file row(s).');

        if (count($missing)) {
            Terminal::text('[color=red]Missing bytes for ' . count($missing) . ' row(s):[/color]');
            foreach (array_slice($missing, 0, 20) as $file) Terminal::text('  ' . $file['path']);
            if (count($missing) > 20) Terminal::text('  … and ' . (count($missing) - 20) . ' more');

            if ($fix) {
                foreach ($missing as $file) $files->where('id', $file['id'])->update(['status' => 'quarantine']);
                Terminal::text('[color=yellow]Marked as quarantine; they now answer 404 instead of 410.[/color]');
            } else {
                Terminal::text('[color=dark-gray]Run with --fix to quarantine them.[/color]');
            }
        } else {
            Terminal::text('[color=green]Every row has its bytes.[/color]');
        }

        # The counters are maintained incrementally on the hot path, which is
        # the right trade there and means they can drift. This is the recount.
        if ($fix) {
            $db = new DB;
            $db->prepare("UPDATE cdn_buckets b SET
                            b.files_count  = (SELECT COUNT(*) FROM cdn_files f WHERE f.bucket_id = b.id AND f.deleted_at IS NULL),
                            b.storage_used = (SELECT COALESCE(SUM(f.size), 0) FROM cdn_files f WHERE f.bucket_id = b.id AND f.deleted_at IS NULL)");

            $db->prepare("UPDATE cdn_projects p SET
                            p.storage_used = (SELECT COALESCE(SUM(b.storage_used), 0) FROM cdn_buckets b WHERE b.project_id = p.id AND b.deleted_at IS NULL)");

            # Reference counts, against the file rows that actually exist. They
            # are maintained by increments, so a row removed outside the
            # application - a manual DELETE, a restore from an older dump -
            # leaves an object counted as wanted, and an object counted as
            # wanted is never collected. This is the reconciliation.
            $db->prepare("UPDATE cdn_objects o SET
                            o.refs = (SELECT COUNT(*) FROM cdn_files f WHERE f.hash = o.hash AND f.deleted_at IS NULL),
                            o.orphan_at = IF(
                                (SELECT COUNT(*) FROM cdn_files f WHERE f.hash = o.hash AND f.deleted_at IS NULL) = 0,
                                COALESCE(o.orphan_at, :now),
                                NULL
                            )", ['now' => date('Y-m-d H:i:s')]);

            $orphans = (int) ($db->prepare("SELECT COUNT(*) AS count FROM cdn_objects WHERE refs <= 0")->fetch(\PDO::FETCH_ASSOC)['count'] ?? 0);

            Terminal::text('[color=green]Counters recomputed.[/color] ' . ($orphans ? "[color=yellow]$orphans object(s) now unreferenced - `cdn gc` will collect them.[/color]" : ''));
        }
    }

    /**
     * Description: Invalidate derivatives.
     * Usage: php terminal cdn purge bucket=assets [prefix=images/] [path=logo.png]
     */
    public static function purge()
    {
        $slug   = Terminal::$parameters['bucket'] ?? null;
        $bucket = $slug ? (new Buckets)->where('slug', $slug)->closureMode(false)->first() : null;

        if (!$bucket) return Terminal::text('[color=red]bucket=<slug> is required and must exist.[/color]');

        $result = match (true) {
            isset(Terminal::$parameters['path'])   => Purger::path($bucket, Terminal::$parameters['path'], 'cli'),
            isset(Terminal::$parameters['prefix']) => Purger::prefix($bucket, Terminal::$parameters['prefix'], 'cli'),
            isset(Terminal::$parameters['tag'])    => Purger::tag($bucket, Terminal::$parameters['tag'], 'cli'),
            default => Purger::bucket($bucket, 'cli'),
        };

        Registry::forgetBucket($bucket['slug']);

        if (!($result['ok'] ?? false)) return Terminal::text('[color=red]' . ($result['error'] ?? 'failed') . '[/color]');

        Terminal::text('[color=green]Purged[/color] ' . ($result['variants'] ?? 0) . ' derivative(s), ' . File::humanFileSize($result['bytes'] ?? 0) . ' freed.');
    }

    /**
     * Description: Create, list or revoke an API key.
     * Usage: php terminal cdn key create name=deploy scopes=read,upload | key list | key revoke access=cdn_xxx
     */
    public static function key()
    {
        $action = Terminal::$commands[2] ?? 'list';
        $model  = new ApiKeys;

        if ($action === 'list') {
            $keys = $model->closureMode(false)->orderBy(['id' => 'DESC'])->get();

            if (!count($keys)) return Terminal::text('[color=yellow]No keys.[/color]');

            foreach ($keys as $key) {
                $scopes = implode(',', Support::json($key['scopes']) ?: ['read']);
                $state  = $key['status'] === 'active' ? 'green' : 'dark-gray';
                Terminal::text("[color=$state]{$key['access_key']}[/color]  {$key['name']}  [color=dark-gray]$scopes  " . number_format((int) $key['requests']) . ' req[/color]');
            }
            return;
        }

        if ($action === 'revoke') {
            $access = Terminal::$parameters['access'] ?? null;
            if (!$access) return Terminal::text('[color=red]access=<key> is required.[/color]');

            $model->where('access_key', $access)->update(['status' => 'revoked']);
            return Terminal::text('[color=green]Revoked.[/color]');
        }

        if ($action !== 'create') return Terminal::text('[color=red]key create | key list | key revoke[/color]');

        $project = (new Projects)->closureMode(false)->orderBy(['id' => 'ASC'])->first();
        if (!$project) return Terminal::text('[color=red]Run `php terminal cdn setup` first.[/color]');

        $access = 'cdn_' . Support::token(12);
        $secret = Support::token(24);

        $model->insert([
            'project_id'    => $project['id'],
            'name'          => Terminal::$parameters['name'] ?? 'cli',
            'access_key'    => $access,
            'secret_hash'   => password_hash($secret, PASSWORD_BCRYPT),
            'secret_cipher' => Secret::seal($secret),
            'scopes'        => json_encode(array_values(array_filter(explode(',', (string) (Terminal::$parameters['scopes'] ?? 'read'))))),
        ], just_insert: true);

        Terminal::text('[color=green]Key created. The secret is not stored in readable form - copy it now.[/color]');
        Terminal::text("[color=cyan]Access key:[/color] $access");
        Terminal::text("[color=cyan]Secret:    [/color] $secret");
    }

    /**
     * Description: Print a signed URL for a path.
     * Usage: php terminal cdn sign bucket=assets path=images/logo.png [ttl=3600]
     */
    public static function sign()
    {
        $slug   = Terminal::$parameters['bucket'] ?? null;
        $path   = Terminal::$parameters['path'] ?? null;
        $bucket = $slug ? (new Buckets)->where('slug', $slug)->closureMode(false)->first() : null;

        if (!$bucket || !$path) return Terminal::text('[color=red]bucket=<slug> and path=<path> are required.[/color]');

        $ttl = (int) (Terminal::$parameters['ttl'] ?? 3600);

        Terminal::text(Signature::url($bucket['slug'], $path, ['bucket' => $bucket, 'ttl' => $ttl]));
        Terminal::text('[color=dark-gray]Valid until ' . date('Y-m-d H:i:s', time() + $ttl) . '[/color]');
    }

    /**
     * Description: Import a directory into a bucket.
     * Usage: php terminal cdn import bucket=assets from=D:/images [prefix=images]
     */
    public static function import()
    {
        $slug   = Terminal::$parameters['bucket'] ?? null;
        $from   = Terminal::$parameters['from'] ?? null;
        $prefix = trim((string) (Terminal::$parameters['prefix'] ?? ''), '/');

        $bucket = $slug ? (new Buckets)->where('slug', $slug)->closureMode(false)->first() : null;

        if (!$bucket) return Terminal::text('[color=red]bucket=<slug> is required and must exist.[/color]');
        if (!$from || !is_dir($from)) return Terminal::text('[color=red]from=<directory> is required and must exist.[/color]');

        $from     = rtrim(str_replace('\\', '/', $from), '/');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS));

        $ok = 0;
        $failed = [];

        foreach ($iterator as $entry) {
            if (!$entry->isFile()) continue;

            $relative = ltrim(str_replace($from, '', str_replace('\\', '/', $entry->getPathname())), '/');
            $target   = ($prefix ? "$prefix/" : '') . $relative;

            # Copied to temp first: store() moves what it is given, and importing
            # must not empty the directory it is reading.
            $temporary = Storage::tempRoot() . '/import-' . Support::token(8) . '.part';
            if (!@copy($entry->getPathname(), $temporary)) {
                $failed[] = "$relative (copy failed)";
                continue;
            }

            $result = Uploader::store($bucket, $temporary, ['path' => $target, 'name' => $entry->getFilename(), 'uploaded_by' => 'cli:import']);

            if ($result['ok']) $ok++;
            else $failed[] = "$relative ({$result['error']})";
        }

        Registry::forgetBucket($bucket['slug']);

        Terminal::text("[color=green]Imported[/color] $ok file(s).");

        if (count($failed)) {
            Terminal::text('[color=yellow]Skipped ' . count($failed) . ':[/color]');
            foreach (array_slice($failed, 0, 20) as $line) Terminal::text("  $line");
        }
    }

    /**
     * Description: Storage, traffic and cache numbers.
     * Usage: php terminal cdn stats [days=7]
     */
    public static function stats()
    {
        $days   = (int) (Terminal::$parameters['days'] ?? 7);
        $series = Metrics::series(null, $days);

        $totals = ['requests' => 0, 'bytes' => 0, 'hits' => 0, 'misses' => 0];
        foreach ($series as $day) foreach ($totals as $key => $value) $totals[$key] += (int) $day[$key];

        $ratio = ($totals['hits'] + $totals['misses']) > 0 ? round($totals['hits'] / ($totals['hits'] + $totals['misses']) * 100, 1) . '%' : '—';

        Terminal::text("[color=cyan]Last $days day(s)[/color]");
        Terminal::text('  requests   ' . number_format($totals['requests']));
        Terminal::text('  transfer   ' . File::humanFileSize($totals['bytes']));
        Terminal::text('  hit ratio  ' . $ratio);

        $objects  = Storage::measure(Storage::root());
        $variants = Storage::measure(Storage::variantRoot());

        Terminal::text("\n[color=cyan]Storage[/color]");
        Terminal::text('  objects    ' . number_format($objects['files']) . ' files, ' . File::humanFileSize($objects['bytes']));
        Terminal::text('  variants   ' . number_format($variants['files']) . ' files, ' . File::humanFileSize($variants['bytes']));

        $free = Storage::freeSpace();
        if ($free !== null) Terminal::text('  free       ' . File::humanFileSize($free));
    }
}
