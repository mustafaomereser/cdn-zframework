<?php

namespace App\Cdn;

use App\Models\Cdn\Buckets;
use App\Models\Cdn\Files;
use App\Models\Cdn\Projects;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Session;
use zFramework\Core\Facades\Str;

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
     * One project when the switcher has one selected, all of them otherwise.
     * The panel used to show every project's files, buckets and traffic mixed
     * together, which is readable with one project and unreadable with three.
     *
     * @return array
     */
    public static function projectIds(): array
    {
        if ($selected = self::selected()) return [(int) $selected['id']];

        return array_map('intval', array_column(self::projects(), 'id'));
    }

    /**
     * The project the switcher is pointing at, or null for all of them.
     *
     * Resolved against what the account owns rather than taken from the session
     * as given: the session is the user's own, but a stale id from before a
     * project was deleted would otherwise scope every query to nothing.
     *
     * @return array|null
     */
    public static function selected(): ?array
    {
        $id = (int) (Session::get('cdn-project') ?? 0);
        if (!$id) return null;

        foreach (self::projects() as $project) if ((int) $project['id'] === $id) return $project;

        return null;
    }

    /**
     * Point the switcher at a project, or at all of them.
     *
     * @param string|int|null $id
     * @return void
     */
    public static function select(string|int|null $id): void
    {
        $id = (int) $id;

        if (!$id) {
            Session::delete('cdn-project');
            return;
        }

        # Resolved here so an id that is not this account's never reaches the
        # session in the first place.
        Session::set('cdn-project', (int) self::project($id)['id']);
    }

    /**
     * What the account is using, and what it is allowed.
     *
     * Used is summed over the projects, because that is where the counters are
     * maintained. The allowance comes from the account: a quota of 0 is
     * unlimited, and the sidebar then shows a figure and no bar rather than a
     * bar measured against a ceiling that is not there.
     *
     * @return array{used:int,quota:int,bandwidth:int,bandwidth-quota:int}
     */
    public static function usage(): array
    {
        $used = $bandwidth = 0;
        $month = date('Y-m');

        # Across every project, whatever the switcher is pointing at: this is
        # the account's bill, and it does not change because somebody is looking
        # at one project.
        foreach (self::projects() as $project) {
            # Except an axis the project has its own number for. It is measured
            # against its own ceiling, so counting it here too would spend the
            # shared allowance twice - give a project 50 GB and the account it
            # belongs to would watch its own 5 GB fill up with bytes that are
            # not charged to it.
            #
            # The two are separate: a project can have its own disk allowance
            # and still share the account's transfer.
            if (($project['storage_mode'] ?? 'account') !== 'custom') $used += (int) $project['storage_used'];

            if (($project['bandwidth_mode'] ?? 'account') === 'custom') continue;

            # The counter belongs to a month. A row still carrying last month's
            # period reads as zero rather than being reset here - a read path
            # does not write to fix bookkeeping.
            if (($project['bandwidth_period'] ?? null) === $month) $bandwidth += (int) $project['bandwidth_used'];
        }

        # The allowance is the account's. It used to be the sum of the projects'
        # quotas, which made creating a project a way of granting yourself
        # another five gigabytes.
        $allowance = self::allowance(Auth::user() ?: []);

        return [
            'used'            => $used,
            'bandwidth'       => $bandwidth,
            'quota'           => $allowance['storage'],
            'bandwidth-quota' => $allowance['bandwidth'],
        ];
    }

    /**
     * The same numbers, narrowed to whatever the switcher is pointing at.
     *
     * The sidebar reads this. With a project selected it shows that project -
     * its bytes, and its own quota if it was given one - because everything
     * else on the page is that project and a total that is not would be read as
     * one that is. With "all projects" it is the account, which is what the
     * bill is.
     *
     * @return array{used:int,quota:int,bandwidth:int,bandwidth-quota:int,scope:string}
     */
    public static function scopedUsage(): array
    {
        $project = self::selected();

        if (!$project) return self::usage() + ['scope' => 'account'];

        $storageCustom   = ($project['storage_mode'] ?? 'account') === 'custom';
        $bandwidthCustom = ($project['bandwidth_mode'] ?? 'account') === 'custom';
        $account         = self::usage();

        return [
            'used'            => (int) $project['storage_used'],
            'bandwidth'       => ($project['bandwidth_period'] ?? null) === date('Y-m') ? (int) $project['bandwidth_used'] : 0,

            # An axis on the account's allowance shows the account's number: it
            # is the ceiling it will actually hit, and showing a copy of it as
            # though it were the project's own would say the account has one per
            # project - which is exactly the thing that is not true.
            'quota'           => $storageCustom ? (int) $project['storage_quota'] : $account['quota'],
            'bandwidth-quota' => $bandwidthCustom ? (int) $project['bandwidth_quota'] : $account['bandwidth-quota'],

            'scope'           => $storageCustom ? 'project' : 'shared',
            'bandwidth-scope' => $bandwidthCustom ? 'project' : 'shared',
        ];
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
        $first    = !count((new Projects)->where('owner_id', (int) $user['id'])->closureMode(false)->get());
        $name     = $name ?: (string) ($user['username'] ?? 'project');
        $quota    = self::allowance($user);

        $project = (new Projects)->insert([
            'name'            => self::uniqueName($name),
            'slug'            => self::uniqueProjectSlug($name, $user, $first),
            'owner_id'        => (int) $user['id'],

            # Every project of an account carries the same numbers. They are the
            # account's, not a grant that arrives with each new project.
            'storage_quota'   => $quota['storage'],
            'bandwidth_quota' => $quota['bandwidth'],
        ]);

        if ($bucket = (string) ($defaults['bucket'] ?? '')) {
            (new Buckets)->insert([
                'project_id'  => $project['id'],
                'name'        => ucfirst($bucket),

                # Only has to be unique inside the project, so a new account
                # gets exactly the name it asked for.
                'slug'        => Str::slug($bucket),
                'signing_key' => Support::token(24),
            ]);
        }

        # A project just appeared; the memoised list is out of date.
        self::$projects = null;

        return $project;
    }

    /**
     * The account's allowance, in bytes. 0 is unlimited.
     *
     * Three places to look, and it writes back what it works out so there is
     * only one place next time:
     *
     *   1. The account's own columns.
     *   2. Its oldest project's - accounts that predate the columns have their
     *      number there, and reading 0 for those would hand every one of them
     *      an unlimited quota.
     *   3. What config gives a new account.
     *
     * @param array $user
     * @return array{storage:int,bandwidth:int}
     */
    public static function allowance(array $user): array
    {
        $storage   = (int) ($user['storage_quota'] ?? 0);
        $bandwidth = (int) ($user['bandwidth_quota'] ?? 0);

        if ($storage || $bandwidth) return ['storage' => $storage, 'bandwidth' => $bandwidth];

        $oldest = (new Projects)->where('owner_id', (int) $user['id'])->closureMode(false)->orderBy(['id' => 'ASC'])->first();

        if ($oldest && ((int) $oldest['storage_quota'] || (int) $oldest['bandwidth_quota'])) {
            $storage   = (int) $oldest['storage_quota'];
            $bandwidth = (int) $oldest['bandwidth_quota'];
        } else {
            $defaults  = (array) Support::config('auth.defaults', []);
            $storage   = (int) ($defaults['storage-quota'] ?? 0);
            $bandwidth = (int) ($defaults['bandwidth-quota'] ?? 0);
        }

        if ($storage || $bandwidth) {
            (new \App\Models\User)->where('id', $user['id'])->update([
                'storage_quota'   => $storage,
                'bandwidth_quota' => $bandwidth,
            ]);

            # Auth's copy of the row is what usage() reads on this request.
            Auth::flushRequestState();
        }

        return ['storage' => $storage, 'bandwidth' => $bandwidth];
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
    public static function uniqueProjectSlug(string $wanted, ?array $user = null, bool $first = false): string
    {
        $model = new Projects;
        $base  = Str::slug($wanted) ?: 'project';

        # Every project an account owns starts with the account's own slug: the
        # first one is that slug, the rest are `<account>-<name>`.
        #
        # Without it a url segment is first-come across the whole installation,
        # so somebody's second project can take `docs` from the person who
        # wanted it for their first. It also makes a url say whose it is, which
        # is what somebody reading a log line wants to know.
        if ($user) {
            $account = self::accountSlug($user);
            $base    = $first || $base === $account ? $account : $account . '-' . $base;
        }

        $slug = $base;

        while ($model->where('slug', $slug)->closureMode(false)->first()) $slug = $base . '-' . substr(Support::token(3), 0, 4);

        return $slug;
    }

    /**
     * The account's own slug: its oldest project's, or its username.
     *
     * Read from the project rather than recomputed, so an account whose
     * username changes keeps the prefix its existing urls already carry.
     *
     * @param array $user
     * @return string
     */
    public static function accountSlug(array $user): string
    {
        $oldest = (new Projects)->where('owner_id', (int) $user['id'])->closureMode(false)->orderBy(['id' => 'ASC'])->first();

        if ($oldest) return (string) $oldest['slug'];

        return Str::slug((string) ($user['username'] ?? 'project')) ?: 'project';
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
     * Whether this user administers the installation itself - accounts, quotas,
     * system health - rather than only their own project.
     *
     * Three ways to be one, in this order:
     *
     *   1. Listed in `auth.operators`. A file, so it cannot be revoked from a
     *      form - which is what makes it the way back in when the column below
     *      has left nobody holding the keys.
     *   2. users.is_operator, set from the operator panel.
     *   3. The first registered account. Somebody has to be, and on a fresh
     *      install there is nobody to grant it. This one stands down as soon as
     *      `auth.operators` names anybody.
     *
     * @param array|null $user Defaults to the signed-in one.
     * @return bool
     */
    public static function isOperator(?array $user = null): bool
    {
        $user ??= Auth::user() ?: [];
        if (!$user) return false;

        $operators = array_filter(array_map('strtolower', (array) Support::config('auth.operators', [])));

        if (count($operators)) return in_array(strtolower((string) ($user['email'] ?? '')), $operators, true);

        if ((int) ($user['is_operator'] ?? 0) === 1) return true;

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
