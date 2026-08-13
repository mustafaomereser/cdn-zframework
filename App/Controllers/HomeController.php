<?php

namespace App\Controllers;

use App\Cdn\Support;
use zFramework\Core\Abstracts\Controller;

/**
 * The public front of the service.
 *
 * The skeleton's welcome page used to live here, along with a POST endpoint
 * that ran arbitrary terminal commands and returned their output. That is a
 * remote shell on anything reachable from the internet, and this application is
 * meant to be reachable from the internet, so it is gone rather than guarded.
 */
class HomeController extends Controller
{
    public function __construct($method = null)
    {
        //
    }

    /**
     * @return mixed
     */
    public function index(): mixed
    {
        # Signed-in visitors used to be redirected to the panel from here, which
        # made the panel's own "public site" link a dead end - it bounced
        # straight back. The nav shows "Open panel" when there is a session, so
        # the convenience is kept without taking the page away from the person
        # who asked for it.
        return view('cdn.home', [
            'registration' => (bool) Support::config('auth.registration', true),
        ]);
    }
}
