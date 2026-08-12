<?php

namespace App\Cdn;

/**
 * Aliased: the facade and the phpredis class are both called Redis, and an
 * unaliased import makes `?Redis` below resolve to the facade - which
 * connection() does not return, so it would be a TypeError on the first hit.
 */
use zFramework\Core\Facades\Redis as RedisFacade;

/**
 * Fixed-window request counting.
 *
 * A window is `floor(now / window)`, so the counter key rotates on its own and
 * nothing has to expire it precisely. The trade-off is the boundary: a client
 * can spend a full allowance at the end of one window and another at the start
 * of the next. A sliding window would cost a sorted set per client, which is
 * more bookkeeping than a public asset host wants to do per request.
 *
 * Storage, cheapest first: Redis when configured (shared across servers), APCu
 * otherwise (per server), and nothing at all if neither is there - in which
 * case the limiter allows everything rather than failing closed. It exists to
 * blunt abuse, not to be a dependency the CDN cannot serve without.
 */
class RateLimiter
{
    /**
     * @var bool|null Whether APCu can be used, resolved once.
     */
    private static ?bool $apcu = null;

    /**
     * Count one request against a limit.
     *
     * @param string $bucket Logical limiter name: ip, key, upload …
     * @param string $identity Who is being counted.
     * @param int    $limit
     * @param int    $window Seconds.
     * @return array{allowed:bool,count:int,limit:int,remaining:int,reset:int}
     */
    public static function hit(string $bucket, string $identity, int $limit, int $window): array
    {
        $window = max(1, $window);
        $slot   = (int) floor(time() / $window);
        $reset  = ($slot + 1) * $window;
        $key    = "cdn:rl:$bucket:" . substr(sha1($identity), 0, 16) . ":$slot";

        $count = self::increment($key, $window);

        # Unknown means no store is available. Allow, and say so through count 0
        # so a caller that cares can tell it apart from a real first request.
        if ($count === null) return ['allowed' => true, 'count' => 0, 'limit' => $limit, 'remaining' => $limit, 'reset' => $reset];

        return [
            'allowed'   => $count <= $limit,
            'count'     => $count,
            'limit'     => $limit,
            'remaining' => max(0, $limit - $count),
            'reset'     => $reset,
        ];
    }

    /**
     * Increment a counter and return its new value, or null with no store.
     *
     * @param string $key
     * @param int    $ttl
     * @return int|null
     */
    private static function increment(string $key, int $ttl): ?int
    {
        if ($redis = self::redis()) {
            try {
                $value = $redis->incr(RedisFacade::key($key));
                # Only on the first hit: re-arming the expiry every request would
                # keep a busy client's window open forever.
                if ($value === 1) $redis->expire(RedisFacade::key($key), $ttl + 1);
                return (int) $value;
            } catch (\Throwable) {
                # Fall through to APCu rather than failing the request.
            }
        }

        if (self::apcu()) {
            $value = apcu_inc($key, 1, $success, $ttl + 1);
            if ($success) return (int) $value;
        }

        return null;
    }

    /**
     * @return \Redis|null
     */
    private static function redis(): ?\Redis
    {
        return RedisFacade::available('cache') ? RedisFacade::connection('cache') : null;
    }

    /**
     * @return bool
     */
    private static function apcu(): bool
    {
        return self::$apcu ??= function_exists('apcu_inc');
    }

    /**
     * Apply the limits from config to the current request.
     *
     * @param string      $bucket ip | key | upload
     * @param string      $identity
     * @return array{allowed:bool,count:int,limit:int,remaining:int,reset:int}|null  Null when the limiter is off.
     */
    public static function check(string $bucket, string $identity): ?array
    {
        if (!Support::config('limits.enabled', true)) return null;

        $limit = (array) Support::config("limits.$bucket", []);
        if (!($limit['requests'] ?? 0)) return null;

        return self::hit($bucket, $identity, (int) $limit['requests'], (int) ($limit['window'] ?? 60));
    }

    /**
     * A counter that is read but not spent - for a dashboard, or a second check
     * within one request.
     *
     * @param string $bucket
     * @param string $identity
     * @param int    $window
     * @return int
     */
    public static function peek(string $bucket, string $identity, int $window = 60): int
    {
        $slot = (int) floor(time() / max(1, $window));
        $key  = "cdn:rl:$bucket:" . substr(sha1($identity), 0, 16) . ":$slot";

        if ($redis = self::redis()) {
            try {
                return (int) $redis->get(RedisFacade::key($key));
            } catch (\Throwable) {
            }
        }

        if (self::apcu()) return (int) apcu_fetch($key);

        return 0;
    }
}
