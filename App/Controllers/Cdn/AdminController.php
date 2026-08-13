<?php

namespace App\Controllers\Cdn;

use App\Cdn\Metrics;
use App\Cdn\Purger;
use App\Cdn\Registry;
use App\Cdn\Secret;
use App\Cdn\Signature;
use App\Cdn\Storage;
use App\Cdn\Support;
use App\Cdn\Tenant;
use App\Cdn\Transform;
use App\Cdn\Uploader;
use App\Models\Cdn\AccessLogs;
use App\Models\Cdn\ApiKeys;
use App\Models\Cdn\Buckets;
use App\Models\Cdn\Files;
use App\Models\Cdn\Purges;
use App\Models\Cdn\Variants;
use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\DB;
use zFramework\Core\Facades\Session;
use zFramework\Core\Validator;

/**
 * The panel.
 *
 * Everything here is scoped to the signed-in user's project through Tenant,
 * which resolves ids rather than trusting them. A method that reads an id
 * straight out of the URL and hands it to findOrFail is a method that serves
 * somebody else's files, and there is no amount of menu-hiding that fixes it.
 *
 * Five pages, deliberately: Overview, Files, Buckets, Keys, Activity. The
 * things a person does daily - upload something, copy its URL - are on the
 * first one, so the rest can be as detailed as they need to be.
 */
class AdminController
{
    /**
     * Overview: what is here, what it costs, and the two actions that make up
     * most of the day.
     *
     * @return mixed
     */
    public function dashboard(): mixed
    {
        $project = Tenant::project();
        $buckets = Tenant::buckets();

        $files = (new Files)
            ->where('project_id', $project['id'])
            ->orderBy(['id' => 'DESC'])
            ->limit(8)
            ->closureMode(false)
            ->get();

        # Today comes from the log rather than the rollup, which runs overnight.
        # Without this the dashboard looks dead every morning.
        $today = (new DB)->prepare(
            "SELECT COUNT(*) AS requests, COALESCE(SUM(bytes * weight), 0) AS bytes,
                    SUM(IF(cache = 'hit', 1, 0)) AS hits, SUM(IF(status >= 400, 1, 0)) AS errors
               FROM cdn_access_logs WHERE project_id = :project AND created_at >= :from",
            ['project' => $project['id'], 'from' => date('Y-m-d') . ' 00:00:00']
        )->fetch(\PDO::FETCH_ASSOC) ?: [];

        $series = Metrics::series(null, 30, (int) $project['id']);

        $totals = ['requests' => 0, 'bytes' => 0, 'hits' => 0, 'misses' => 0];
        foreach ($series as $day) foreach ($totals as $key => $value) $totals[$key] += (int) $day[$key];

        return view('cdn.pages.dashboard', compact('project', 'buckets', 'files', 'today', 'series', 'totals'));
    }

    #region Files
    /**
     * @return mixed
     */
    public function files(): mixed
    {
        $project = Tenant::project();
        $buckets = Tenant::buckets();

        $query = (new Files)->where('project_id', $project['id'])->closureMode(false);

        # The bucket filter is checked against this user's buckets, not taken as
        # given - otherwise it is a way to read another project's file list.
        if ($bucket = request('bucket')) $query->where('bucket_id', (int) Tenant::bucket((int) $bucket)['id']);
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
        $file     = Tenant::file($id);
        $bucket   = Tenant::bucket((int) $file['bucket_id']);
        $variants = (new Variants)->where('file_id', $file['id'])->closureMode(false)->orderBy(['hits' => 'DESC'])->get();

        return view('cdn.pages.files.show', [
            'file'     => $file,
            'bucket'   => $bucket,
            'variants' => $variants,
            'url'      => $this->url($bucket, $file['path']),
        ]);
    }

