<?php

namespace App\Cdn;

use App\Models\Cdn\ApiKeys;
use App\Models\Cdn\Audits;
use App\Models\Cdn\Buckets;
use App\Models\Cdn\Files;
use App\Models\Cdn\Projects;
use App\Models\Cdn\Webhooks;
use App\Models\User;
use zFramework\Core\Facades\Auth;

/**
 * Administering the installation rather than a project.
 *
 * Everything a project owner does goes through Tenant, which resolves ids
 * against the signed-in account and so cannot be talked into touching somebody
 * else's row. This class is the deliberate exception: it works across accounts,
 * which is why every method in it is behind the Operator middleware and why the
 * ones that change something write an audit row.
 *
 * The split matters. Tenant is safe because of what it cannot express; this is
 * safe because of who can reach it. Mixing the two would lose both.
 */
class Operator
{
    /**
     * Accounts, with what each of them is actually using.
     *
     * The totals are read from the project rows rather than counted from files:
     * they are maintained on the write path precisely so that a list like this
     * is one query rather than a scan of every object in the installation.
     *
     * @param int $perPage
     * @return array
     */
    public static function users(int $perPage = 30): array
    {
        $query = (new User)->closureMode(false);

        # The or-group goes on first on purpose. The builder takes a group's
        # connector to whatever came before it from the group's own first
        # condition, so a group added after a plain where would be ORed to it -
        # `status = active OR username LIKE …`, which is every account again.
        if ($search = request('q')) {
            $like = '%' . $search . '%';

            $query->whereOr([['username', 'LIKE', $like], ['email', 'LIKE', $like]]);
        }

        if (in_array($status = (string) request('status'), ['active', 'suspended'], true)) $query->where('status', $status);

        $users = $query->orderBy(['id' => 'DESC'])->paginate($perPage);

        $users['items'] = self::withUsage($users['items']);

        return $users;
    }

    /**
     * Attach each account's projects and totals.
     *
     * One query for the page rather than one per row - a hundred accounts on a
     * page is a hundred round trips otherwise, and it is the sort of thing that
     * only shows up once the installation has users on it.
     *
     * @param array $users
     * @return array
     */
    private static function withUsage(array $users): array
    {
        $ids = array_values(array_filter(array_map(fn($user) => (int) $user['id'], $users)));

        $projects = count($ids)
            ? (new Projects)->whereIn('owner_id', $ids)->closureMode(false)->get()
            : [];

        $byOwner = [];
        foreach ($projects as $project) $byOwner[(int) $project['owner_id']][] = $project;

        foreach ($users as &$user) {
            $own = $byOwner[(int) $user['id']] ?? [];

            $user['projects'] = $own;

            # Against the account's allowance, so a project with its own numbers
            # is left out of it - it is measured against its own ceiling, and
            # counting it twice is how an account with a 50 GB project watches
            # its own 5 GB fill up with bytes nobody charged to it.
            $shared = array_values(array_filter($own, fn($p) => ($p['quota_mode'] ?? 'account') !== 'custom'));

            $user['storage']   = array_sum(array_map(fn($p) => (int) $p['storage_used'], $shared));
            $user['bandwidth'] = array_sum(array_map(
                fn($p) => ($p['bandwidth_period'] ?? null) === date('Y-m') ? (int) $p['bandwidth_used'] : 0,
                $shared
            ));

            # What the account holds in total is a different number, and an
            # operator looking at a list of accounts wants both.
            $user['storage-total'] = array_sum(array_map(fn($p) => (int) $p['storage_used'], $own));

            # The allowance is the account's own, not a sum over its projects.
            # Accounts that predate the column read it from their oldest project
            # instead, which is what Tenant::allowance() does - and it writes it
            # back, so this is the last time that costs anything.
            $allowance = Tenant::allowance($user);

            $user['quota']           = $allowance['storage'];
            $user['bandwidth-quota'] = $allowance['bandwidth'];

            $user['operator'] = Tenant::isOperator($user);
        }

        return $users;
    }

