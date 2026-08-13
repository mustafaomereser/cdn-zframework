<?php

namespace App\Controllers;

use App\Cdn\Flash;
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
        # Rendered into the page rather than left to the toast library: the
        # public layout does not load it, and an error somebody cannot see is an
        # error they blame on the form.
        return view('cdn.auth', [
            'registration' => (bool) Support::config('auth.registration', true),
            'next'         => (string) (request('next') ?: ''),
            'alerts'       => Flash::take(),
        ]);
    }

    /**
     * @param SigninRequest $validate
     * @return mixed
     */
    public function signin(): mixed
    {
        $validate = $this->check(new SigninRequest);

        if ($validate === null) return $this->refuse();

        if (!Auth::attempt(['email' => $validate['email'], 'password' => $validate['password']], (bool) $validate['keep-logged-in'])) {
            Flash::danger(_l('cdn.alerts.signin-failed'));

            return $this->refuse();
        }

        # Suspended after the credentials, not before: answering differently to
        # a suspended account and a wrong password tells anybody who asks which
        # addresses have accounts here.
        if ((string) (Auth::user()['status'] ?? 'active') === 'suspended') {
            $reason = trim((string) (Auth::user()['suspend_reason'] ?? ''));

            Auth::logout();

            Flash::danger($reason
                ? _l('cdn.alerts.suspended-because', ['reason' => $reason])
                : _l('cdn.alerts.suspended'));

            return $this->refuse();
        }

        # The project is created on first sign-in when the account predates it -
        # an account added straight to the database, or one from before this was
        # a CDN.
        Tenant::project();

        return redirect($this->next());
    }

    /**
     * @param SignupRequest $validate
     * @return mixed
     */
    public function signup(): mixed
    {
        # Checked here rather than only hidden in the form: a closed
        # registration that can still be posted to is not closed.
        if (!Support::config('auth.registration', true)) {
            Flash::danger(_l('cdn.alerts.registration-closed'));

            return $this->refuse();
        }

        $validate = $this->check(new SignupRequest);

        if ($validate === null) return $this->refuse();

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

        Flash::success(_l('cdn.alerts.welcome'));

        return redirect($this->next());
    }

    /**
     * Run a request's rules without letting the validator answer for us.
     *
     * Given a callback, Validator::validate() hands back the errors instead of
     * redirecting - and that matters here, because its own redirect loses the
     * messages: run.php clears the framework's alerts as soon as a redirect is
     * sent, so every "e-mail already taken" arrived at a page with nothing on
     * it and the form said "that did not go through" for all of them.
     *
     * @param object $request
     * @return array|null Null when something failed.
     */
    private function check(object $request): ?array
    {
        $failed = false;

        $data = \zFramework\Core\Validator::validate(
            $_REQUEST,
            $request->columns(),
            [],
            function (array $errors) use (&$failed) {
                $failed = true;

                foreach ($errors as $list) foreach ($list as $message) Flash::danger((string) $message);
            }
        );

        return $failed ? null : $data;
    }

    /**
     * Back to the form, with whatever was just said about it.
     *
     * These used to answer json and the page posted them with fetch, which
     * worked right up until the validator refused something: it reports by
     * putting alerts in the session and redirecting, so the fetch followed a
     * 302 to a page of html and the form showed "that did not go through" for
     * every wrong password, taken username and short password alike.
     *
     * A plain form post has none of that problem, and works with javascript
     * off, which a sign-in page has no business requiring.
     *
     * @return mixed
     */
    private function refuse(): mixed
    {
        $query = [];

        if ($this->wanted()) $query['next'] = $this->wanted();

        # Which tab to come back to. A rejected sign-up landing on the sign-in
        # form looks like the form was cleared for no reason.
        if (request('tab') === 'signup') $query['tab'] = 'signup';

        return redirect(route('auth-form') . (count($query) ? '?' . http_build_query($query) : ''));
    }

    /**
     * The `next` that was posted, if it is one we would honour.
     *
     * @return string
     */
    private function wanted(): string
    {
        $next = $this->next();

        return $next === '/panel' ? '' : $next;
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
        Flash::success(_l('cdn.alerts.signed-out'));

        # A plain form post gets a redirect; only a caller that asked for json
        # gets json. Signing out through a script means a broken script - a cdn
        # that failed to load, an error earlier in the file - locks somebody
        # into a session they cannot end.
        if (!\zFramework\Core\Helpers\Http::isAjax()) redirect('/');

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
