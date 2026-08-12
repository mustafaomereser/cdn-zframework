<?php

namespace App\Middlewares\Cdn;

use App\Cdn\Support;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Helpers\Http;

/**
 * Guards the panel.
 *
 * Two gates: signed in at all, and named in `cdn.admin.emails`. An empty list
 * means any authenticated user, which is only reasonable while the application
 * has no users other than its operators - so the panel says so out loud on the
 * dashboard rather than leaving it to be discovered.
 */
#[\AllowDynamicProperties]
class Admin
{
    /**
     * @return bool
     */
    public function attempt(): bool
    {
        # Answered here rather than by declining: without a fallback closure on
        # the group - which would make the route table uncacheable - a decline
        # is served as a bare 404, and "you are not signed in" deserves better.
        if (!Support::config('admin.enabled', true)) abort(404);
        if (!Auth::check()) $this->error();

        # The panel renders inside the application's admin error pages, so a 404
        # in it looks like the panel rather than like the public site.
        Http::$error_view = 'errors.admin';

        $allowed = array_filter((array) Support::config('admin.emails', []));
        if (!count($allowed)) return true;

        $user = Auth::user() ?: [];

        if (!in_array(strtolower((string) ($user['email'] ?? '')), array_map('strtolower', $allowed), true)) abort(403);

        return true;
    }

    /**
     * @return void
     */
    public function error(): void
    {
        # Not signed in at all is a redirect to the form; signed in but not an
        # operator is a refusal - sending them to a login page they are already
        # past is a loop.
        if (!Auth::check()) redirect(route('auth-form'));

        abort(403);
    }
}
