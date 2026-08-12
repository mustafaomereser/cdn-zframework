<?php

namespace App\Controllers\Cdn;

use App\Cdn\Metrics;
use App\Cdn\Purger;
use App\Cdn\Registry;
use App\Cdn\Secret;
use App\Cdn\Signature;
use App\Cdn\Storage;
use App\Cdn\Support;
use App\Cdn\Transform;
use App\Cdn\Uploader;
use App\Models\Cdn\AccessLogs;
use App\Models\Cdn\ApiKeys;
use App\Models\Cdn\Buckets;
use App\Models\Cdn\Files;
use App\Models\Cdn\Projects;
use App\Models\Cdn\Purges;
use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\DB;
use zFramework\Core\Validator;

/**
 * The operator panel.
 *
 * Everything the API can do, with a face on it, plus the things only a person
 * needs: what is filling the disk, which paths are being refused and why, what
 * a purge actually removed.
 */
class AdminController
{
    /**
     * The project this panel administers.
     *
     * Single-tenant by default - the first project, created on demand - because
     * an installation that never needs a second one should not have to think
     * about the concept at all.
     *
     * @return array
     */
    private function project(): array
    {
        $model   = new Projects;
        $project = $model->closureMode(false)->orderBy(['id' => 'ASC'])->first();

        if ($project) return $project;

        return $model->insert([
            'name' => (string) (config('app.title') ?: 'CDN'),
            'slug' => 'default',
            'owner_id' => Auth::id(),
        ]);
    }

    /**
     * @return mixed
     */
    public function dashboard(): mixed
    {
        $project = $this->project();
        $buckets = (new Buckets)->where('project_id', $project['id'])->closureMode(false)->orderBy(['storage_used' => 'DESC'])->get();

        $series = Metrics::series(null, 30);

        $totals = ['requests' => 0, 'bytes' => 0, 'hits' => 0, 'misses' => 0, 'errors' => 0];
        foreach ($series as $day) foreach ($totals as $key => $value) $totals[$key] += (int) $day[$key];

        # Today is not in cdn_stats yet - the rollup runs overnight - so it is
        # read from the log directly. Without this the dashboard looks dead
        # every morning until the cron fires.
        $today = (new DB)->prepare(
            "SELECT COUNT(*) AS requests, COALESCE(SUM(bytes * weight), 0) AS bytes,
                    SUM(IF(cache = 'hit', 1, 0)) AS hits, SUM(IF(status >= 400, 1, 0)) AS errors
               FROM cdn_access_logs WHERE created_at >= :from",
            ['from' => date('Y-m-d') . ' 00:00:00']
        )->fetch(\PDO::FETCH_ASSOC) ?: [];

        $variants = (new DB)->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(size), 0) AS bytes FROM cdn_variants")->fetch(\PDO::FETCH_ASSOC) ?: [];

        $popular = (new Files)
            ->where('project_id', $project['id'])
            ->orderBy(['downloads' => 'DESC'])
            ->limit(10)
            ->closureMode(false)
            ->get();

