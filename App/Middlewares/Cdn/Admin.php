<?php

namespace App\Middlewares\Cdn;

use App\Cdn\Support;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Helpers\Http;

/**
 * Guards the panel.
 *
 * Only one gate now: signed in. Every account has a project of its own, so
 * there is nobody to keep out - what used to be an allowlist is now the
 * per-project scoping in Tenant, which is a much better place for it. An
 * allowlist decides who may open a page; scoping decides what the page can
 * possibly show, and only the second survives somebody editing an id in a url.
 *
 * `auth.operators` still exists, for the parts that administer the
 * installation rather than a project - see Tenant::isOperator().
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
        # is served as a bare 404.
        if (!Support::config('admin.enabled', true)) abort(404);
        if (!Auth::check()) $this->error();


        return true;
    }

    /**
     * @return void
     */
    public function error(): void
    {
        redirect(route('auth-form') . '?next=' . rawurlencode(uri()));
    }
}
