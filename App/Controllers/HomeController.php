<?php

namespace App\Controllers;

use App\Cdn\Support;
use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Facades\Auth;

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
        # Somebody signed in has no use for the pitch.
        if (Auth::check()) redirect((string) (Support::config('admin.route') ?: '/panel'));

        return view('cdn.home', [
            'registration' => (bool) Support::config('auth.registration', true),
        ]);
    }
}
