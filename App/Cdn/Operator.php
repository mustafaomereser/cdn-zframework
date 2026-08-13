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

            $user['projects']  = $own;
            $user['storage']   = array_sum(array_map(fn($p) => (int) $p['storage_used'], $own));
            $user['quota']     = array_sum(array_map(fn($p) => (int) $p['storage_quota'], $own));
            $user['bandwidth'] = array_sum(array_map(
                fn($p) => ($p['bandwidth_period'] ?? null) === date('Y-m') ? (int) $p['bandwidth_used'] : 0,
                $own
            ));

            # A quota of 0 is unlimited, and one unlimited project makes the
            # account's total unlimited - not the sum of the others.
            foreach ($own as $project) if ((int) $project['storage_quota'] === 0) $user['quota'] = 0;

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
     * Set a project's quotas. Bytes; 0 is unlimited.
     *
     * @param array $project
     * @param int   $storage
     * @param int   $bandwidth
     * @return void
     */
    public static function quota(array $project, int $storage, int $bandwidth): void
    {
        $storage   = max(0, $storage);
        $bandwidth = max(0, $bandwidth);

        (new Projects)->where('id', $project['id'])->update([
            'storage_quota'   => $storage,
            'bandwidth_quota' => $bandwidth,
        ]);

        # The delivery path reads the project row through the registry cache, so
        # a quota raised here would otherwise take up to registry-ttl to be felt
        # by the person who was refused.
        Registry::forgetProject((int) $project['id']);

        self::audit('quota', 'project', $project, [
            'storage'   => [(int) $project['storage_quota'], $storage],
            'bandwidth' => [(int) $project['bandwidth_quota'], $bandwidth],
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
    public static function projectStatus(array $project, string $status): void
    {
        $status = $status === 'suspended' ? 'suspended' : 'active';

        (new Projects)->where('id', $project['id'])->update(['status' => $status]);

        Registry::forgetProject((int) $project['id']);

        self::audit($status === 'suspended' ? 'suspend' : 'restore', 'project', $project, []);
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
    public static function userStatus(array $user, string $status): void
    {
        $status = $status === 'suspended' ? 'suspended' : 'active';

        if ($status === 'suspended' && (int) $user['id'] === (int) Auth::id()) return;

        (new User)->where('id', $user['id'])->update(['status' => $status]);

        foreach ((new Projects)->where('owner_id', $user['id'])->closureMode(false)->get() as $project) {
            (new Projects)->where('id', $project['id'])->update(['status' => $status]);
            Registry::forgetProject((int) $project['id']);
        }

        self::audit($status === 'suspended' ? 'suspend' : 'restore', 'user', $user, []);
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
