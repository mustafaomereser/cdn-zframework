<?php

namespace App\Controllers;

use App\Cdn\Support;
use App\Cdn\Tenant;
use App\Models\User;
use App\Requests\Auth\SigninRequest;
use App\Requests\Auth\SignupRequest;
use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Response;
use zFramework\Core\Facades\Str;

/**
 * Sign in, sign up, sign out.
 *
 * Registration creates the account and its project in one go - see
 * Tenant::create(). A user without a project is a user whose panel has nothing
 * to show and no way to make anything, so the two are never separate.
 */
class AuthController extends Controller
{
    public function __construct()
    {
        //
    }

    /**
     * The sign-in / sign-up page.
     *
     * @return mixed
     */
    public function auth(): mixed
    {
        return view('cdn.auth', [
            'registration' => (bool) Support::config('auth.registration', true),
            'next'         => (string) (request('next') ?: ''),
        ]);
    }

    /**
     * @param SigninRequest $validate
     * @return mixed
     */
    public function signin(SigninRequest $validate): mixed
    {
        $validate = $validate->validated();
        $response = ['status' => 0];

        if (Auth::attempt(['email' => $validate['email'], 'password' => $validate['password']], (bool) $validate['keep-logged-in'])) {
            $response['status'] = 1;

            # The project is created on first sign-in when the account predates
            # it - an account added straight to the database, or one from before
            # this was a CDN.
            Tenant::project();

            $response['redirect'] = $this->next();
        } else Alerts::danger('E-mail or password does not match.');

        return Response::json($response);
    }

    /**
     * @param SignupRequest $validate
     * @return mixed
     */
    public function signup(SignupRequest $validate): mixed
    {
        # Checked here rather than only hidden in the form: a closed
        # registration that can still be posted to is not closed.
        if (!Support::config('auth.registration', true)) {
            Alerts::danger('Registration is closed.');
            return Response::json(['status' => 0]);
        }

        $validate = $validate->validated();

        $user = (new User)->insert([
            'username'  => $validate['username'],
            'email'     => $validate['email'],
            'password'  => Auth::encodePassword($validate['password']),
            'api_token' => Str::rand(60),
        ]);

        # Straight in, with a project and a first bucket waiting. Making somebody
        # sign in again immediately after choosing a password is a step that
        # exists for no reason.
        Auth::login($user);
        Tenant::create($user);

        Alerts::success('Welcome. Your CDN is ready.');

        return Response::json(['status' => 1, 'redirect' => $this->next()]);
    }

    /**
     * Where to go after signing in.
     *
     * A `next` parameter is only honoured when it is a path on this site -
     * an absolute url here is an open redirect, which is how a phishing link
     * gets to wear your domain.
     *
     * @return string
     */
    private function next(): string
    {
        $next = (string) (request('next') ?: '');

        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) return (string) (Support::config('admin.route') ?: '/panel');

        return $next;
    }

    /**
     * @return mixed
     */
    public function signout(): mixed
    {
        Auth::logout();
        Alerts::success('Signed out.');

        return Response::json(['status' => 1, 'redirect' => '/']);
    }

    /**
     * The signed-in indicator the layout loads over ajax.
     *
     * @return mixed
     */
    public function content(): mixed
    {
        return view('app.layouts.auth.content');
    }
}
