<?php

namespace App\Controllers\Cdn;

use App\Cdn\Flash;
use App\Cdn\Operator;
use App\Cdn\Mover;
use App\Cdn\Uploader;
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
            'units'  => ['B' => 1, 'KB' => 1024, 'MB' => 1024 ** 2, 'GB' => 1024 ** 3, 'TB' => 1024 ** 4],
            'totals' => Operator::totals(),
        ]);
    }

    /**
     * One account, with everything under it.
     *
     * @param string $id
     * @return mixed
     */
    public function account(string $id): mixed
    {
        return view('cdn.pages.admin.account', Operator::account($id) + [
            'units'  => ['B' => 1, 'KB' => 1024, 'MB' => 1024 ** 2, 'GB' => 1024 ** 3, 'TB' => 1024 ** 4],
            'locked' => count((array) Support::config('auth.operators', [])) > 0,
            'prefix' => rtrim((string) Support::config('delivery.url-prefix', '/cdn'), '/'),
        ]);
    }

    /**
     * One project, whoever owns it.
     *
     * @param string $id
     * @return mixed
     */
    public function project(string $id): mixed
    {
        return view('cdn.pages.admin.project', Operator::projectDetail($id) + [
            'units'  => ['B' => 1, 'KB' => 1024, 'MB' => 1024 ** 2, 'GB' => 1024 ** 3, 'TB' => 1024 ** 4],
            'prefix' => rtrim((string) Support::config('delivery.url-prefix', '/cdn'), '/'),
        ]);
    }

    /**
     * Every file in the installation.
     *
     * @return mixed
     */
    public function files(): mixed
    {
        $files = Operator::files();

        return view('cdn.pages.admin.files', [
            'files'  => $files,

            # Only when the listing is one account's, which is what a bucket or
            # project filter makes it. Offering every bucket in the installation
            # would be a menu nobody can read and a move nobody meant.
            'moveTargets' => Operator::moveTargets($files['items']),
            'prefix' => rtrim((string) Support::config('delivery.url-prefix', '/cdn'), '/'),
        ]);
    }

    /**
     * Delete or move a selection of files, across accounts.
     *
     * A move here is bound by one rule the panel's own is not: every file has to
     * belong to the same owner as the bucket it is going to. An operator can see
     * everything, which is exactly why the one action that puts one customer's
     * bytes inside another's namespace is refused rather than trusted.
     *
     * @return mixed
     */
    public function filesBulk(): mixed
    {
        $ids = array_values(array_filter(array_map('intval', (array) request('files'))));

        if (!count($ids)) {
            Flash::danger(_l('cdn.files.none-selected'));

            return back();
        }

        $action = (string) request('action');
        $target = $action === 'move' ? Operator::bucket((string) request('target')) : null;
        $owner  = $target ? (int) Operator::project((int) $target['project_id'])['owner_id'] : 0;

        $done   = 0;
        $failed = [];

        foreach (Operator::filesByIds($ids) as $file) {
            if ($action === 'move') {
                if ((int) Operator::project((int) $file['project_id'])['owner_id'] !== $owner) {
                    $failed[] = _l('cdn.operator.move-other-account');
                    continue;
                }

                $result = Mover::move($file, $target);

                if ($result['ok']) $done++;
                else $failed[] = _l('cdn.upload-errors.' . ($result['error'] ?? 'unknown'), ['error' => $result['error'] ?? '']);

                continue;
            }

            Uploader::delete($file);
            $done++;
        }

        if ($done) {
            Operator::audit($action === 'move' ? 'files-moved' : 'files-deleted', 'files', [
                'id'   => $target['id'] ?? null,
                'name' => $target['name'] ?? null,
            ], ['count' => $done]);

            Flash::success(_l($action === 'move' ? 'cdn.alerts.files-moved' : 'cdn.alerts.files-deleted', [
                'count'  => $done,
                'bucket' => $target['name'] ?? '',
            ]));
        }

        foreach (array_unique($failed) as $message) Flash::danger($message);

        return back();
    }

    /**
     * The hosting account: what it is using, and the cron that keeps this
     * installation tidy.
     *
     * @return mixed
     */
    public function cpanel(): mixed
    {
        return view('cdn.pages.admin.cpanel', [
            'credentials' => \App\Cdn\Hosting::credentials(),
            'configured'  => \App\Cdn\Hosting::configured(),
            'usage'       => \App\Cdn\Hosting::usage(),
            'crons'       => \App\Cdn\Hosting::crons(),
            'command'     => \App\Cdn\Hosting::command(),
        ]);
    }

    /**
     * Save the connection.
     *
     * @return mixed
     */
    public function cpanelSave(): mixed
    {
        if (request('forget')) {
            \App\Cdn\Settings::put([
                'hosting.cpanel.enabled'  => null,
                'hosting.cpanel.domain'   => null,
                'hosting.cpanel.username' => null,
                'hosting.cpanel.token'    => null,
            ]);

            Operator::audit('cpanel', 'system', ['id' => null, 'name' => 'cpanel'], ['forget' => true]);

            Flash::success(_l('cdn.cpanel.forgotten'));

            return back();
        }

        $values = [
            'hosting.cpanel.enabled'  => request('enabled') ? '1' : '0',
            'hosting.cpanel.domain'   => trim((string) request('domain')),
            'hosting.cpanel.username' => trim((string) request('username')),
        ];

        # An empty token field keeps the stored one: the form never renders it,
        # so an empty box means "unchanged" rather than "delete it".
        if (trim((string) request('token')) !== '') $values['hosting.cpanel.token'] = trim((string) request('token'));

        \App\Cdn\Settings::put($values);

        # Audited without the token. What is worth recording is that somebody
        # pointed this installation at an account, not the secret they used.
        Operator::audit('cpanel', 'system', ['id' => null, 'name' => $values['hosting.cpanel.domain']], [
            'username' => $values['hosting.cpanel.username'],
            'enabled'  => $values['hosting.cpanel.enabled'] === '1',
        ]);

        Flash::success(_l('cdn.alerts.saved'));

        return back();
    }

    /**
     * Add or remove a cron line through cPanel.
     *
     * @return mixed
     */
    public function cpanelCron(): mixed
    {
        $action = (string) request('action');

        if ($action === 'remove') {
            $result = \App\Cdn\Hosting::removeCron((int) request('key'));

            if ($result['ok']) Flash::success(_l('cdn.cpanel.cron-removed'));
            else Flash::danger(_l('cdn.cpanel.cron-failed', ['error' => $result['error'] ?? '']));

            Operator::audit('cpanel-cron', 'system', ['id' => null, 'name' => 'remove'], $result);

            return back();
        }

        $schedule = (string) (request('schedule') ?: '0 * * * *');

        # Five fields, each of them one of the shapes cron accepts. Whatever the
        # select sends is checked rather than trusted: it is a string that ends
        # up in a crontab.
        if (!preg_match('/^[\d*\/,\- ]{1,60}$/', $schedule) || count(explode(' ', trim($schedule))) !== 5) {
            Flash::danger(_l('cdn.cpanel.bad-schedule'));

            return back();
        }

        $result = \App\Cdn\Hosting::installCron($schedule);

        if ($result['ok']) Flash::success(_l('cdn.cpanel.cron-installed'));
        else Flash::danger(_l('cdn.cpanel.cron-failed', ['error' => $result['error'] ?? '']));

        Operator::audit('cpanel-cron', 'system', ['id' => null, 'name' => $schedule], $result);

        return back();
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
        return view('cdn.pages.admin.system', [
            'totals'     => Operator::totals(),
            'info'       => \App\Cdn\System::info(),
            'extensions' => \App\Cdn\System::extensions(),
            'disks'      => \App\Cdn\System::disks(),
            'hosting'    => \App\Cdn\Hosting::usage(),
            'variants'   => Storage::measure(Storage::variantRoot()),
            'capabilities' => [
                'driver'  => Transform::driver(),
                'formats' => array_combine(
                    ['jpg', 'png', 'gif', 'webp', 'avif'],
                    array_map(fn($format) => Transform::supports($format), ['jpg', 'png', 'gif', 'webp', 'avif'])
                ),
            ],
        ]);
    }

    /**
     * The housekeeping page: what runs, when it last ran, and what it would
     * free if it ran now.
     *
     * @return mixed
     */
    public function maintenance(): mixed
    {
        $db = new \zFramework\Core\Facades\DB;

        $orphans = $db->prepare('SELECT COUNT(*) AS count, COALESCE(SUM(size), 0) AS bytes FROM cdn_objects WHERE refs <= 0')
            ->fetch(\PDO::FETCH_ASSOC) ?: ['count' => 0, 'bytes' => 0];

        $logRows = (int) (($db->prepare('SELECT COUNT(*) AS count FROM cdn_access_logs')->fetch(\PDO::FETCH_ASSOC))['count'] ?? 0);

        return view('cdn.pages.admin.maintenance', [
            'tasks'    => array_keys(\App\Cdn\Housekeeping::tasks()),
            'lastRun'  => \App\Cdn\Housekeeping::lastRun(),
            'daily'    => \App\Cdn\Housekeeping::lastDaily(),
            'variants' => Storage::measure(Storage::variantRoot()),
            'orphans'  => ['count' => (int) $orphans['count'], 'bytes' => (int) $orphans['bytes']],
            'logRows'  => $logRows,
        ]);
    }

    /**
     * Run the housekeeping, or one task of it, from the panel.
     *
     * The same work the hourly cron does. It is here for the host where nobody
     * set up a crontab, and for the day somebody wants the disk back now rather
     * than at the top of the hour - which is why the daily tasks run when they
     * are asked for by name even if they already ran today.
     *
     * @return mixed
     */
    public function maintenanceRun(): mixed
    {
        $only = (string) request('task');
        $only = $only !== '' && isset(\App\Cdn\Housekeeping::tasks()[$only]) ? $only : null;

        # A long job on a web request. It is bounded - the tasks are all
        # counted work over rows and files - but the default limit is not
        # written for it.
        @set_time_limit(max(60, (int) Support::config('admin.console.timeout', 120)));

        try {
            $did = \App\Cdn\Housekeeping::run($only, true);
        } catch (\Throwable $thrown) {
            Flash::danger(_l('cdn.operator.maintenance-failed', ['error' => $thrown->getMessage()]));

            return back();
        }

        Operator::audit('maintenance', 'system', ['id' => null, 'name' => $only ?: 'all'], $did);

        # What it actually did, per task, rather than "done".
        $summary = [];

        foreach ($did as $task => $count) $summary[] = _l("cdn.operator.task-$task") . ': ' . number_format($count);

        Flash::success(count($summary) ? implode(' · ', $summary) : _l('cdn.operator.maintenance-nothing'));

        return back();
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

        Flash::success(_l('cdn.alerts.quota-saved', ['project' => $user['username']]));

        return back();
    }

    /**
     * A project's own quota, or back to the account's.
     *
     * @param string $id
     * @return mixed
     */
    public function projectQuota(string $id): mixed
    {
        $project = Operator::project($id);

        Operator::projectQuota(
            $project,
            (bool) request('storage-custom'),
            Operator::bytes(request('storage'), request('storage-unit')),
            (bool) request('bandwidth-custom'),
            Operator::bytes(request('bandwidth'), request('bandwidth-unit'))
        );

        Flash::success(_l('cdn.alerts.quota-saved', ['project' => $project['name']]));

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

        Flash::success(_l('cdn.alerts.bandwidth-reset', ['project' => $project['name']]));

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

        Operator::projectStatus($project, $status, (string) request('reason'));

        Flash::success(_l($status === 'suspended' ? 'cdn.alerts.suspended-project' : 'cdn.alerts.restored-project', [
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
            Flash::danger(_l('cdn.alerts.not-yourself'));

            return back();
        }

        Operator::userStatus($user, $status, (string) request('reason'));

        Flash::success(_l($status === 'suspended' ? 'cdn.alerts.suspended-user' : 'cdn.alerts.restored-user', [
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
            Flash::danger(_l('cdn.alerts.operators-in-config'));

            return back();
        }

        $user     = Operator::user($id);
        $operator = (bool) request('operator');

        if (!$operator && (int) $user['id'] === (int) Auth::id()) {
            Flash::danger(_l('cdn.alerts.not-yourself'));

            return back();
        }

        Operator::operator($user, $operator);

        Flash::success(_l($operator ? 'cdn.alerts.operator-granted' : 'cdn.alerts.operator-revoked', [
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
            Flash::danger(_l('cdn.alerts.not-yourself'));

            return back();
        }

        $counts = Operator::deleteUser($user);

        Flash::success(_l('cdn.alerts.user-deleted', [
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
                'cdn'      => Runner::commands('cdn'),
                'terminal' => Runner::commands('terminal'),
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
