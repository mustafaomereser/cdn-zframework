<?php

namespace App\Controllers\Cdn;

use App\Cdn\Flash;
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
use App\Models\Cdn\Projects;
use App\Models\Cdn\Purges;
use App\Models\Cdn\Variants;
use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\DB;
use zFramework\Core\Facades\Response;
use zFramework\Core\Facades\Session;
use zFramework\Core\Helpers\Http;
use zFramework\Core\Validator;

/**
 * The panel.
 *
 * Everything here is scoped through Tenant, which resolves ids rather than
 * trusting them. A method that reads an id straight out of the URL and hands it
 * to findOrFail is a method that serves somebody else's files, and there is no
 * amount of menu-hiding that fixes it.
 *
 * A user may own several projects. The panel shows all of them together -
 * files, keys and activity across the lot - and asks which one only where the
 * answer changes something: creating a bucket, and issuing a key.
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
        $projects = Tenant::projects();
        $buckets  = Tenant::buckets();

        # Everything else on this page is the selected project; the numbers at
        # the top of it are too.
        $usage    = Tenant::scopedUsage();

        $files = (new Files)
            ->whereIn('project_id', Tenant::projectIds())
            ->orderBy(['id' => 'DESC'])
            ->limit(8)
            ->closureMode(false)
            ->get();

        # Today comes from the log rather than the rollup, which runs overnight.
        # Without this the dashboard looks dead every morning.
        $ids   = implode(',', Tenant::projectIds()) ?: '0';
        $today = (new DB)->prepare(
            "SELECT COUNT(*) AS requests, COALESCE(SUM(bytes * weight), 0) AS bytes,
                    SUM(IF(cache = 'hit', 1, 0)) AS hits, SUM(IF(status >= 400, 1, 0)) AS errors
               FROM cdn_access_logs WHERE project_id IN ($ids) AND created_at >= :from",
            ['from' => date('Y-m-d') . ' 00:00:00']
        )->fetch(\PDO::FETCH_ASSOC) ?: [];

        $series = [];
        foreach (Tenant::projectIds() as $id) {
            foreach (Metrics::series(null, 30, $id) as $day) {
                $series[$day['date']] ??= ['date' => $day['date'], 'requests' => 0, 'bytes' => 0, 'hits' => 0, 'misses' => 0, 'errors' => 0];
                foreach (['requests', 'bytes', 'hits', 'misses', 'errors'] as $column) $series[$day['date']][$column] += (int) $day[$column];
            }
        }

        ksort($series);
        $series = array_values($series);

        $totals = ['requests' => 0, 'bytes' => 0, 'hits' => 0, 'misses' => 0];
        foreach ($series as $day) foreach ($totals as $key => $value) $totals[$key] += (int) $day[$key];

        return view('cdn.pages.dashboard', compact('projects', 'buckets', 'files', 'today', 'series', 'totals', 'usage'));
    }

    #region Files
    /**
     * @return mixed
     */
    public function files(): mixed
    {
        $buckets = Tenant::buckets();

        $query = (new Files)->whereIn('project_id', Tenant::projectIds())->closureMode(false);

        # The bucket filter is resolved through Tenant, not taken as given -
        # otherwise it is a way to read another project's file list.
        if ($bucket = request('bucket')) $query->where('bucket_id', (int) Tenant::bucket((int) $bucket)['id']);
        if ($search = request('q'))      $query->where('path', 'LIKE', '%' . $search . '%');

        $files = $query->orderBy(['id' => 'DESC'])->paginate(30);

        return view('cdn.pages.files.index', compact('files', 'buckets'));
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
            'project'  => Tenant::projectOf($bucket),
            'variants' => $variants,
            'url'      => $this->url($bucket, $file['path']),

            # Built here rather than estimated: a number somebody plans a
            # release around should be the real one. Null for anything that is
            # not css or js, and for a file minifying does not shrink.
            'minified' => \App\Cdn\Minifier::saving($file, $bucket),
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
            if (Http::isAjax()) return Response::json(['ok' => false, 'error' => _l('cdn.alerts.upload-empty')]);

            Flash::danger(_l('cdn.alerts.upload-empty'));

            return back();
        }

        Registry::forgetBucket($bucket);

        $done = array_values(array_filter($results, fn($result) => $result['ok']));

        # The uploader answers the browser directly, so it can show what
        # happened to each file next to the bar that was measuring it. The
        # redirect below is still the answer for a form post without javascript.
        if (Http::isAjax()) {
            return Response::json([
                'ok'    => count($done) > 0,
                'files' => array_map(fn($result) => $result['ok']
                    ? [
                        'ok'   => true,
                        'id'   => $result['file']['id'],
                        'name' => $result['file']['name'],
                        'path' => $result['file']['path'],
                        'size' => (int) $result['file']['size'],
                        'url'  => $this->url($bucket, $result['file']['path']),
                        'page' => route('cdn-admin.files.show', ['id' => $result['file']['id']]),
                    ]
                    : ['ok' => false, 'name' => $result['name'] ?? null, 'error' => $this->reason($result)],
                    $results),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (count($done)) Flash::success(_l('cdn.alerts.uploaded', ['count' => count($done)]));
        foreach ($results as $result) if (!$result['ok']) Flash::danger($this->reason($result));

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

        # A suspended project does not change. Otherwise "suspended" means the
        # urls are off and the account can still delete what it was suspended
        # over.
        if ($refusal = $this->refused(Tenant::bucket((int) $file['bucket_id']))) return $refusal;

        Uploader::delete($file);

        Flash::success(_l('cdn.alerts.file-deleted', ['path' => $file['path']]));

        return redirect(route('cdn-admin.files'));
    }
    #endregion

    #region Buckets
    /**
     * @return mixed
     */
    public function buckets(): mixed
    {
        return view('cdn.pages.buckets.index', [
            'buckets'  => Tenant::buckets(),
            'projects' => Tenant::projects(),
        ]);
    }

    /**
     * @param string|null $id
     * @return mixed
     */
    public function bucketForm(?string $id = null): mixed
    {
        return view('cdn.pages.buckets.form', [
            'bucket'   => $id ? Tenant::bucket($id) : [],
            'projects' => Tenant::projects(),
        ]);
    }

    /**
     * @return mixed
     */
    public function bucketSave(): mixed
    {
        $model = new Buckets;
        $id    = request('id');

        Validator::validate($_REQUEST, [
            'name' => ['required', 'max:120'],
            'slug' => ['required', 'max:120'],
        ]);

        $existing = $id ? Tenant::bucket($id) : null;

        # Resolved through Tenant so a posted project id that is not this user's
        # is a 404 rather than a bucket in somebody else's namespace. An existing
        # bucket keeps its project: moving one would change every url it serves,
        # which is a different operation than editing its settings.
        $project = $existing ? Tenant::projectOf($existing) : Tenant::project(request('project'));

        $slug = \zFramework\Core\Facades\Str::slug((string) request('slug'));

        # Unique within the project only. The url carries the project as its own
        # segment, so two accounts can both have "photos" and neither has to
        # accept a name with a random suffix on the end.
        $clash = $model->where('project_id', $project['id'])->where('slug', $slug)->closureMode(false)->first();

        if ($clash && (!$existing || (int) $clash['id'] !== (int) $existing['id'])) {
            Flash::danger(_l('cdn.alerts.bucket-taken', ['slug' => $slug, 'project' => $project['name']]));
            return back();
        }

        $columns = [
            'name'          => request('name'),
            'slug'          => $slug,
            'visibility'    => in_array(request('visibility'), ['public', 'signed', 'private'], true) ? request('visibility') : 'public',
            'cache_ttl'     => max(0, (int) request('cache_ttl')),
            'transform'     => request('transform') ? 1 : 0,
            'minify'        => request('minify') ? 1 : 0,
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

            # Both keys: the cached row is found under the old slug, and the new
            # one must not answer from a copy made before the rename.
            Registry::forgetBucket($existing);
            Registry::forgetBucket(['project_id' => $project['id'], 'slug' => $slug]);

            Flash::success(_l('cdn.alerts.bucket-saved'));
        } else {
            $model->insert($columns + [
                'project_id'  => $project['id'],
                # Per bucket, so one bucket's signed links can be invalidated
                # later without touching the others.
                'signing_key' => Support::token(24),
            ]);

            Flash::success(_l('cdn.alerts.bucket-created', ['path' => '/' . $project['slug'] . '/' . $slug]));
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

        if ($refusal = $this->refused($bucket)) return $refusal;

        # The files go first, so their bytes lose their references and the
        # collector can reclaim them. Dropping the bucket alone would leave the
        # objects referenced forever by rows nothing can reach.
        $files = (new Files)->where('bucket_id', $bucket['id'])->closureMode(false)->get();
        foreach ($files as $file) Uploader::delete($file);

        Purger::bucket($bucket, 'panel:' . Auth::id());
        (new Buckets)->where('id', $bucket['id'])->delete();
        Registry::forgetBucket($bucket);

        Flash::success(_l('cdn.alerts.bucket-deleted', ['count' => count($files)]));

        return redirect(route('cdn-admin.buckets'));
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function bucketPurge(string $id): mixed
    {
        $result = Purger::bucket(Tenant::bucket($id), 'panel:' . Auth::id());

        Flash::success(_l('cdn.alerts.purged', ['count' => $result['variants'], 'size' => \zFramework\Core\Helpers\File::humanFileSize($result['bytes'])]));

        return back();
    }
    #endregion

    #region Keys
    /**
     * @return mixed
     */
    public function keys(): mixed
    {
        # Shown once, on the redirect after creation, then dropped. Not
        # JustOneTime: every request ends by clearing that store outright, so a
        # value written before a redirect is gone before the page that would
        # display it runs.
        $created = Session::get('cdn-new-key') ?: null;
        if ($created) Session::delete('cdn-new-key');

        return view('cdn.pages.keys', [
            'keys'     => (new ApiKeys)->whereIn('project_id', Tenant::projectIds())->closureMode(false)->orderBy(['id' => 'DESC'])->get(),
            'buckets'  => Tenant::buckets(),
            'projects' => Tenant::projects(),
            'created'  => $created,
        ]);
    }

    /**
     * @return mixed
     */
    public function keyCreate(): mixed
    {
        Validator::validate($_REQUEST, ['name' => ['required', 'max:120']]);

        $project = Tenant::project(request('project'));

        $access = 'cdn_' . Support::token(12);
        $secret = Support::token(24);

        # Only buckets from the chosen project can be attached, whatever ids
        # were posted.
        $mine    = array_column(Tenant::buckets((int) $project['id']), 'id');
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

        Session::set('cdn-new-key', ['access' => $access, 'secret' => $secret, 'project' => $project['name']]);

        return redirect(route('cdn-admin.keys'));
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function keyRevoke(string $id): mixed
    {
        $key = (new ApiKeys)->whereIn('project_id', Tenant::projectIds())->where('id', (int) $id)->closureMode(false)->first();
        if (!$key) abort(404);

        (new ApiKeys)->where('id', $key['id'])->update(['status' => 'revoked']);
        Flash::success(_l('cdn.alerts.key-revoked'));

        return back();
    }
    #endregion

    /**
     * Activity: what was served, and what was cleared.
     *
     * @return mixed
     */
    public function activity(): mixed
    {
        $query = (new AccessLogs)->whereIn('project_id', Tenant::projectIds())->closureMode(false);

        if ($bucket = request('bucket')) $query->where('bucket_id', (int) Tenant::bucket((int) $bucket)['id']);
        if ($cache = request('cache'))   $query->where('cache', (string) $cache);
        if (request('errors'))           $query->where('status', '>=', 400);

        return view('cdn.pages.activity', [
            'logs'    => $query->orderBy(['id' => 'DESC'])->paginate(40),
            'purges'  => (new Purges)->whereIn('project_id', Tenant::projectIds())->closureMode(false)->orderBy(['id' => 'DESC'])->limit(30)->get(),
            'buckets' => Tenant::buckets(),
        ]);
    }

    #region Settings
    /**
     * Settings: the projects, and - for an operator - the installation.
     *
     * @return mixed
     */
    public function settings(): mixed
    {
        # The installation block that used to be here now has a page of its own
        # under Administration - it was the only thing on this page that had
        # nothing to do with the signed-in account.
        return view('cdn.pages.settings', [
            'projects' => Tenant::projects(),
            'usage'    => Tenant::usage(),
            'operator' => Tenant::isOperator(),
        ]);
    }

    /**
     * Add a project.
     *
     * A second project is a second namespace in the url, which is the reason to
     * want one: separating a staging site's assets from a live one's, or one
     * client's from another's.
     *
     * @return mixed
     */
    public function projects(): mixed
    {
        $projects = Tenant::projects();
        $ids      = array_map(fn($project) => (int) $project['id'], $projects);
        $usage    = Tenant::usage();
        $selected = Tenant::selected();
        $month    = date('Y-m');

        # One query for every bucket of every project, grouped after. A query per
        # project is a query per project, and this page exists to be opened.
        $buckets = count($ids)
            ? (new Buckets)->whereIn('project_id', $ids)->closureMode(false)->orderBy(['storage_used' => 'DESC'])->get()
            : [];

        $rows = [];

        foreach ($projects as $index => $project) {
            $own = array_values(array_filter($buckets, fn($bucket) => (int) $bucket['project_id'] === (int) $project['id']));

            $custom = ($project['quota_mode'] ?? 'account') === 'custom';

            $rows[] = $project + [
                'buckets'   => $own,
                'files'     => array_sum(array_map(fn($bucket) => (int) $bucket['files_count'], $own)),
                'used'      => (int) $project['storage_used'],
                'month'     => ($project['bandwidth_period'] ?? null) === $month ? (int) $project['bandwidth_used'] : 0,

                # The ceiling this one will actually hit, and whose it is.
                'quota'     => $custom ? (int) $project['storage_quota'] : (int) $usage['quota'],
                'own-quota' => $custom,

                # The first is the account's namespace: it cannot be renamed or
                # deleted, and saying so here saves opening it to find out.
                'main'      => $index === 0,
                'selected'  => $selected && (int) $selected['id'] === (int) $project['id'],
            ];
        }

        return view('cdn.pages.projects.index', [
            'rows'   => $rows,
            'usage'  => $usage,
            'prefix' => rtrim((string) Support::config('delivery.url-prefix', '/cdn'), '/'),
        ]);
    }

    /**
     * One project: its buckets, what it is using, and the two things that can
     * be done to it.
     *
     * The buckets are here because "which buckets are in this project" was a
     * question the panel could not answer - the bucket list was every bucket of
     * every project in one table.
     *
     * @param string $id
     * @return mixed
     */
    public function project(string $id): mixed
    {
        $project = Tenant::project($id);
        $buckets = (new Buckets)->where('project_id', $project['id'])->closureMode(false)->orderBy(['id' => 'ASC'])->get();

        return view('cdn.pages.projects.show', [
            'project' => $project,
            'buckets' => $buckets,
            'files'   => array_sum(array_map(fn($bucket) => (int) $bucket['files_count'], $buckets)),
            'month'   => ($project['bandwidth_period'] ?? null) === date('Y-m') ? (int) $project['bandwidth_used'] : 0,
            'usage'   => Tenant::usage(),
            # The first one is the account's namespace and cannot be deleted.
            'only'    => (int) Tenant::projects()[0]['id'] === (int) $project['id'],
            'prefix'  => rtrim((string) Support::config('delivery.url-prefix', '/cdn'), '/'),
        ]);
    }

    /**
     * @return mixed
     */
    public function projectForm(): mixed
    {
        return view('cdn.pages.projects.create', [
            'account' => Tenant::accountSlug(Auth::user() ?: []),
            'prefix'  => rtrim((string) Support::config('delivery.url-prefix', '/cdn'), '/'),
        ]);
    }

    /**
     * Add a project.
     *
     * A second project is a second namespace in the url, which is the reason to
     * want one: separating a staging site's assets from a live one's, or one
     * client's from another's. It brings no extra quota with it - that belongs
     * to the account.
     *
     * @return mixed
     */
    public function projectCreate(): mixed
    {
        Validator::validate($_REQUEST, ['name' => ['required', 'max:120']]);

        $name = trim((string) request('name'));

        # Names are unique across the installation, so a person choosing between
        # two of them in a dropdown never sees the same label twice. Said out
        # loud rather than silently suffixed - the name is theirs to pick.
        if ((new Projects)->where('name', $name)->closureMode(false)->first()) {
            Flash::danger(_l('cdn.alerts.name-taken', ['name' => $name]));
            return back();
        }

        $project = Tenant::create(Auth::user() ?: [], $name);

        Flash::success(_l('cdn.alerts.project-created', ['path' => '/' . $project['slug'] . '/']));

        return redirect(route('cdn-admin.projects.show', ['id' => $project['id']]));
    }

    /**
     * Delete a project, its buckets and its files.
     *
     * The files go one at a time through Uploader::delete rather than by
     * dropping rows: that is what releases each object's reference, and an
     * object nothing references is what the collector reclaims.
     *
     * @param string $id
     * @return mixed
     */
    public function projectDelete(string $id): mixed
    {
        $project = Tenant::project($id);

        # The first project is the account's own namespace - its slug is the
        # account slug, and every other project's is derived from it. The rest
        # can go, however many are left.
        if ((int) Tenant::projects()[0]['id'] === (int) $project['id']) {
            Flash::danger(_l('cdn.alerts.project-main'));

            return back();
        }

        if (($project['status'] ?? 'active') !== 'active') {
            Flash::danger(_l('cdn.upload-errors.project-suspended'));

            return back();
        }

        $files = (new Files)->where('project_id', $project['id'])->closureMode(false)->get();

        foreach ($files as $file) Uploader::delete($file);

        foreach ((new Buckets)->where('project_id', $project['id'])->closureMode(false)->get() as $bucket) {
            Purger::bucket($bucket, 'panel:' . Auth::id());
            Registry::forgetBucket($bucket);
        }

        (new Buckets)->where('project_id', $project['id'])->delete();
        (new ApiKeys)->where('project_id', $project['id'])->delete();
        (new Projects)->where('id', $project['id'])->delete();

        Registry::forgetProject((int) $project['id']);

        # The switcher may have been pointing at it.
        Tenant::select(null);
        Tenant::flushRequestState();

        Flash::success(_l('cdn.alerts.project-deleted', ['name' => $project['name'], 'files' => count($files)]));

        return redirect(route('cdn-admin.projects'));
    }

    /**
     * Point the panel at one project, or at all of them.
     *
     * @return mixed
     */
    public function projectSwitch(): mixed
    {
        Tenant::select(request('id'));

        # Back to the same page, now listing one project's rows - except from a
        # project's own page, which would be somebody else's after the switch.
        $back = (string) ($_SERVER['HTTP_REFERER'] ?? '');

        if (str_contains($back, '/projects/')) return redirect(route('cdn-admin.dashboard'));

        return back();
    }

    /**
     * Rename a project.
     *
     * The slug is not touched: it is in every URL the project has ever served,
     * and a rename is not meant to break them.
     *
     * @param string $id
     * @return mixed
     */
    public function projectSave(string $id): mixed
    {
        Validator::validate($_REQUEST, ['name' => ['required', 'max:120']]);

        $project = Tenant::project($id);
        $name    = trim((string) request('name'));

        # The main project's name is the account's namespace: every other
        # project's url name is derived from it, so it is as fixed as its slug.
        # Hidden in the form and refused here, because a hidden form is a form
        # somebody can still post to.
        if ((int) Tenant::projects()[0]['id'] === (int) $project['id']) {
            Flash::danger(_l('cdn.alerts.project-main'));

            return back();
        }

        $clash = (new Projects)->where('name', $name)->closureMode(false)->first();
        if ($clash && (int) $clash['id'] !== (int) $project['id']) {
            Flash::danger(_l('cdn.alerts.name-taken', ['name' => $name]));
            return back();
        }

        (new Projects)->where('id', $project['id'])->update(['name' => $name]);
        Registry::forgetProject((int) $project['id']);

        Flash::success(_l('cdn.alerts.saved'));

        return back();
    }
    #endregion

    /**
     * A servable url for a file, signed when the bucket needs it.
     *
     * @param array  $bucket
     * @param string $path
     * @return string
     */
    private function url(array $bucket, string $path): string
    {
        $project = Tenant::projectOf($bucket);

        if (($bucket['visibility'] ?? 'public') === 'public')
            return host() . rtrim((string) Support::config('delivery.url-prefix', '/cdn'), '/')
                . '/' . $project['slug'] . '/' . $bucket['slug'] . '/' . $path;

        return Signature::url($project['slug'], $bucket['slug'], $path, ['bucket' => $bucket, 'ttl' => 3600]);
    }

    /**
     * Refuse a write to a suspended project, in words.
     *
     * Reads are untouched by a suspension - the panel still lists everything -
     * so this is only on the paths that change something.
     *
     * @param array $bucket
     * @return mixed Null when it may proceed.
     */
    private function refused(array $bucket): mixed
    {
        if (!$reason = \App\Cdn\Guard::frozen($bucket)) return null;

        Flash::danger(_l('cdn.upload-errors.' . $reason));

        return back();
    }

    /**
     * An upload failure in words somebody can act on.
     *
     * @param array $result
     * @return string
     */
    private function reason(array $result): string
    {
        $error = (string) ($result['error'] ?? 'unknown');

        # A missing key renders as nothing at all, so the fallback names the code
        # rather than showing an empty toast for a failure nobody can then
        # report.
        $message = _l('cdn.upload-errors.' . $error)
            ?: _l('cdn.upload-errors.unknown', ['error' => $error]);

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
