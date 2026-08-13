<?php

namespace App\Cdn;

use App\Models\Cdn\Buckets;
use App\Models\Cdn\Files;
use App\Models\Cdn\Projects;
use zFramework\Core\Facades\Auth;

/**
 * Who the signed-in user is, and what belongs to them.
 *
 * A user owns projects; a project owns buckets. The project is a path segment
 * of its own - /cdn/<project>/<bucket>/<path> - which is what lets two people
 * both have a bucket called "photos" without one of them ending up with
 * "photos-32787".
 *
 * Every panel query goes through here. The rule is that an id from a URL is
 * never trusted: bucket(5) returns the bucket only if it belongs to one of this
 * user's projects, and 404s otherwise. Without that, multi-tenancy is just a
 * table with a column in it.
 */
class Tenant
{
    /**
     * Resolved once per request.
     */
    private static ?array $projects = null;

    /**
     * Every project this user owns, oldest first.
     *
     * The first one is created on demand rather than only at registration, so
     * an account that predates this - or one added straight to the database -
     * gets one the first time it opens the panel.
     *
     * @return array
     */
    public static function projects(): array
    {
        if (self::$projects !== null) return self::$projects;

        $user = Auth::user() ?: [];
        $id   = (int) ($user['id'] ?? 0);

        if (!$id) abort(401);

        $projects = (new Projects)->where('owner_id', $id)->closureMode(false)->orderBy(['id' => 'ASC'])->get();

        if (!count($projects)) $projects = [self::create($user)];

        return self::$projects = $projects;
    }

    /**
     * One project of this user's - the given id, or the first.
     *
     * @param string|int|null $id
     * @return array
     */
    public static function project(string|int|null $id = null): array
    {
        $projects = self::projects();

        if ($id === null || $id === '') return $projects[0];

        foreach ($projects as $project) if ((int) $project['id'] === (int) $id) return $project;

        abort(404);
    }

    /**
     * Ids for scoping a query. Everything the panel reads is filtered by this.
     *
     * @return array
     */
    public static function projectIds(): array
    {
        return array_map('intval', array_column(self::projects(), 'id'));
    }

    /**
     * Storage and transfer summed over every project the user owns.
     *
     * A quota of 0 is unlimited, and one unlimited project makes the total
     * unlimited - so the sidebar shows a figure and no bar rather than a bar
     * measured against a ceiling that is not there.
     *
     * @return array{used:int,quota:int,bandwidth:int}
     */
    public static function usage(): array
    {
        $used = $quota = $bandwidth = 0;
        $unlimited = false;

        foreach (self::projects() as $project) {
            $used      += (int) $project['storage_used'];
            $bandwidth += (int) $project['bandwidth_used'];

            if ((int) $project['storage_quota'] > 0) $quota += (int) $project['storage_quota'];
            else $unlimited = true;
        }

        return ['used' => $used, 'quota' => $unlimited ? 0 : $quota, 'bandwidth' => $bandwidth];
    }

    /**
     * Create a project for a user, with a first bucket unless that is turned
     * off - so the panel after registration has something in it rather than an
     * empty state and a form.
     *
     * @param array       $user
     * @param string|null $name Null uses the username, which is what happens at
     *                          registration.
     * @return array
     */
    public static function create(array $user, ?string $name = null): array
    {
        $defaults = (array) Support::config('auth.defaults', []);
        $name     = $name ?: (string) ($user['username'] ?? 'project');

        $project = (new Projects)->insert([
            'name'            => self::uniqueName($name),
            'slug'            => self::uniqueProjectSlug($name),
            'owner_id'        => (int) $user['id'],
            'storage_quota'   => (int) ($defaults['storage-quota'] ?? 0),
            'bandwidth_quota' => (int) ($defaults['bandwidth-quota'] ?? 0),
        ]);

        if ($bucket = (string) ($defaults['bucket'] ?? '')) {
            (new Buckets)->insert([
                'project_id'  => $project['id'],
                'name'        => ucfirst($bucket),

                # Only has to be unique inside the project, so a new account
                # gets exactly the name it asked for.
                'slug'        => \zFramework\Core\Facades\Str::slug($bucket),
                'signing_key' => Support::token(24),
            ]);
        }

        # A project just appeared; the memoised list is out of date.
        self::$projects = null;

        return $project;
    }

    /**
     * A project name nothing else is using.
     *
     * @param string $wanted
     * @return string
     */
    public static function uniqueName(string $wanted): string
    {
        $model = new Projects;
        $name  = trim($wanted) ?: 'Project';
        $base  = $name;
        $count = 2;

        while ($model->where('name', $name)->closureMode(false)->first()) {
            $name = "$base $count";
            $count++;
        }

        return $name;
    }

    /**
     * A project slug nothing else is using. Slugs are path segments, so this
     * one really is global.
     *
     * @param string $wanted
     * @return string
     */
    public static function uniqueProjectSlug(string $wanted): string
    {
        $model = new Projects;
        $base  = \zFramework\Core\Facades\Str::slug($wanted) ?: 'project';
        $slug  = $base;

        while ($model->where('slug', $slug)->closureMode(false)->first()) $slug = $base . '-' . substr(Support::token(3), 0, 4);

        return $slug;
    }

    /**
     * A bucket of this user's, by id. 404 otherwise.
     *
     * @param string|int $id
     * @return array
     */
    public static function bucket(string|int $id): array
    {
        $bucket = (new Buckets)
            ->whereIn('project_id', self::projectIds())
            ->where('id', (int) $id)
            ->closureMode(false)
            ->first();

        # 404 rather than 403: whether somebody else's bucket exists is not
        # something this user should be able to find out by trying ids.
        if (!$bucket) abort(404);

        return $bucket;
    }

    /**
     * Every bucket the user owns, across every project.
     *
     * @param int|null $projectId Narrow to one project.
     * @return array
     */
    public static function buckets(?int $projectId = null): array
    {
        $model = (new Buckets)->whereIn('project_id', $projectId ? [$projectId] : self::projectIds())->closureMode(false);

        return $model->orderBy(['project_id' => 'ASC', 'id' => 'ASC'])->get();
    }

    /**
     * A file of this user's. 404 otherwise.
     *
     * @param string|int $id
     * @return array
     */
    public static function file(string|int $id): array
    {
        $file = (new Files)
            ->whereIn('project_id', self::projectIds())
            ->where('id', (int) $id)
            ->closureMode(false)
            ->first();

        if (!$file) abort(404);

        return $file;
    }

    /**
     * The project a bucket belongs to, from the list already loaded.
     *
     * @param array $bucket
     * @return array
     */
    public static function projectOf(array $bucket): array
    {
        return self::project((int) $bucket['project_id']);
    }

    /**
     * Whether this user administers the installation itself - system health,
     * every project's usage - rather than only their own.
     *
     * The first account is an operator by default: somebody has to be, and on a
     * fresh install there is nobody to grant it.
     *
     * @return bool
     */
    public static function isOperator(): bool
    {
        $user = Auth::user() ?: [];
        if (!$user) return false;

        $operators = array_filter(array_map('strtolower', (array) Support::config('auth.operators', [])));

        if (count($operators)) return in_array(strtolower((string) ($user['email'] ?? '')), $operators, true);

        $first = (new \App\Models\User)->closureMode(false)->orderBy(['id' => 'ASC'])->first();

        return (int) ($first['id'] ?? 0) === (int) $user['id'];
    }

    /**
     * Drop what this request resolved. Only matters in a long-running worker.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$projects = null;
    }
}