    /**
     * One account.
     *
     * @param int|string $id
     * @return array
     */
    public static function user($id): array
    {
        $user = (new User)->closureMode(false)->where('id', (int) $id)->first();

        if (!$user) abort(404);

        return self::withUsage([$user])[0];
    }

    /**
     * Every project, newest first, with its owner attached.
     *
     * @param int $perPage
     * @return array
     */
    public static function projects(int $perPage = 30): array
    {
        $query = (new Projects)->closureMode(false);

        if ($search = request('q')) {
            $like = '%' . $search . '%';

            $query->whereOr([['name', 'LIKE', $like], ['slug', 'LIKE', $like]]);
        }

        $projects = $query->orderBy(['storage_used' => 'DESC'])->paginate($perPage);

        $owners = array_values(array_filter(array_map(fn($p) => (int) $p['owner_id'], $projects['items'])));

        $users = count($owners)
            ? (new User)->whereIn('id', $owners)->closureMode(false)->get()
            : [];

        $byId = [];
        foreach ($users as $user) $byId[(int) $user['id']] = $user;

        foreach ($projects['items'] as &$project) $project['owner'] = $byId[(int) $project['owner_id']] ?? null;

        return $projects;
    }

    /**
     * A project, by id, without the tenant scoping.
     *
     * @param int|string $id
     * @return array
     */
    public static function project($id): array
    {
        $project = (new Projects)->closureMode(false)->where('id', (int) $id)->first();

        if (!$project) abort(404);

        return $project;
    }

    /**
     * Everything under one account.
     *
     * The operator pages could list accounts and projects and nothing else -
     * which meant an operator could see that somebody was using 40 GB and had
     * no way to find out what of. This is the page that answers that.
     *
     * @param int|string $id
     * @return array
     */
    public static function account($id): array
    {
        $user     = self::user($id);
        $projects = (new Projects)->where('owner_id', $user['id'])->closureMode(false)->orderBy(['id' => 'ASC'])->get();
        $ids      = array_map(fn($project) => (int) $project['id'], $projects);

        $buckets = count($ids)
            ? (new Buckets)->whereIn('project_id', $ids)->closureMode(false)->orderBy(['storage_used' => 'DESC'])->get()
            : [];

        $files = count($ids)
            ? (new Files)->whereIn('project_id', $ids)->closureMode(false)->orderBy(['id' => 'DESC'])->limit(12)->get()
            : [];

        return [
            'user'     => $user,
            'projects' => self::withCounts($projects, $buckets),
            'buckets'  => $buckets,
            'files'    => $files,
            'keys'     => count($ids) ? count((new ApiKeys)->whereIn('project_id', $ids)->closureMode(false)->get()) : 0,
        ];
    }

    /**
     * One project, whoever owns it, with what is inside it.
     *
     * @param int|string $id
     * @return array
     */
    public static function projectDetail($id): array
    {
        $project = self::project($id);
        $owner   = (new User)->closureMode(false)->where('id', (int) $project['owner_id'])->first();

        $buckets = (new Buckets)->where('project_id', $project['id'])->closureMode(false)->orderBy(['storage_used' => 'DESC'])->get();
        $files   = (new Files)->where('project_id', $project['id'])->closureMode(false)->orderBy(['id' => 'DESC'])->limit(12)->get();

        return [
            'project' => $project,
            'owner'   => $owner,
            'buckets' => $buckets,
            'files'   => $files,
            'counts'  => [
                'files' => array_sum(array_map(fn($bucket) => (int) $bucket['files_count'], $buckets)),
                'keys'  => count((new ApiKeys)->where('project_id', $project['id'])->closureMode(false)->get()),
            ],
        ];
    }