        return view('cdn.pages.dashboard', compact('project', 'buckets', 'series', 'totals', 'today', 'variants', 'popular'));
    }

    /**
     * @return mixed
     */
    public function buckets(): mixed
    {
        $project = $this->project();
        $buckets = (new Buckets)->where('project_id', $project['id'])->closureMode(false)->orderBy(['id' => 'DESC'])->get();

        return view('cdn.pages.buckets.index', compact('project', 'buckets'));
    }

    /**
     * @param string|null $id
     * @return mixed
     */
    public function bucketForm(?string $id = null): mixed
    {
        $bucket = $id ? (new Buckets)->closureMode(false)->findOrFail($id) : [];

        return view('cdn.pages.buckets.form', compact('bucket'));
    }

    /**
     * @return mixed
     */
    public function bucketSave(): mixed
    {
        $project = $this->project();
        $model   = new Buckets;
        $id      = request('id');

        Validator::validate($_REQUEST, [
            'name' => ['required', 'max:120'],
            'slug' => ['required', 'max:120'],
        ]);

        $slug = \zFramework\Core\Facades\Str::slug((string) request('slug'));

        # The slug is the first path segment of every URL in the bucket, so a
        # collision would silently reroute somebody else's traffic.
        $clash = $model->where('slug', $slug)->closureMode(false)->first();
        if ($clash && (!$id || (int) $clash['id'] !== (int) $id)) {
            Alerts::danger("`$slug` is already taken.");
            return back();
        }

        $columns = [
            'name'          => request('name'),
            'slug'          => $slug,
            'visibility'    => in_array(request('visibility'), ['public', 'signed', 'private'], true) ? request('visibility') : 'public',
            'cache_ttl'     => max(0, (int) request('cache_ttl')),
            'immutable'     => request('immutable') ? 1 : 0,
            'transform'     => request('transform') ? 1 : 0,
            'signed_only'   => request('signed_only') ? 1 : 0,
            'max_file_size' => max(0, (int) request('max_file_size')),
            'origin_url'    => request('origin_url') ?: null,
            'origin_ttl'    => max(0, (int) request('origin_ttl')) ?: 86400,
            'disk'          => request('disk') ?: 'local',
            'status'        => request('status') ?: 'active',
            'allowed_ext'   => $this->list(request('allowed_ext')),
            'allowed_mimes' => $this->list(request('allowed_mimes')),
            'cors'          => $this->list(request('cors')),
            'referers'      => json_encode([
                'mode'        => in_array(request('referer_mode'), ['off', 'allow', 'deny'], true) ? request('referer_mode') : 'off',
                'list'        => array_values(array_filter(array_map('trim', explode(',', (string) request('referer_list'))))),
                'allow-empty' => request('referer_empty') ? true : false,
            ]),
        ];

        if ($id) {
            $existing = $model->closureMode(false)->findOrFail($id);
            $model->where('id', $id)->update($columns);
            Registry::forgetBucket($existing['slug']);
            Registry::forgetBucket($slug);
            Alerts::success('Bucket updated.');
        } else {
            $model->insert($columns + [
                'project_id' => $project['id'],
                # Generated per bucket so one bucket's links can be invalidated
                # on their own, later, without touching the others.
                'signing_key' => Support::token(24),
            ]);
            Alerts::success('Bucket created.');
        }

        return redirect(route('cdn-admin.buckets'));
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function bucketDelete(string $id): mixed
    {
        $bucket = (new Buckets)->closureMode(false)->findOrFail($id);

        # Files first, so their bytes lose their references and the collector
        # can reclaim them. Deleting the bucket row alone would leave the
        # objects referenced forever by rows nothing can reach.
        $files = (new Files)->where('bucket_id', $bucket['id'])->closureMode(false)->get();
        foreach ($files as $file) Uploader::delete($file);

        Purger::bucket($bucket, 'panel:' . Auth::id());
        (new Buckets)->where('id', $bucket['id'])->delete();
        Registry::forgetBucket($bucket['slug']);

        Alerts::success('Bucket deleted with ' . count($files) . ' file(s).');

        return redirect(route('cdn-admin.buckets'));
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function bucketPurge(string $id): mixed
    {
        $bucket = (new Buckets)->closureMode(false)->findOrFail($id);
        $result = Purger::bucket($bucket, 'panel:' . Auth::id());

        Alerts::success("Purged {$result['variants']} derivative(s), freed " . \zFramework\Core\Helpers\File::humanFileSize($result['bytes']) . '.');

        return back();
    }

    /**
     * @return mixed
     */
    public function files(): mixed
    {
        $project = $this->project();
        $buckets = (new Buckets)->where('project_id', $project['id'])->closureMode(false)->get();

        $query = (new Files)->where('project_id', $project['id'])->closureMode(false);

        if ($bucket = request('bucket')) $query->where('bucket_id', (int) $bucket);
        if ($search = request('q'))      $query->where('path', 'LIKE', '%' . $search . '%');

        $files = $query->orderBy(['id' => 'DESC'])->paginate(30);

        return view('cdn.pages.files.index', compact('files', 'buckets', 'project'));
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function file(string $id): mixed
    {
        $file     = (new Files)->closureMode(false)->findOrFail($id);
        $bucket   = (new Buckets)->closureMode(false)->findOrFail((string) $file['bucket_id']);
        $variants = (new \App\Models\Cdn\Variants)->where('file_id', $file['id'])->closureMode(false)->orderBy(['hits' => 'DESC'])->get();

        $url = ($bucket['visibility'] === 'public')
            ? host() . rtrim((string) Support::config('delivery.url-prefix', '/cdn'), '/') . '/' . $bucket['slug'] . '/' . $file['path']
            : Signature::url($bucket['slug'], $file['path'], ['bucket' => $bucket, 'ttl' => 3600]);

        return view('cdn.pages.files.show', compact('file', 'bucket', 'variants', 'url'));
    }

    /**
     * @return mixed
     */
    public function upload(): mixed
    {
        $bucket = (new Buckets)->closureMode(false)->findOrFail((string) request('bucket'));

        $results = [];

        if (!empty($_FILES['files']['name'][0])) {
            foreach (array_keys($_FILES['files']['name']) as $index) {
                $entry = array_combine(array_keys($_FILES['files']), array_column($_FILES['files'], $index));
                $results[] = Uploader::fromRequest($bucket, $entry, [
                    'path'        => request('path') ? rtrim((string) request('path'), '/') . '/' . \App\Cdn\Support::slugName($entry['name']) : null,
                    'uploaded_by' => 'panel:' . Auth::id(),
                ]);
            }
        } elseif (request('url')) {
            $results[] = Uploader::fromUrl($bucket, (string) request('url'), ['uploaded_by' => 'panel:' . Auth::id()]);
        }

        Registry::forgetBucket($bucket['slug']);

        $ok = count(array_filter($results, fn($result) => $result['ok']));
        if ($ok) Alerts::success("$ok file(s) uploaded.");

        foreach ($results as $result) if (!$result['ok']) Alerts::danger($result['error'] . (isset($result['message']) ? " - {$result['message']}" : ''));

        return back();
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function fileDelete(string $id): mixed
    {
        $file = (new Files)->closureMode(false)->findOrFail($id);
        Uploader::delete($file);

        Alerts::success('File deleted.');

        return redirect(route('cdn-admin.files'));
    }

    /**
     * @return mixed
     */
    public function keys(): mixed
    {
        $project = $this->project();
        $keys    = (new ApiKeys)->where('project_id', $project['id'])->closureMode(false)->orderBy(['id' => 'DESC'])->get();
        $buckets = (new Buckets)->where('project_id', $project['id'])->closureMode(false)->get();

        # Shown once, on the redirect after creation, then dropped.
        #
        # Not JustOneTime: every request ends by clearing that store outright, so
        # a value written before a redirect is gone before the page that would
        # display it runs. Read-then-delete here is the behaviour that was
        # wanted, spelled out.
        $created = \zFramework\Core\Facades\Session::get('cdn-new-key') ?: null;
        if ($created) \zFramework\Core\Facades\Session::delete('cdn-new-key');

        return view('cdn.pages.keys', compact('keys', 'buckets', 'created', 'project'));
    }

    /**
     * @return mixed
     */
    public function keyCreate(): mixed
    {
        $project = $this->project();

        Validator::validate($_REQUEST, ['name' => ['required', 'max:120']]);

        $access = 'cdn_' . Support::token(12);
        $secret = Support::token(24);

        (new ApiKeys)->insert([
            'project_id'    => $project['id'],
            'name'          => request('name'),
            'access_key'    => $access,
            'secret_hash'   => password_hash($secret, PASSWORD_BCRYPT),
            'secret_cipher' => Secret::seal($secret),
            'scopes'        => json_encode(array_values(array_intersect(
                (array) (request('scopes') ?: ['read']),
                ['read', 'upload', 'delete', 'purge', 'admin']
            ))),
            'buckets'     => request('buckets') ? json_encode(array_map('intval', (array) request('buckets'))) : null,
            'allowed_ips' => $this->list(request('allowed_ips')),
            'expires_at'  => request('expires_at') ?: null,
        ], just_insert: true);

        # The only time the secret is readable outside the client. The keys page
        # displays it and deletes it in the same request.
        \zFramework\Core\Facades\Session::set('cdn-new-key', ['access' => $access, 'secret' => $secret]);

        Alerts::success('Key created. The secret is shown once.');

        return redirect(route('cdn-admin.keys'));
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function keyRevoke(string $id): mixed
    {
        (new ApiKeys)->where('id', $id)->update(['status' => 'revoked']);
        Alerts::success('Key revoked.');

        return back();
    }

    /**
     * @return mixed
     */
    public function logs(): mixed
    {
        $query = (new AccessLogs)->closureMode(false);

        if ($bucket = request('bucket')) $query->where('bucket_id', (int) $bucket);
        if ($status = request('status')) $query->where('status', (int) $status);
        if ($cache = request('cache'))   $query->where('cache', $cache);

        $logs    = $query->orderBy(['id' => 'DESC'])->paginate(50);
        $buckets = (new Buckets)->closureMode(false)->get();

        return view('cdn.pages.logs', compact('logs', 'buckets'));
    }

    /**
     * @return mixed
     */
    public function purges(): mixed
    {
        $purges = (new Purges)->closureMode(false)->orderBy(['id' => 'DESC'])->paginate(50);

        return view('cdn.pages.purges', compact('purges'));
    }

    /**
     * What is configured and what the machine can actually do.
     *
     * The second half is the useful one: `transform.formats` listing avif means
     * nothing if this build of PHP cannot write it, and that mismatch is
     * otherwise only discovered as an image that silently never converts.
     *
     * @return mixed
     */
    public function settings(): mixed
    {
        $capabilities = [
            'driver'  => Transform::driver(),
            'formats' => array_combine(
                ['jpg', 'png', 'gif', 'webp', 'avif'],
                array_map(fn($format) => Transform::supports($format), ['jpg', 'png', 'gif', 'webp', 'avif'])
            ),
            'apcu'    => function_exists('apcu_fetch'),
            'redis'   => \zFramework\Core\Facades\Redis::available('cache'),
            'finfo'   => class_exists('finfo'),
            'gzip'    => function_exists('gzencode'),
        ];

        $disks = [];
        foreach ((array) Support::config('storage.disks', []) as $name => $disk) {
            $disks[$name] = [
                'root'     => $disk['root'] ?? null,
                'writable' => is_dir($disk['root'] ?? '') ? is_writable($disk['root']) : null,
                'free'     => Storage::freeSpace($name),
            ];
        }

        $variants = Storage::measure(Storage::variantRoot());

        return view('cdn.pages.settings', compact('capabilities', 'disks', 'variants'));
    }

    /**
     * A comma separated field as a json column, or null when empty.
     *
     * @param mixed $value
     * @return string|null
     */
    private function list(mixed $value): ?string
    {
        $items = array_values(array_filter(array_map('trim', explode(',', (string) $value))));

        return count($items) ? json_encode($items) : null;
    }
}
