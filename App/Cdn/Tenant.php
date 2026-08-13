<?php

namespace App\Cdn;

use App\Models\Cdn\Buckets;
use App\Models\Cdn\Files;
use App\Models\Cdn\Projects;
use zFramework\Core\Facades\Auth;

/**
 * Who the signed-in user is, and what belongs to them.
 *
 * One project per user. Not because more would be hard, but because "your
 * files, your buckets, your keys" is a model somebody can hold in their head -
 * and a second layer of grouping is a concept every single page would then have
 * to explain.
 *
 * Every panel query goes through here. The rule is that an id from a URL is
 * never trusted: bucket(5) returns the bucket only if it is this user's, and
 * 404s otherwise. Without that, multi-tenancy is just a table with a column in
 * it.
 */
class Tenant
{
    /**
     * Resolved once per request.
     */
    private static ?array $project = null;

    /**
     * The signed-in user's project, created on first use.
     *
     * Created lazily rather than only at registration, so a user who existed
     * before this application became a CDN - or one added straight to the
     * database - still gets one the first time they open the panel.
     *
     * @return array
     */
    public static function project(): array
    {
        if (self::$project !== null) return self::$project;

        $model = new Projects;
        $user  = Auth::user() ?: [];
        $id    = (int) ($user['id'] ?? 0);

        if (!$id) abort(401);

        $project = $model->where('owner_id', $id)->closureMode(false)->first();

        if (!$project) $project = self::create($user);

        return self::$project = $project;
    }

    /**
     * Set a user up with a project and, unless that is turned off, a first
     * bucket - so the panel after registration has something in it rather than
     * an empty state and a form.
     *
     * @param array $user
     * @return array
     */
    public static function create(array $user): array
    {
        $defaults = (array) Support::config('auth.defaults', []);
        $name     = (string) ($user['username'] ?? 'project');

        $project = (new Projects)->insert([
            'name'            => $name,
            'slug'            => self::uniqueSlug($name, new Projects),
            'owner_id'        => (int) $user['id'],
            'storage_quota'   => (int) ($defaults['storage-quota'] ?? 0),
            'bandwidth_quota' => (int) ($defaults['bandwidth-quota'] ?? 0),
        ]);

        if ($bucket = (string) ($defaults['bucket'] ?? '')) {
            (new Buckets)->insert([
                'project_id'  => $project['id'],
                'name'        => ucfirst($bucket),

                # The slug is the first segment of every public url, so it has to
                # be unique across the whole installation - two users both
                # wanting "assets" is the normal case, not the edge case.
                'slug'        => self::uniqueSlug($bucket, new Buckets),
                'signing_key' => Support::token(24),
            ]);
        }

        return $project;
    }

    /**
     * A slug nothing else is using, with a short suffix when it is taken.
     *
     * @param string $wanted
     * @param object $model
     * @return string
     */
    private static function uniqueSlug(string $wanted, object $model): string
    {
        $base = \zFramework\Core\Facades\Str::slug($wanted) ?: 'cdn';
        $slug = $base;

        while ($model->where('slug', $slug)->closureMode(false)->first()) $slug = $base . '-' . substr(Support::token(3), 0, 5);

        return $slug;
    }

    /**
     * @return int
     */
    public static function projectId(): int
    {
        return (int) self::project()['id'];
    }

    /**
     * Whether this user administers the installation itself - system health,
     * every project's usage - rather than only their own project.
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
     * A bucket of this user's, by id or slug. 404 otherwise.
     *
     * @param string|int $identifier
     * @return array
     */
    public static function bucket(string|int $identifier): array
    {
        $model = (new Buckets)->where('project_id', self::projectId())->closureMode(false);

        $bucket = is_numeric($identifier)
            ? $model->where('id', (int) $identifier)->first()
            : $model->where('slug', (string) $identifier)->first();

        # 404 rather than 403: whether somebody else's bucket exists is not
        # something this user should be able to find out by trying ids.
        if (!$bucket) abort(404);

        return $bucket;
    }

    /**
     * @return array
     */
    public static function buckets(): array
    {
        return (new Buckets)->where('project_id', self::projectId())->closureMode(false)->orderBy(['id' => 'ASC'])->get();
    }

    /**
     * A file of this user's. 404 otherwise.
     *
     * @param string|int $id
     * @return array
     */
    public static function file(string|int $id): array
    {
        $file = (new Files)->where('project_id', self::projectId())->where('id', (int) $id)->closureMode(false)->first();

        if (!$file) abort(404);

        return $file;
    }

    /**
     * Drop what this request resolved. Only matters in a long-running worker.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$project = null;
    }
}
