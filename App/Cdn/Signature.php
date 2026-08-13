<?php

namespace App\Cdn;

/**
 * Signed URLs.
 *
 * The signature covers the object path, every query parameter except the
 * signature itself, and - optionally - the client address. That "every
 * parameter" is the important part: transform arguments are query parameters,
 * so a signed thumbnail URL cannot be edited into a signed 5000px render.
 *
 * Expiry is a signed parameter too, which is why it cannot simply be pushed
 * forward by whoever holds the link.
 */
class Signature
{
    /**
     * The secret a bucket signs with.
     *
     * Per-bucket first, so one tenant's links can be invalidated without
     * touching anyone else's. Then the CDN key, then the application's crypt
     * key - which every installation already has, so signing works before
     * anything is configured.
     *
     * @param array|null $bucket
     * @return string
     */
    public static function key(?array $bucket = null): string
    {
        if (!empty($bucket['signing_key'])) return (string) $bucket['signing_key'];

        $key = Support::config('signing.key');
        if (!empty($key)) return (string) $key;

        $crypt = (array) (@include(BASE_PATH . '/config/crypt.php') ?: []);
        return ($crypt['key'] ?? '') . '|' . ($crypt['salt'] ?? '') . '|cdn';
    }

    /**
     * Query parameter names, from config.
     *
     * @return array{expires:string,signature:string,ip:string}
     */
    public static function params(): array
    {
        $params = (array) Support::config('signing.params', []);

        return [
            'expires'   => $params['expires']   ?? 'exp',
            'signature' => $params['signature'] ?? 'sig',
            'ip'        => $params['ip']        ?? 'sip',
        ];
    }

    /**
     * The exact bytes that get hashed.
     *
     * Parameters are sorted so a client reordering the query string does not
     * invalidate the link, and the signature parameter is excluded because it
     * cannot cover itself. Everything is raw-url-encoded, so a value containing
     * '&' cannot be split into two parameters that hash the same.
     *
     * @param string $path   bucket/path, no leading slash
     * @param array  $query
     * @param string $ip     Empty when the link is not address-bound.
     * @return string
     */
    private static function payload(string $path, array $query, string $ip = ''): string
    {
        $names = self::params();

        unset($query[$names['signature']]);
        ksort($query);

        $parts = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) $value = implode(',', $value);
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return ltrim($path, '/') . "\n" . implode('&', $parts) . "\n" . $ip;
    }

    /**
     * @param string     $path
     * @param array      $query
     * @param array|null $bucket
     * @param string     $ip
     * @return string
     */
    public static function calculate(string $path, array $query, ?array $bucket = null, string $ip = ''): string
    {
        $algo = (string) Support::config('signing.algo', 'sha256');
        $hash = hash_hmac($algo, self::payload($path, $query, $ip), self::key($bucket), true);

        # base64url: shorter than hex and safe in a query string without escaping.
        return \zFramework\Core\Facades\Str::base64UrlEncode($hash);
    }

    /**
     * Build a signed query string for an object.
     *
     * @param string     $path    bucket/path
     * @param array      $options ttl | expires | query | ip | bucket
     * @return string  Query string without the leading '?'
     */
    public static function query(string $path, array $options = []): string
    {
        $names  = self::params();
        $bucket = $options['bucket'] ?? null;

        $query  = (array) ($options['query'] ?? []);
        $expires = (int) ($options['expires'] ?? (time() + (int) ($options['ttl'] ?? Support::config('signing.ttl', 3600))));

        $query[$names['expires']] = $expires;

        $ip = '';
        if (!empty($options['ip'])) {
            $ip = (string) $options['ip'];
            $query[$names['ip']] = 1;
        }

        $query[$names['signature']] = self::calculate($path, $query, $bucket, $ip);

        return http_build_query($query);
    }

    /**
     * A complete signed URL.
     *
     * The signed target is project/bucket/path - the same string the delivery
     * route reconstructs - so a signature made for one project's bucket cannot
     * be replayed against another's bucket of the same name.
     *
     * @param string $projectSlug
     * @param string $bucketSlug
     * @param string $path
     * @param array  $options
     * @return string
     */
    public static function url(string $projectSlug, string $bucketSlug, string $path, array $options = []): string
    {
        $prefix = rtrim((string) Support::config('delivery.url-prefix', '/cdn'), '/');
        $target = trim($projectSlug, '/') . '/' . trim($bucketSlug, '/') . '/' . ltrim($path, '/');

        # host() reads $_SERVER, which the CLI does not have - a command line
        # caller passes its own base instead.
        $base = (PHP_SAPI === 'cli' ? '' : host()) . $prefix . '/' . $target;

        return $base . '?' . self::query($target, $options);
    }

    /**
     * Check a request's signature.
     *
     * Returns true, or a short reason. The reason is for the access log and the
     * panel - the client is told 403 and nothing else, since "expired" versus
     * "wrong signature" is information an attacker can iterate against.
     *
     * @param string     $path   bucket/path
     * @param array      $query  $_GET
     * @param array|null $bucket
     * @param string|null $ip
     * @return true|string
     */
    public static function verify(string $path, array $query, ?array $bucket = null, ?string $ip = null): true|string
    {
        $names    = self::params();
        $provided = (string) ($query[$names['signature']] ?? '');

        if ($provided === '') return 'missing';

        $expires = (int) ($query[$names['expires']] ?? 0);
        if ($expires <= 0) return 'no-expiry';

        $leeway = (int) Support::config('signing.leeway', 30);
        if ($expires + $leeway < time()) return 'expired';

        # Address binding is requested by a signed parameter, so a client cannot
        # drop it: removing it changes the payload and the signature fails.
        $bound = !empty($query[$names['ip']]);
        $client = $bound ? (string) ($ip ?? (PHP_SAPI === 'cli' ? '' : ip())) : '';

        $expected = self::calculate($path, $query, $bucket, $client);

        # Timing-safe: a byte-by-byte comparison leaks how much of a guessed
        # signature was right, which is enough to reconstruct one.
        return hash_equals($expected, $provided) ? true : 'mismatch';
    }

    /**
     * Query parameters that belong to signing rather than to the transform.
     *
     * The transformer has to ignore them, or a signed URL and its unsigned
     * equivalent would build two different derivatives of the same image.
     *
     * @return array
     */
    public static function reserved(): array
    {
        return array_values(self::params());
    }
}