    /**
     * Every file in the installation, newest first.
     *
     * Searchable by path, and filterable to one bucket - which is how an
     * operator answers "what is this account storing" without opening twelve
     * pages.
     *
     * @param int $perPage
     * @return array
     */
    public static function files(int $perPage = 40): array
    {
        $query = (new Files)->closureMode(false);

        if ($search = request('q')) $query->where('path', 'LIKE', '%' . $search . '%');
        if ($bucket = (int) request('bucket')) $query->where('bucket_id', $bucket);
        if ($project = (int) request('project')) $query->where('project_id', $project);

        $files = $query->orderBy(['id' => 'DESC'])->paginate($perPage);

        $bucketIds  = array_values(array_unique(array_map(fn($file) => (int) $file['bucket_id'], $files['items'])));
        $projectIds = array_values(array_unique(array_map(fn($file) => (int) $file['project_id'], $files['items'])));

        $buckets = count($bucketIds) ? (new Buckets)->whereIn('id', $bucketIds)->closureMode(false)->get() : [];
        $owners  = count($projectIds) ? (new Projects)->whereIn('id', $projectIds)->closureMode(false)->get() : [];

        $byBucket = $byProject = [];
        foreach ($buckets as $row) $byBucket[(int) $row['id']] = $row;
        foreach ($owners as $row)  $byProject[(int) $row['id']] = $row;

        foreach ($files['items'] as &$file) {
            $file['bucket']  = $byBucket[(int) $file['bucket_id']] ?? null;
            $file['project'] = $byProject[(int) $file['project_id']] ?? null;
        }

        return $files;
    }

    /**
     * Attach bucket and file counts to a list of projects.
     *
     * @param array $projects
     * @param array $buckets
     * @return array
     */
    private static function withCounts(array $projects, array $buckets): array
    {
        foreach ($projects as &$project) {
            $own = array_values(array_filter($buckets, fn($bucket) => (int) $bucket['project_id'] === (int) $project['id']));

            $project['buckets'] = count($own);
            $project['files']   = array_sum(array_map(fn($bucket) => (int) $bucket['files_count'], $own));
        }

        return $projects;
    }

    /**
     * Set an account's allowance. Bytes; 0 is unlimited.
     *
     * Written to the account and then down to its projects. The delivery path
     * has a project row in hand and nothing else, and a join to the owner on
     * the hottest query in the application to find a number that changes twice
     * a year is not a trade worth making.
     *
     * @param array $user
     * @param int   $storage
     * @param int   $bandwidth
     * @return void
     */
    public static function quota(array $user, int $storage, int $bandwidth): void
    {
        $storage   = max(0, $storage);
        $bandwidth = max(0, $bandwidth);

        (new User)->where('id', $user['id'])->update([
            'storage_quota'   => $storage,
            'bandwidth_quota' => $bandwidth,
        ]);

        foreach ((new Projects)->where('owner_id', $user['id'])->closureMode(false)->get() as $project) {
            # A project an operator gave its own numbers keeps them. Rewriting
            # those here is how a deliberate 50 GB quietly becomes the account's
            # 5 the next time somebody touches the account.
            if (($project['quota_mode'] ?? 'account') === 'custom') continue;

            (new Projects)->where('id', $project['id'])->update([
                'storage_quota'   => $storage,
                'bandwidth_quota' => $bandwidth,
            ]);

            # Otherwise a raised quota is not felt by the person who was refused
            # until registry-ttl expires.
            Registry::forgetProject((int) $project['id']);
        }

        self::audit('quota', 'user', $user, [
            'storage'   => [(int) ($user['storage_quota'] ?? 0), $storage],
            'bandwidth' => [(int) ($user['bandwidth_quota'] ?? 0), $bandwidth],
        ]);
    }

    /**
     * Give one project numbers of its own, or put it back on the account's.
     *
     * The flag is what makes this survive: without it there is no way to tell a
     * project that was given 50 GB on purpose from one that happens to match its
     * owner, and the next account-level edit takes it away.
     *
     * @param array $project
     * @param bool  $custom
     * @param int   $storage
     * @param int   $bandwidth
     * @return void
     */
    public static function projectQuota(array $project, bool $custom, int $storage, int $bandwidth): void
    {
        if (!$custom) {
            $owner = (new User)->closureMode(false)->where('id', (int) $project['owner_id'])->first() ?: [];

            $storage   = (int) ($owner['storage_quota'] ?? 0);
            $bandwidth = (int) ($owner['bandwidth_quota'] ?? 0);
        }

        (new Projects)->where('id', $project['id'])->update([
            'quota_mode'      => $custom ? 'custom' : 'account',
            'storage_quota'   => max(0, $storage),
            'bandwidth_quota' => max(0, $bandwidth),
        ]);

        Registry::forgetProject((int) $project['id']);

        self::audit('quota', 'project', $project, [
            'mode'      => $custom ? 'custom' : 'account',
            'storage'   => [(int) $project['storage_quota'], max(0, $storage)],
            'bandwidth' => [(int) $project['bandwidth_quota'], max(0, $bandwidth)],
        ]);
    }

