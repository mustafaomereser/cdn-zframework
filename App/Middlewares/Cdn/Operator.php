<?php

namespace App\Middlewares\Cdn;

use App\Cdn\Support;
use App\Cdn\Tenant;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Helpers\Http;

/**
 * Guards the operator pages.
 *
 * Everything else in the panel is scoped: it cannot show another account's
 * files because the queries cannot express it. These pages are the opposite -
 * they work across accounts by design - so here the check is the only thing
 * standing between a signed-in customer and the quota form for everybody else.
 *
 * A visitor who is not an operator gets 404 rather than 403. There is nothing
 * to gain from confirming that an administration area exists to somebody who
 * cannot open it.
 */
#[\AllowDynamicProperties]
class Operator
{
    /**
     * @return bool
     */
    public function attempt(): bool
    {
        if (!Support::config('admin.enabled', true)) abort(404);

        if (!Auth::check()) $this->error();

        Http::$error_view = 'errors.admin';

        if (!Tenant::isOperator()) abort(404);

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
