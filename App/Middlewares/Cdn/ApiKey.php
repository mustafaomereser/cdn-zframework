<?php

namespace App\Middlewares\Cdn;

use App\Cdn\Credentials;
use App\Cdn\RateLimiter;
use App\Cdn\Support;
use zFramework\Core\ResponseSignal;

/**
 * Guards the management API.
 *
 * Answers the refusal itself rather than declining and leaving it to a group
 * fallback. A fallback would have to be a closure on the route group, and one
 * closure anywhere makes the whole route table uncacheable - which on a host
 * whose router runs on every asset request is a poor trade for a tidier
 * signature. Route::match's declined path with no fallback ends in a plain 404,
 * which is the wrong answer to a bad key.
 */
#[\AllowDynamicProperties]
class ApiKey
{
    /**
     * @return bool
     */
    public function attempt(): bool
    {
        if (!Credentials::attempt()) $this->error();

        # Counted per key rather than per address: several servers behind one
        # NAT share an address, and an API key is the thing with a quota.
        $limit = RateLimiter::check('key', 'key:' . Credentials::key()['access_key']);

        if ($limit !== null && !$limit['allowed']) {
            throw new ResponseSignal(429, [
                'Content-Type' => 'application/json',
                'Retry-After'  => (string) max(1, $limit['reset'] - time()),
                'X-RateLimit-Limit'     => (string) $limit['limit'],
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset'     => (string) $limit['reset'],
            ], json_encode(['ok' => false, 'error' => 'rate-limited']));
        }

        if ($limit !== null) {
            \zFramework\Core\Facades\Response::header('X-RateLimit-Limit', (string) $limit['limit']);
            \zFramework\Core\Facades\Response::header('X-RateLimit-Remaining', (string) $limit['remaining']);
        }

        return true;
    }

    /**
     * @return void
     */
    public function error(): void
    {
        throw new ResponseSignal(401, [
            'Content-Type'     => 'application/json',
            'WWW-Authenticate' => 'Bearer realm="cdn"',
        ], json_encode([
            'ok'     => false,
            'error'  => 'unauthorized',
            # The specific reason goes no further than the server log: "unknown
            # key" versus "bad secret" tells a caller which half to keep trying.
            'detail' => Support::config('api.verbose-errors', false) ? Credentials::failure() : null,
        ]));
    }
}