    /**
     * Upload: files from the browser, or a URL for the server to fetch.
     *
     * @return mixed
     */
    public function upload(): mixed
    {
        $bucket = Tenant::bucket((string) request('bucket'));
        $prefix = trim((string) request('path'), '/');

        $results = [];

        if (!empty($_FILES['files']['name'][0])) {
            foreach (array_keys($_FILES['files']['name']) as $index) {
                $entry = array_combine(array_keys($_FILES['files']), array_column($_FILES['files'], $index));

                $results[] = Uploader::fromRequest($bucket, $entry, [
                    'path'        => $prefix ? $prefix . '/' . Support::slugName($entry['name']) : null,
                    'uploaded_by' => 'panel:' . Auth::id(),
                ]);
            }
        } elseif (request('url')) {
            $results[] = Uploader::fromUrl($bucket, (string) request('url'), [
                'path'        => $prefix ? $prefix . '/' . Support::slugName(basename((string) parse_url(request('url'), PHP_URL_PATH))) : null,
                'uploaded_by' => 'panel:' . Auth::id(),
            ]);
        } else {
            Alerts::danger('Choose a file, or give a URL to fetch.');
            return back();
        }

        Registry::forgetBucket($bucket['slug']);

        $done = array_values(array_filter($results, fn($result) => $result['ok']));

        if (count($done)) Alerts::success(count($done) . ' file(s) uploaded.');
        foreach ($results as $result) if (!$result['ok']) Alerts::danger($this->reason($result));

        # Straight to the file when it is the only one: the next thing wanted is
        # always its URL.
        if (count($done) === 1 && count($results) === 1) return redirect(route('cdn-admin.files.show', ['id' => $done[0]['file']['id']]));

        return back();
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function fileDelete(string $id): mixed
    {
        $file = Tenant::file($id);
        Uploader::delete($file);

        Alerts::success('Deleted ' . $file['path'] . '.');

        return redirect(route('cdn-admin.files'));
    }
    #endregion

    #region Buckets
    /**
     * @return mixed
     */
    public function buckets(): mixed
    {
        return view('cdn.pages.buckets.index', ['buckets' => Tenant::buckets(), 'project' => Tenant::project()]);
    }

    /**
     * @param string|null $id
     * @return mixed
     */
    public function bucketForm(?string $id = null): mixed
    {
        return view('cdn.pages.buckets.form', ['bucket' => $id ? Tenant::bucket($id) : []]);
    }

    /**
     * @return mixed
     */
    public function bucketSave(): mixed
    {
        $project = Tenant::project();
        $model   = new Buckets;
        $id      = request('id');

        Validator::validate($_REQUEST, [
            'name' => ['required', 'max:120'],
            'slug' => ['required', 'max:120'],
        ]);

        $slug     = \zFramework\Core\Facades\Str::slug((string) request('slug'));
        $existing = $id ? Tenant::bucket($id) : null;

        # Globally unique, across every project: the slug is the first path
        # segment of every public url, so a collision reroutes somebody else's
        # traffic. Checked without a project filter for exactly that reason.
        $clash = $model->where('slug', $slug)->closureMode(false)->first();
        if ($clash && (!$existing || (int) $clash['id'] !== (int) $existing['id'])) {
            Alerts::danger("`$slug` is already taken - try another.");
            return back();
        }

        $columns = [
            'name'          => request('name'),
            'slug'          => $slug,
            'visibility'    => in_array(request('visibility'), ['public', 'signed', 'private'], true) ? request('visibility') : 'public',
            'cache_ttl'     => max(0, (int) request('cache_ttl')),
            'transform'     => request('transform') ? 1 : 0,
            'immutable'     => request('immutable') ? 1 : 0,
            'signed_only'   => request('signed_only') ? 1 : 0,
            'max_file_size' => max(0, (int) request('max_file_size')),
            'origin_url'    => request('origin_url') ?: null,
            'origin_ttl'    => max(0, (int) request('origin_ttl')) ?: 86400,
            'allowed_ext'   => $this->list(request('allowed_ext')),
            'allowed_mimes' => $this->list(request('allowed_mimes')),
            'cors'          => $this->list(request('cors')),
            'referers'      => json_encode([
                'mode'        => in_array(request('referer_mode'), ['off', 'allow', 'deny'], true) ? request('referer_mode') : 'off',
                'list'        => array_values(array_filter(array_map('trim', explode(',', (string) request('referer_list'))))),
                'allow-empty' => (bool) request('referer_empty'),
            ]),
        ];

        if ($existing) {
            $model->where('id', $existing['id'])->update($columns);
            Registry::forgetBucket($existing['slug']);
            Registry::forgetBucket($slug);
            Alerts::success('Bucket saved.');
        } else {
            $model->insert($columns + [
                'project_id'  => $project['id'],
                # Per bucket, so one bucket's signed links can be invalidated
                # later without touching the others.
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
        $bucket = Tenant::bucket($id);

        # The files go first, so their bytes lose their references and the
        # collector can reclaim them. Dropping the bucket alone would leave the
        # objects referenced forever by rows nothing can reach.
        $files = (new Files)->where('bucket_id', $bucket['id'])->closureMode(false)->get();
        foreach ($files as $file) Uploader::delete($file);

        Purger::bucket($bucket, 'panel:' . Auth::id());
        (new Buckets)->where('id', $bucket['id'])->delete();
        Registry::forgetBucket($bucket['slug']);

        Alerts::success('Bucket deleted, with ' . count($files) . ' file(s).');

        return redirect(route('cdn-admin.buckets'));
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function bucketPurge(string $id): mixed
    {
        $result = Purger::bucket(Tenant::bucket($id), 'panel:' . Auth::id());

        Alerts::success("Cleared {$result['variants']} generated image(s), freed " . \zFramework\Core\Helpers\File::humanFileSize($result['bytes']) . '.');

        return back();
    }
    #endregion

    #region Keys
    /**
     * @return mixed
     */
    public function keys(): mixed
    {
        $project = Tenant::project();

        # Shown once, on the redirect after creation, then dropped. Not
        # JustOneTime: every request ends by clearing that store outright, so a
        # value written before a redirect is gone before the page that would
        # display it runs.
        $created = Session::get('cdn-new-key') ?: null;
        if ($created) Session::delete('cdn-new-key');

        return view('cdn.pages.keys', [
            'keys'    => (new ApiKeys)->where('project_id', $project['id'])->closureMode(false)->orderBy(['id' => 'DESC'])->get(),
            'buckets' => Tenant::buckets(),
            'created' => $created,
            'project' => $project,
        ]);
    }

    /**
     * @return mixed
     */
    public function keyCreate(): mixed
    {
        $project = Tenant::project();

        Validator::validate($_REQUEST, ['name' => ['required', 'max:120']]);

        $access = 'cdn_' . Support::token(12);
        $secret = Support::token(24);

        # Only this project's buckets can be attached, whatever ids were posted.
        $mine    = array_column(Tenant::buckets(), 'id');
        $buckets = array_values(array_intersect(array_map('intval', (array) (request('buckets') ?: [])), array_map('intval', $mine)));

        (new ApiKeys)->insert([
            'project_id'    => $project['id'],
            'name'          => request('name'),
            'access_key'    => $access,
            'secret_hash'   => password_hash($secret, PASSWORD_BCRYPT),
            'secret_cipher' => Secret::seal($secret),
            'scopes'        => json_encode(array_values(array_intersect(
                (array) (request('scopes') ?: ['read']),
                ['read', 'upload', 'delete', 'purge']
            ))),
            'buckets'     => count($buckets) ? json_encode($buckets) : null,
            'allowed_ips' => $this->list(request('allowed_ips')),
            'expires_at'  => request('expires_at') ?: null,
        ], just_insert: true);

        Session::set('cdn-new-key', ['access' => $access, 'secret' => $secret]);

        return redirect(route('cdn-admin.keys'));
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function keyRevoke(string $id): mixed
    {
        $key = (new ApiKeys)->where('project_id', Tenant::projectId())->where('id', (int) $id)->closureMode(false)->first();
        if (!$key) abort(404);

        (new ApiKeys)->where('id', $key['id'])->update(['status' => 'revoked']);
        Alerts::success('Key revoked. Anything using it stops working now.');

        return back();
    }
    #endregion

    /**
     * Activity: what was served, and what was cleared.
     *
     * The two used to be separate pages. They answer the same question - "what
     * happened, and why does the site look like this" - so they are one page
     * with two tabs.
     *
     * @return mixed
     */
    public function activity(): mixed
    {
        $project = Tenant::project();

        $query = (new AccessLogs)->where('project_id', $project['id'])->closureMode(false);

        if ($bucket = request('bucket')) $query->where('bucket_id', (int) Tenant::bucket((int) $bucket)['id']);
        if ($cache = request('cache'))   $query->where('cache', (string) $cache);
        if (request('errors'))           $query->where('status', '>=', 400);

        return view('cdn.pages.activity', [
            'logs'    => $query->orderBy(['id' => 'DESC'])->paginate(40),
            'purges'  => (new Purges)->where('project_id', $project['id'])->closureMode(false)->orderBy(['id' => 'DESC'])->limit(30)->get(),
            'buckets' => Tenant::buckets(),
        ]);
    }

    /**
     * Settings: the project, and - for an operator - the installation.
     *
     * The second half is the useful one to an operator: `transform.formats`
     * listing avif means nothing if this build of PHP cannot write it, and that
     * mismatch is otherwise only discovered as an image that silently never
     * converts.
     *
     * @return mixed
     */
    public function settings(): mixed
    {
        $project = Tenant::project();
        $system  = null;

        if (Tenant::isOperator()) {
            $disks = [];
            foreach ((array) Support::config('storage.disks', []) as $name => $disk) {
                $disks[$name] = [
                    'root'     => $disk['root'] ?? null,
                    'writable' => is_dir($disk['root'] ?? '') ? is_writable($disk['root']) : null,
                    'free'     => Storage::freeSpace($name),
                ];
            }

            $system = [
                'capabilities' => [
                    'driver'  => Transform::driver(),
                    'formats' => array_combine(
                        ['jpg', 'png', 'gif', 'webp', 'avif'],
                        array_map(fn($format) => Transform::supports($format), ['jpg', 'png', 'gif', 'webp', 'avif'])
                    ),
                    'apcu'  => function_exists('apcu_fetch'),
                    'redis' => \zFramework\Core\Facades\Redis::available('cache'),
                    'finfo' => class_exists('finfo'),
                ],
                'disks'    => $disks,
                'variants' => Storage::measure(Storage::variantRoot()),
                'projects' => (new \App\Models\Cdn\Projects)->closureMode(false)->orderBy(['storage_used' => 'DESC'])->get(),
            ];
        }

        return view('cdn.pages.settings', compact('project', 'system'));
    }

    /**
     * Rename the project.
     *
     * @return mixed
     */
    public function settingsSave(): mixed
    {
        Validator::validate($_REQUEST, ['name' => ['required', 'max:120']]);

        (new \App\Models\Cdn\Projects)->where('id', Tenant::projectId())->update(['name' => request('name')]);
        Alerts::success('Saved.');

        return back();
    }

    /**
     * A servable url for a file, signed when the bucket needs it.
     *
     * @param array  $bucket
     * @param string $path
     * @return string
     */
    private function url(array $bucket, string $path): string
    {
        if (($bucket['visibility'] ?? 'public') === 'public')
            return host() . rtrim((string) Support::config('delivery.url-prefix', '/cdn'), '/') . '/' . $bucket['slug'] . '/' . $path;

        return Signature::url($bucket['slug'], $path, ['bucket' => $bucket, 'ttl' => 3600]);
    }

    /**
     * An upload failure in words somebody can act on.
     *
     * @param array $result
     * @return string
     */
    private function reason(array $result): string
    {
        $message = match ($result['error'] ?? '') {
            'extension-blocked'       => 'That file type cannot be uploaded - it is one that can execute on a server.',
            'extension-not-allowed'   => 'This bucket does not accept that file type.',
            'mime-not-allowed'        => 'This bucket does not accept that content type.',
            'too-large'               => 'That file is too large.',
            'storage-quota-exceeded'  => 'Your storage quota is full.',
            'remote-disabled'         => 'Fetching by URL is turned off.',
            'private-address'         => 'That URL points inside a private network.',
            'invalid-path'            => 'That path is not usable.',
            'already-exists'          => 'Something is already stored at that path.',
            default                   => 'Upload failed (' . ($result['error'] ?? 'unknown') . ').',
        };

        return $message . (isset($result['message']) ? ' ' . $result['message'] : '');
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
