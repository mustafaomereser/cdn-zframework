<?php

namespace App\Controllers\Cdn;

use App\Cdn\Operator;
use App\Cdn\Runner;
use App\Cdn\Storage;
use App\Cdn\Support;
use App\Cdn\Transform;
use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Response;
use zFramework\Core\Helpers\File;

/**
 * The operator pages: accounts, quotas, the installation itself.
 *
 * Separate from AdminController rather than a set of `if (isOperator)` branches
 * inside it. Two reasons, and the second is the one that matters: a page that
 * shows different things to different people is a page whose scoping has to be
 * re-read every time it changes, and the whole route group here is behind one
 * middleware instead.
 *
 * Every method that changes something goes through App\Cdn\Operator, which
 * writes the audit row. None of them write to the database directly.
 */
class OperatorController
{
    /**
     * Accounts.
     *
     * @return mixed
     */
    public function users(): mixed
    {
        return view('cdn.pages.admin.users', [
            'users'  => Operator::users(),
            'units'  => ['B' => 1, 'KB' => 1024, 'MB' => 1024 ** 2, 'GB' => 1024 ** 3, 'TB' => 1024 ** 4],
            'totals' => Operator::totals(),
            'locked' => count((array) Support::config('auth.operators', [])) > 0,
        ]);
    }

    /**
     * Projects, with the quota form on each.
     *
     * @return mixed
     */
    public function projects(): mixed
    {
        # `rows` rather than `projects`: the layout defines a $projects of its
        # own - the signed-in account's - and the compiler splices page and
        # layout into one file, so the page's would be the one that loses.
        return view('cdn.pages.admin.projects', [
            'rows'   => Operator::projects(),
            'totals' => Operator::totals(),
        ]);
    }

    /**
     * What was changed, and by whom.
     *
     * @return mixed
     */
    public function audits(): mixed
    {
        return view('cdn.pages.admin.audits', ['audits' => Operator::audits()]);
    }

    /**
     * The machine: what it can do, what it has room for.
     *
     * This used to be a block at the bottom of the settings page, where it was
     * the only thing on it that had nothing to do with the signed-in account.
     *
     * @return mixed
     */
    public function system(): mixed
    {
        $disks = [];

        foreach ((array) Support::config('storage.disks', []) as $name => $disk) {
            $disks[$name] = [
                'root'     => $disk['root'] ?? null,
                'writable' => is_dir($disk['root'] ?? '') ? is_writable($disk['root']) : null,
                'free'     => Storage::freeSpace($name),
            ];
        }

        return view('cdn.pages.admin.system', [
            'totals'   => Operator::totals(),
            'disks'    => $disks,
            'variants' => Storage::measure(Storage::variantRoot()),
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
        ]);
    }

    #region Actions
    /**
     * @param string $id
     * @return mixed
     */
    public function quota(string $id): mixed
    {
        $user = Operator::user($id);

        Operator::quota(
            $user,
            Operator::bytes(request('storage'), request('storage-unit')),
            Operator::bytes(request('bandwidth'), request('bandwidth-unit'))
        );

        Alerts::success(_l('cdn.alerts.quota-saved', ['project' => $user['username']]));

        return back();
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function bandwidthReset(string $id): mixed
    {
        $project = Operator::project($id);

        Operator::resetBandwidth($project);

        Alerts::success(_l('cdn.alerts.bandwidth-reset', ['project' => $project['name']]));

        return back();
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function projectStatus(string $id): mixed
    {
        $project = Operator::project($id);
        $status  = (string) request('status');

        Operator::projectStatus($project, $status);

        Alerts::success(_l($status === 'suspended' ? 'cdn.alerts.suspended-project' : 'cdn.alerts.restored-project', [
            'project' => $project['name'],
        ]));

        return back();
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function userStatus(string $id): mixed
    {
        $user   = Operator::user($id);
        $status = (string) request('status');

        if ((int) $user['id'] === (int) Auth::id()) {
            Alerts::danger(_l('cdn.alerts.not-yourself'));

            return back();
        }

        Operator::userStatus($user, $status);

        Alerts::success(_l($status === 'suspended' ? 'cdn.alerts.suspended-user' : 'cdn.alerts.restored-user', [
            'user' => $user['username'],
        ]));

        return back();
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function operator(string $id): mixed
    {
        # While auth.operators names anybody, the column is not what decides -
        # writing it would look like it worked and change nothing.
        if (count((array) Support::config('auth.operators', []))) {
            Alerts::danger(_l('cdn.alerts.operators-in-config'));

            return back();
        }

        $user     = Operator::user($id);
        $operator = (bool) request('operator');

        if (!$operator && (int) $user['id'] === (int) Auth::id()) {
            Alerts::danger(_l('cdn.alerts.not-yourself'));

            return back();
        }

        Operator::operator($user, $operator);

        Alerts::success(_l($operator ? 'cdn.alerts.operator-granted' : 'cdn.alerts.operator-revoked', [
            'user' => $user['username'],
        ]));

        return back();
    }

    /**
     * @param string $id
     * @return mixed
     */
    public function userDelete(string $id): mixed
    {
        $user = Operator::user($id);

        if ((int) $user['id'] === (int) Auth::id()) {
            Alerts::danger(_l('cdn.alerts.not-yourself'));

            return back();
        }

        $counts = Operator::deleteUser($user);

        Alerts::success(_l('cdn.alerts.user-deleted', [
            'user'  => $user['username'],
            'files' => $counts['files'],
        ]));

        return redirect(route('cdn-admin.operator.users'));
    }
    #endregion

    #region Console
    /**
     * @return mixed
     */
    public function console(): mixed
    {
        if (!Runner::enabled()) abort(404);

        return view('cdn.pages.admin.console', [
            'scripts' => [
                'cdn'      => Runner::allowed('cdn'),
                'terminal' => Runner::allowed('terminal'),
            ],
            'timeout' => (int) Support::config('admin.console.timeout', 120),
        ]);
    }

    /**
     * Run one line and answer with what it printed.
     *
     * @return mixed
     */
    public function consoleRun(): mixed
    {
        if (!Runner::enabled()) abort(404);

        $result = Runner::run((string) request('script'), (string) request('command'));

        if (isset($result['refused'])) {
            $result['output'] = _l('cdn.console.refused', ['reason' => $result['refused']]);
        }

        return Response::json($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    #endregion

    /**
     * Bytes, for the templates.
     *
     * @param int $bytes
     * @return string
     */
    public static function size(int $bytes): string
    {
        return File::humanFileSize($bytes);
    }
}