    /**
     * Start this month's transfer counter again.
     *
     * For the case the number is wrong rather than spent: a mis-imported row, a
     * customer who paid for an overage.
     *
     * @param array $project
     * @return void
     */
    public static function resetBandwidth(array $project): void
    {
        (new Projects)->where('id', $project['id'])->update([
            'bandwidth_used'   => 0,
            'bandwidth_period' => date('Y-m'),
        ]);

        Registry::forgetProject((int) $project['id']);

        self::audit('bandwidth-reset', 'project', $project, ['was' => (int) $project['bandwidth_used']]);
    }

    /**
     * Suspend or restore a project.
     *
     * Suspended is 403 at the delivery path - the files are still there and the
     * panel still lists them. That is the difference between this and deleting.
     *
     * @param array  $project
     * @param string $status active | suspended
     * @return void
     */
    public static function projectStatus(array $project, string $status, string $reason = ''): void
    {
        $status = $status === 'suspended' ? 'suspended' : 'active';
        $reason = trim(mb_substr($reason, 0, 255));

        (new Projects)->where('id', $project['id'])->update([
            'status' => $status,

            # Cleared on restore. A reason that outlives the suspension it
            # explains is a reason somebody reads about a project that works.
            'suspend_reason' => $status === 'suspended' ? ($reason ?: null) : null,
        ]);

        Registry::forgetProject((int) $project['id']);

        self::audit($status === 'suspended' ? 'suspend' : 'restore', 'project', $project, ['reason' => $reason]);
    }

    /**
     * Suspend or restore an account.
     *
     * Its projects follow: an account that cannot sign in but whose urls keep
     * serving is not suspended in any sense the person paying for it recognises.
     *
     * @param array  $user
     * @param string $status
     * @return void
     */
    public static function userStatus(array $user, string $status, string $reason = ''): void
    {
        $status = $status === 'suspended' ? 'suspended' : 'active';
        $reason = trim(mb_substr($reason, 0, 255));

        if ($status === 'suspended' && (int) $user['id'] === (int) Auth::id()) return;

        (new User)->where('id', $user['id'])->update([
            'status'         => $status,
            'suspend_reason' => $status === 'suspended' ? ($reason ?: null) : null,
        ]);

        foreach ((new Projects)->where('owner_id', $user['id'])->closureMode(false)->get() as $project) {
            (new Projects)->where('id', $project['id'])->update([
                'status'         => $status,
                'suspend_reason' => $status === 'suspended' ? ($reason ?: null) : null,
            ]);

            Registry::forgetProject((int) $project['id']);
        }

        self::audit($status === 'suspended' ? 'suspend' : 'restore', 'user', $user, ['reason' => $reason]);
    }

    /**
     * Grant or take away operator rights.
     *
     * Only meaningful while `auth.operators` is empty: a list in the config file
     * is the whole answer when it exists, and the panel says so rather than
     * writing a column nothing reads.
     *
     * @param array $user
     * @param bool  $operator
     * @return void
     */
    public static function operator(array $user, bool $operator): void
    {
        # Nobody takes their own rights away by accident. Somebody else can.
        if (!$operator && (int) $user['id'] === (int) Auth::id()) return;

        (new User)->where('id', $user['id'])->update(['is_operator' => $operator ? 1 : 0]);

        self::audit('operator', 'user', $user, ['operator' => $operator]);
    }

