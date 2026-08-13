<?php

namespace App\Cdn;

use App\Models\Cdn\ApiKeys;
use App\Models\Cdn\Buckets;
use App\Models\Cdn\Files;
use App\Models\Cdn\Projects;
use App\Models\Cdn\Uploads;
use zFramework\Core\Facades\DB;
use zFramework\Core\Helpers\File;
use zFramework\Kernel\Terminal;

/**
 * CDN operations from the command line: `php cdn <command>`.
 *
 * A separate entry point rather than a `php terminal` module, because the
 * terminal only discovers modules inside zFramework/Kernel/Modules - and the
 * framework is a project of its own. An application-specific command living
 * in there would be application code the framework has to carry.
 *
 * It borrows the framework's Terminal only for colouring output; argument
 * parsing is here, since the two entry points do not share a dispatcher.
 *
 * This is for what runs on a schedule, or what nobody should have to click
 * through - collecting orphans, rolling up a day of traffic, checking that
 * every row still has bytes behind it. The panel is for looking.
 */
class Console
{
    /**
     * Positional arguments: [command, sub-command, …]
     */
    public static array $commands = [];

    /**
     * key=value arguments, and --flags under numeric keys.
     */
    public static array $parameters = [];

    /**
     * @param array $argv
     * @return void
     */
    /**
     * Every command this script has, derived from its own public methods.
     *
     * The same list `php cdn` prints, so the panel cannot drift from it.
     *
     * @return array
     */
    public static function commands(): array
    {
        $skip = ['begin', 'commands'];

        return array_values(array_diff(
            array_map(
                fn($method) => strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $method->name)),
                array_filter(
                    (new \ReflectionClass(self::class))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC),
                    fn($method) => $method->isPublic() && $method->isStatic() && $method->class === self::class
                )
            ),
            $skip
        ));
    }

    public static function begin(array $argv): void
    {
        array_shift($argv);

        foreach ($argv as $argument) {
            if (str_starts_with($argument, '--')) {
                self::$parameters[] = $argument;
                continue;
            }

            if (strstr($argument, '=')) {
                [$key, $value] = explode('=', $argument, 2);
                self::$parameters[$key] = $value;
                continue;
            }

            self::$commands[] = $argument;
        }

        $command = strtolower((string) (self::$commands[0] ?? ''));

        # The command list is derived from the methods rather than repeated in a
        # constant, so adding one cannot forget to register it.
        #
        # The filters are or-ed, not and-ed - getMethods(IS_PUBLIC|IS_STATIC)
        # returns everything that is either, so a private helper would be
        # offered as a command. Hence the explicit isPublic() below.
        $available = array_values(array_diff(
            array_map(
                fn($method) => $method->name,
                array_filter(
                    (new \ReflectionClass(self::class))->getMethods(),
                    fn($method) => $method->isPublic() && $method->isStatic()
                )
            ),
            ['begin']
        ));

        if (!in_array($command, $available, true)) {
            self::usage($command, $available);
            return;
        }

        self::{$command}();
    }

    /**
     * @param string $command
     * @param array  $available
     * @return void
     */
    private static function usage(string $command, array $available): void
    {
        if ($command !== '') Terminal::text("[color=red]`$command` is not a cdn command.[/color]\n");

        Terminal::text('[color=yellow]Usage:[/color] php cdn <command> [key=value] [--flag]');
        Terminal::text('');

        $help = [
            'setup'  => 'Create the storage directories and a first project + bucket.',
            'import' => 'Import a directory into a bucket.        bucket= from= [project=] [prefix=]',
            'key'    => 'Manage API keys.                         create name= scopes= | list | revoke access=',
            'sign'   => 'Print a signed URL.                      bucket= path= [project=] [ttl=]',
            'purge'  => 'Invalidate derivatives.                  bucket= [project=] [prefix= | path= | tag=]',
            'gc'     => 'Orphans, expired uploads, eviction.      [grace=3600]',
            'rollup' => 'Fold a day of access logs into stats.    [date=YYYY-MM-DD]',
            'prune'  => 'Delete access logs past retention.       [days=30]',
            'verify' => 'Check every row against the disk.        [--fix]',
            'stats'  => 'Storage, traffic and cache numbers.      [days=7]',
            'serve'  => 'Development server, with the router.     [host=] [port=8080]',
            'translate' => 'Machine-translate the interface.      lang=de|all [--force]',
        ];

        foreach ($available as $name) Terminal::text('  [color=green]' . str_pad($name, 8) . '[/color] ' . ($help[$name] ?? ''));
    }

    /**
     * Create the storage directories and a first project + bucket.
     *
     * @return void
     */
    public static function setup(): void
    {
        Terminal::text('[color=yellow]Preparing storage…[/color]');

        foreach ([Storage::root(), Storage::variantRoot(), Storage::tempRoot()] as $directory) {
            $created = Storage::ensureDirectory($directory);
            Terminal::text(($created ? '[color=green]ok  [/color] ' : '[color=red]fail[/color] ') . $directory);
        }

        # Storage lives outside the document root by design, but a
        # misconfiguration that puts it inside one is worth catching now rather
        # than when somebody fetches an object directly and skips every check.
        $public = str_replace('\\', '/', (string) base_path(config('app.public') ?: 'public_html'));
        if (strstr(str_replace('\\', '/', Storage::root()), $public))
            Terminal::text('[color=red]Warning: the object root is inside the public directory. Every access check can be bypassed by requesting the file directly.[/color]');

        $projects = new Projects;
        $project  = $projects->closureMode(false)->orderBy(['id' => 'ASC'])->first();

        if (!$project) {
            $project = $projects->insert(['name' => (string) (config('app.title') ?: 'CDN'), 'slug' => 'default']);
            Terminal::text("[color=green]Project created:[/color] {$project['slug']}");
        }

        $slug    = self::$parameters['bucket'] ?? 'assets';
        $buckets = new Buckets;

        if (!$buckets->where('project_id', $project['id'])->where('slug', $slug)->closureMode(false)->first()) {
            $buckets->insert([
                'project_id'  => $project['id'],
                'name'        => ucfirst($slug),
                'slug'        => $slug,
                'signing_key' => Support::token(24),
            ]);
            Terminal::text("[color=green]Bucket created:[/color] $slug");
        }

        Terminal::text("\n[color=cyan]Delivery:[/color] " . rtrim((string) config('cdn.delivery.url-prefix'), '/') . "/{$project['slug']}/$slug/<path>");
        Terminal::text('[color=cyan]Panel:   [/color] ' . (config('cdn.admin.route') ?: '/cdn-admin'));
        Terminal::text('[color=cyan]Driver:  [/color] ' . Transform::driver());
    }

    /**
     * Housekeeping - orphan objects, expired uploads, derivative eviction.
     *
     * @return void
     */
    public static function gc(): void
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
            $collected = Purger::collect((int) (self::$parameters['grace'] ?? 3600));
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
     * Fold a day of access logs into cdn_stats.
     *
     * @return void
     */
    public static function rollup(): void
    {
        $date    = self::$parameters['date'] ?? null;
        $written = Metrics::rollup($date);

        Terminal::text('[color=green]Rolled up[/color] ' . ($date ?: date('Y-m-d', strtotime('-1 day'))) . " - $written bucket row(s).");
    }

    /**
     * Delete access logs past the retention window.
     *
     * @return void
     */
    public static function prune(): void
    {
        $days    = isset(self::$parameters['days']) ? (int) self::$parameters['days'] : null;
        $deleted = Metrics::prune($days);

        Terminal::text('[color=green]Pruned[/color] ' . number_format($deleted) . ' log row(s).');
    }

    /**
     * Check every file row against the disk, and recompute the counters.
     *
     * @return void
     */
    public static function verify(): void
    {
        $fix     = in_array('--fix', self::$parameters, true);
        $files   = new Files;
        $missing = [];
        $checked = 0;

        foreach ($files->closureMode(false)->get() as $file) {
            $checked++;
            if (Storage::exists($file['storage_path'], $file['disk'])) continue;
            $missing[] = $file;
        }

        Terminal::text('[color=cyan]Checked[/color] ' . number_format($checked) . ' file row(s).');

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

        if (!$fix) return;

        # The counters are maintained incrementally on the hot path, which is the
        # right trade there and means they can drift. This is the recount.
        $db = new DB;

        $db->prepare("UPDATE cdn_buckets b SET
                        b.files_count  = (SELECT COUNT(*) FROM cdn_files f WHERE f.bucket_id = b.id AND f.deleted_at IS NULL),
                        b.storage_used = (SELECT COALESCE(SUM(f.size), 0) FROM cdn_files f WHERE f.bucket_id = b.id AND f.deleted_at IS NULL)");

        $db->prepare("UPDATE cdn_projects p SET
                        p.storage_used = (SELECT COALESCE(SUM(b.storage_used), 0) FROM cdn_buckets b WHERE b.project_id = p.id AND b.deleted_at IS NULL)");

        # Reference counts, against the file rows that actually exist. They are
        # maintained by increments, so a row removed outside the application - a
        # manual DELETE, a restore from an older dump - leaves an object counted
        # as wanted, and an object counted as wanted is never collected.
        $db->prepare("UPDATE cdn_objects o SET
                        o.refs = (SELECT COUNT(*) FROM cdn_files f WHERE f.hash = o.hash AND f.deleted_at IS NULL),
                        o.orphan_at = IF(
                            (SELECT COUNT(*) FROM cdn_files f WHERE f.hash = o.hash AND f.deleted_at IS NULL) = 0,
                            COALESCE(o.orphan_at, :now),
                            NULL
                        )", ['now' => date('Y-m-d H:i:s')]);

        $orphans = (int) ($db->prepare("SELECT COUNT(*) AS count FROM cdn_objects WHERE refs <= 0")->fetch(\PDO::FETCH_ASSOC)['count'] ?? 0);

        Terminal::text('[color=green]Counters recomputed.[/color] ' . ($orphans ? "[color=yellow]$orphans object(s) now unreferenced - `php cdn gc` will collect them.[/color]" : ''));

        # A project used to carry one flag for both quotas; it is two now, one
        # per axis. Where the flag is at its default and the number is not the
        # owner's, the number was deliberate - somebody set it - so the flag is
        # set to match rather than leaving a quota that the next account-level
        # edit would silently take away.
        $db->prepare("UPDATE cdn_projects p
                        JOIN users u ON u.id = p.owner_id
                         SET p.storage_mode = 'custom'
                       WHERE p.storage_mode = 'account' AND p.storage_quota <> u.storage_quota");

        $db->prepare("UPDATE cdn_projects p
                        JOIN users u ON u.id = p.owner_id
                         SET p.bandwidth_mode = 'custom'
                       WHERE p.bandwidth_mode = 'account' AND p.bandwidth_quota <> u.bandwidth_quota");

        $adopted = (int) ($db->prepare(
            "SELECT COUNT(*) AS count FROM cdn_projects WHERE storage_mode = 'custom' OR bandwidth_mode = 'custom'"
        )->fetch(\PDO::FETCH_ASSOC)['count'] ?? 0);

        if ($adopted) Terminal::text("[color=dark-gray]$adopted project(s) hold a quota of their own.[/color]");
    }

    /**
     * Invalidate derivatives.
     *
     * @return void
     */
    public static function purge(): void
    {
        $bucket = self::resolveBucket();

        if (!$bucket) return;

        $result = match (true) {
            isset(self::$parameters['path'])   => Purger::path($bucket, self::$parameters['path'], 'cli'),
            isset(self::$parameters['prefix']) => Purger::prefix($bucket, self::$parameters['prefix'], 'cli'),
            isset(self::$parameters['tag'])    => Purger::tag($bucket, self::$parameters['tag'], 'cli'),
            default => Purger::bucket($bucket, 'cli'),
        };

        Registry::forgetBucket($bucket);

        if (!($result['ok'] ?? false)) {

            Terminal::text('[color=red]' . ($result['error'] ?? 'failed') . '[/color]');

            return;

        }

        Terminal::text('[color=green]Purged[/color] ' . ($result['variants'] ?? 0) . ' derivative(s), ' . File::humanFileSize($result['bytes'] ?? 0) . ' freed.');
    }

    /**
     * Create, list or revoke an API key.
     *
     * @return void
     */
    public static function key(): void
    {
        $action = self::$commands[1] ?? 'list';
        $model  = new ApiKeys;

        if ($action === 'list') {
            $keys = $model->closureMode(false)->orderBy(['id' => 'DESC'])->get();

            if (!count($keys)) {

                Terminal::text('[color=yellow]No keys.[/color]');

                return;

            }

            foreach ($keys as $key) {
                $scopes = implode(',', Support::json($key['scopes']) ?: ['read']);
                $state  = $key['status'] === 'active' ? 'green' : 'dark-gray';
                Terminal::text("[color=$state]{$key['access_key']}[/color]  {$key['name']}  [color=dark-gray]$scopes  " . number_format((int) $key['requests']) . ' req[/color]');
            }
            return;
        }

        if ($action === 'revoke') {
            $access = self::$parameters['access'] ?? null;
            if (!$access) {
                Terminal::text('[color=red]access=<key> is required.[/color]');
                return;
            }

            $model->where('access_key', $access)->update(['status' => 'revoked']);
            Terminal::text('[color=green]Revoked.[/color]');
            return;
        }

        if ($action !== 'create') {

            Terminal::text('[color=red]php cdn key create | key list | key revoke[/color]');

            return;

        }

        $project = (new Projects)->closureMode(false)->orderBy(['id' => 'ASC'])->first();
        if (!$project) {
            Terminal::text('[color=red]Run `php cdn setup` first.[/color]');
            return;
        }

        $access = 'cdn_' . Support::token(12);
        $secret = Support::token(24);

        $model->insert([
            'project_id'    => $project['id'],
            'name'          => self::$parameters['name'] ?? 'cli',
            'access_key'    => $access,
            'secret_hash'   => password_hash($secret, PASSWORD_BCRYPT),
            'secret_cipher' => Secret::seal($secret),
            'scopes'        => json_encode(array_values(array_filter(explode(',', (string) (self::$parameters['scopes'] ?? 'read'))))),
        ], just_insert: true);

        Terminal::text('[color=green]Key created. The secret is not stored in readable form - copy it now.[/color]');
        Terminal::text("[color=cyan]Access key:[/color] $access");
        Terminal::text("[color=cyan]Secret:    [/color] $secret");
    }

    /**
     * Print a signed URL for a path.
     *
     * @return void
     */
    public static function sign(): void
    {
        $path   = self::$parameters['path'] ?? null;
        $bucket = self::resolveBucket();

        if (!$bucket) return;

        if (!$path) {
            Terminal::text('[color=red]path=<path> is required.[/color]');
            return;
        }

        $ttl = (int) (self::$parameters['ttl'] ?? 3600);

        # host() is empty under the CLI - there is no request to read a host from
        # - so the base has to be given or the url comes out relative.
        $host = rtrim((string) (self::$parameters['host'] ?? ''), '/');
        $project = (new Projects)->closureMode(false)->find((string) $bucket['project_id']);
        $url     = $host . Signature::url((string) $project['slug'], $bucket['slug'], $path, ['bucket' => $bucket, 'ttl' => $ttl]);

        Terminal::text($url);
        if ($host === '') Terminal::text('[color=dark-gray]Relative: pass host=https://cdn.example.com for an absolute url.[/color]');
        Terminal::text('[color=dark-gray]Valid until ' . date('Y-m-d H:i:s', time() + $ttl) . '[/color]');
    }

    /**
     * Import a directory into a bucket.
     *
     * @return void
     */
    public static function import(): void
    {
        $from   = self::$parameters['from'] ?? null;
        $prefix = trim((string) (self::$parameters['prefix'] ?? ''), '/');

        $bucket = self::resolveBucket();

        if (!$bucket) return;
        if (!$from || !is_dir($from)) {
            Terminal::text('[color=red]from=<directory> is required and must exist.[/color]');
            return;
        }

        $from     = rtrim(str_replace('\\', '/', $from), '/');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS));

        $ok     = 0;
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

        Registry::forgetBucket($bucket);

        Terminal::text("[color=green]Imported[/color] $ok file(s).");

        if (count($failed)) {
            Terminal::text('[color=yellow]Skipped ' . count($failed) . ':[/color]');
            foreach (array_slice($failed, 0, 20) as $line) Terminal::text("  $line");
        }
    }

    /**
     * The development server, started with a router script.
     *
     * `php terminal run` starts `php -S` with no router, and the built-in
     * server answers a path that is not a real file with its own 404 rather
     * than falling through to a front controller. Every CDN url ends in .png or
     * .mp4 and none of them are files in the document root, so none of them
     * would ever reach PHP.
     *
     * public_html/router.php closes that, and lives in the application because
     * a change to the framework's run module would not survive the next
     * framework release. In production the .htaccess rewrite - or nginx's
     * try_files - already does this job.
     *
     * @return void
     */
    public static function serve(): void
    {
        $public = base_path(config('app.public') ?: 'public_html');
        $router = $public . '/router.php';

        if (!is_file($router)) {
            Terminal::text('[color=red]public_html/router.php is missing - without it no asset url reaches PHP.[/color]');
            return;
        }

        $host = (string) (self::$parameters['host'] ?? '127.0.0.1');
        $port = (int) (self::$parameters['port'] ?? 8080);

        # Walk up until a port answers nothing, the way the framework's own
        # runner does - two of these on one machine is normal while testing.
        while (@fsockopen($host, $port, $errno, $error, 1)) {
            Terminal::text("[color=yellow]$port is taken, trying " . (++$port) . '.[/color]');
        }

        Terminal::text("[color=green]Serving[/color] http://$host:$port  [color=dark-gray](ctrl+c to stop)[/color]");
        Terminal::text('[color=dark-gray]Delivery:[/color] ' . rtrim((string) config('cdn.delivery.url-prefix'), '/') . '/<bucket>/<path>');
        Terminal::text('[color=dark-gray]Panel:   [/color] ' . (config('cdn.admin.route') ?: '/cdn-admin') . "\n");

        passthru('php -S ' . escapeshellarg("$host:$port") . ' -t ' . escapeshellarg($public) . ' ' . escapeshellarg($router));
    }

    /**
     * Machine-translate the interface into a language file.
     *
     * Once, here, rather than on every page in every visitor's browser. What
     * comes out is an ordinary locale: rendered by the server, cached like any
     * other page, and a file somebody can correct a line of.
     *
     * @return void
     */
    public static function translate(): void
    {
        $source = (string) (config('cdn.i18n.source') ?: 'en');
        $native = (array) config('cdn.i18n.native');
        $names  = (array) config('cdn.i18n.languages');
        $force  = in_array('--force', self::$parameters, true);

        $file = base_path("resource/lang/$source/cdn.php");

        if (!is_file($file)) {
            Terminal::text("[color=red]The source language file is missing: {$file}[/color]");
            return;
        }

        $strings = (array) include($file);
        $wanted  = self::$parameters['lang'] ?? null;

        if (!$wanted) {
            Terminal::text('[color=yellow]Usage:[/color] php cdn translate lang=de [--force]');
            Terminal::text('[color=yellow]      [/color] php cdn translate lang=all');
            Terminal::text('');
            Terminal::text('[color=dark-gray]' . Translator::count($strings) . " strings in resource/lang/$source/cdn.php[/color]");
            Terminal::text('[color=dark-gray]Available: [/color]' . implode(', ', array_keys($names)));
            return;
        }

        $targets = $wanted === 'all'
            ? array_values(array_diff(array_keys($names), $native))
            : [$wanted];

        foreach ($targets as $language) {
            if (in_array($language, $native, true)) {
                Terminal::text("[color=yellow]$language is hand-written - skipped. Edit the file directly.[/color]");
                continue;
            }

            if (!isset($names[$language])) {
                Terminal::text("[color=red]`$language` is not in i18n.languages - add it there first.[/color]");
                continue;
            }

            $target   = base_path("resource/lang/$language/cdn.php");
            $existing = is_file($target) ? (array) include($target) : [];

            $done  = 0;
            $delay = max(0, (int) config('cdn.i18n.translator.delay')) * 1000;

            Terminal::text("[color=cyan]{$language}[/color] [color=dark-gray]{$names[$language]}[/color]");

            $translated = Translator::walk($strings, $existing, $language, $force, function ($key, $before, $after) use (&$done, $delay) {
                $done++;

                # One line per string would scroll a terminal off its own
                # history; the count is what somebody watching wants.
                if ($done % 10 === 0) {
                    echo "\r  " . $done . ' translated…';
                    flush();
                }

                if ($delay) usleep($delay);
            });

            echo "\r";

            if (!$done) {
                Terminal::text('  [color=dark-gray]nothing to do - every string already has a value (--force to redo)[/color]');
                continue;
            }

            Translator::write($target, $translated, $language, $names[$language]);

            Terminal::text("  [color=green]$done translated[/color] [color=dark-gray]→ resource/lang/$language/cdn.php[/color]");
        }

        Terminal::text("\n[color=dark-gray]Generated files are a first draft. Correct a line and it stays: this command only fills in what is empty unless you pass --force.[/color]");
    }

    /**
     * Storage, traffic and cache numbers.
     *
     * @return void
     */
    public static function stats(): void
    {
        $days   = (int) (self::$parameters['days'] ?? 7);
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
    /**
     * The bucket a command is talking about.
     *
     * bucket=<slug> is unique inside a project, not across the installation, so
     * with more than one project the slug alone can be ambiguous. Rather than
     * picking one, it says which projects have that name and asks for
     * project=<slug>.
     *
     * @return array|null
     */
    private static function resolveBucket(): ?array
    {
        $slug = self::$parameters['bucket'] ?? null;

        if (!$slug) {
            Terminal::text('[color=red]bucket=<slug> is required.[/color]');
            return null;
        }

        $model = (new Buckets)->where('slug', $slug)->closureMode(false);

        if ($project = self::$parameters['project'] ?? null) {
            $row = (new Projects)->where('slug', $project)->closureMode(false)->first();

            if (!$row) {
                Terminal::text("[color=red]No project called `$project`.[/color]");
                return null;
            }

            $model->where('project_id', $row['id']);
        }

        $matches = $model->get();

        if (!count($matches)) {
            Terminal::text("[color=red]No bucket called `$slug`" . (isset($project) ? " in `$project`" : '') . '.[/color]');
            return null;
        }

        if (count($matches) === 1) return $matches[0];

        Terminal::text("[color=yellow]`$slug` exists in more than one project - add project=<slug>:[/color]");

        foreach ($matches as $match) {
            $owner = (new Projects)->closureMode(false)->find((string) $match['project_id']);
            Terminal::text('  ' . ($owner['slug'] ?? '?'));
        }

        return null;
    }
}