    /**
     * Delete an account and everything under it.
     *
     * The files go one at a time through Uploader::delete rather than by
     * dropping rows: that is what releases each object's reference, and an
     * object nothing references is what the collector reclaims. Dropping the
     * rows would leave the bytes on disk with nothing left that knows they are
     * there.
     *
     * @param array $user
     * @return array{files:int,buckets:int,projects:int}
     */
    public static function deleteUser(array $user): array
    {
        if ((int) $user['id'] === (int) Auth::id()) return ['files' => 0, 'buckets' => 0, 'projects' => 0];

        $projects = (new Projects)->where('owner_id', $user['id'])->closureMode(false)->get();
        $counts   = ['files' => 0, 'buckets' => 0, 'projects' => count($projects)];

        foreach ($projects as $project) {
            $files = (new Files)->where('project_id', $project['id'])->closureMode(false)->get();

            foreach ($files as $file) Uploader::delete($file);

            $counts['files'] += count($files);

            $buckets = (new Buckets)->where('project_id', $project['id'])->closureMode(false)->get();

            foreach ($buckets as $bucket) {
                Purger::bucket($bucket, 'operator:' . Auth::id());
                Registry::forgetBucket($bucket);
            }

            $counts['buckets'] += count($buckets);

            (new Buckets)->where('project_id', $project['id'])->delete();
            (new ApiKeys)->where('project_id', $project['id'])->delete();
            (new Webhooks)->where('project_id', $project['id'])->delete();
            (new Projects)->where('id', $project['id'])->delete();

            Registry::forgetProject((int) $project['id']);
        }

        (new User)->where('id', $user['id'])->delete();

        self::audit('delete', 'user', $user, $counts);

        return $counts;
    }

    /**
     * The record of who did what.
     *
     * @param int $perPage
     * @return array
     */
    public static function audits(int $perPage = 40): array
    {
        return (new Audits)->closureMode(false)->orderBy(['id' => 'DESC'])->paginate($perPage);
    }

    /**
     * Write one.
     *
     * The subject's name is copied in rather than joined out later: this table
     * has to still make sense once the row it points at is gone, which is
     * exactly the case somebody comes here to ask about.
     *
     * @param string $action
     * @param string $type
     * @param array  $subject
     * @param array  $detail
     * @return void
     */
    public static function audit(string $action, string $type, array $subject, array $detail = []): void
    {
        $actor = Auth::user() ?: [];

        (new Audits)->insert([
            'actor_id'      => $actor['id'] ?? null,
            'actor_email'   => $actor['email'] ?? null,
            'action'        => $action,
            'subject_type'  => $type,
            'subject_id'    => $subject['id'] ?? null,
            'subject_label' => $subject['name'] ?? ($subject['email'] ?? ($subject['username'] ?? null)),
            'detail'        => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip'            => function_exists('ip') ? (string) ip() : null,
        ]);
    }

    /**
     * Installation totals for the top of the page.
     *
     * @return array
     */
    public static function totals(): array
    {
        $projects = (new Projects)->closureMode(false)->get();

        return [
            'users'     => (new User)->closureMode(false)->count(),
            'projects'  => count($projects),
            'storage'   => array_sum(array_map(fn($p) => (int) $p['storage_used'], $projects)),
            'bandwidth' => array_sum(array_map(
                fn($p) => ($p['bandwidth_period'] ?? null) === date('Y-m') ? (int) $p['bandwidth_used'] : 0,
                $projects
            )),
            'suspended' => count(array_filter($projects, fn($p) => ($p['status'] ?? 'active') !== 'active')),
        ];
    }

    /**
     * Bytes from a number and a unit, as the form sends them.
     *
     * @param string|null $amount
     * @param string|null $unit
     * @return int
     */
    public static function bytes(?string $amount, ?string $unit): int
    {
        $amount = (float) str_replace(',', '.', (string) $amount);
        if ($amount <= 0) return 0;

        $scale = ['b' => 1, 'kb' => 1024, 'mb' => 1024 ** 2, 'gb' => 1024 ** 3, 'tb' => 1024 ** 4];

        return (int) round($amount * ($scale[strtolower((string) $unit)] ?? 1));
    }
}
