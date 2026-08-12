<?php

namespace App\Cdn;

use zFramework\Core\Facades\Defer;
use zFramework\Core\Facades\DB;
use zFramework\Core\ResponseSignal;

/**
 * Authentication for the management API.
 *
 * Two ways in, and the difference is what leaks when something is logged badly:
 *
 *   secret  - the key and its secret are sent. Simple, and fine over TLS to a
 *             trusted client. Anything that captures the request has the key.
 *   hmac    - the request is signed with the secret, which never leaves the
 *             client. A captured request is replayable for a few minutes and
 *             for that one request only.
 *
 * The stored secret is a bcrypt hash, so neither mode can be answered by
 * reading the table.
 */
class Credentials
{
    /**
     * The key row for this request, once resolved.
     */
    private static ?array $key = null;

    /**
     * Reason the last attempt failed. For the log, not for the client.
     */
    private static ?string $failure = null;

    /**
     * @return array|null
     */
    public static function key(): ?array
    {
        return self::$key;
    }

    /**
     * @return int
     */
    public static function projectId(): int
    {
        return (int) (self::$key['project_id'] ?? 0);
    }

    /**
     * @return string|null
     */
    public static function failure(): ?string
    {
        return self::$failure;
    }

    /**
     * Resolve the caller from the request headers.
     *
     * @return bool
     */
    public static function attempt(): bool
    {
        self::$key = null;

        [$access, $secret] = self::presented();

        if ($access === null) return self::fail('no-credentials');

        # Read straight through rather than through the model: both forms of the
        # secret are in ApiKeys::$guard, which is what keeps them out of every
        # response - and out of the one place that needs them. The row does not
        # leave this class.
        $row = (new DB)->prepare("SELECT * FROM cdn_api_keys WHERE access_key = :key AND deleted_at IS NULL LIMIT 1", ['key' => $access])->fetch(\PDO::FETCH_ASSOC);

        if (!$row) return self::fail('unknown-key');

        if (($row['status'] ?? 'active') !== 'active') return self::fail('key-' . $row['status']);
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) return self::fail('key-expired');

        $allowed = Support::json($row['allowed_ips']);
        if (count($allowed)) {
            $ip      = function_exists('ip') ? (string) ip() : '';
            $matched = false;
            foreach ($allowed as $rule) if (Guard::ipMatches($ip, (string) $rule)) $matched = true;
            if (!$matched) return self::fail('ip-not-allowed');
        }

        $verified = $secret !== null
            ? password_verify($secret, (string) $row['secret_hash'])
            : self::verifySignature($row);

        if (!$verified) return self::fail('bad-secret');

        self::$key = $row;

        # Written after the response: an audit trail must not be on the caller's
        # clock, and this is one UPDATE per API request.
        Defer::after(function () use ($row) {
            try {
                (new DB)->prepare(
                    "UPDATE cdn_api_keys SET last_used_at = :now, last_used_ip = :ip, requests = requests + 1 WHERE id = :id",
                    ['now' => date('Y-m-d H:i:s'), 'ip' => function_exists('ip') ? ip() : null, 'id' => $row['id']]
                );
            } catch (\Throwable) {
            }
        }, 'cdn-key-usage');

        return true;
    }

    /**
     * Read the credentials out of the request.
     *
     * @return array{0:?string,1:?string}  [access key, secret or null for hmac]
     */
    private static function presented(): array
    {
        $authorization = Support::header('Authorization');

        if ($authorization !== null && stripos($authorization, 'bearer ') === 0) {
            $token = trim(substr($authorization, 7));
            if (strstr($token, ':')) {
                [$access, $secret] = explode(':', $token, 2);
                return [$access, $secret];
            }
            # A bare bearer token is an access key on its own; the signature
            # headers have to carry the proof.
            return [$token, null];
        }

        $access = Support::header('X-Cdn-Key');
        if ($access === null) return [null, null];

        return [$access, Support::header('X-Cdn-Secret')];
    }

    /**
     * Check a signed request.
     *
     * The signature covers the method, the path, a hash of the body and the
     * timestamp - so a captured request cannot be replayed against a different
     * endpoint, and cannot be replayed at all once the window passes.
     *
     * @param array $row
     * @return bool
     */
    private static function verifySignature(array $row): bool
    {
        if (!Support::config('api.hmac.enabled', true)) return false;

        $signature = Support::header('X-Cdn-Signature');
        $timestamp = (int) Support::header('X-Cdn-Timestamp');

        if ($signature === null || !$timestamp) return false;

        $window = (int) Support::config('api.hmac.window', 300);
        if (abs(time() - $timestamp) > $window) return false;

        # The raw body, not a re-encoding of the parsed one: a client signs the
        # bytes it sent.
        $body = (string) @file_get_contents('php://input');

        $payload = implode("\n", [
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            strtok($_SERVER['REQUEST_URI'] ?? '/', '?'),
            hash('sha256', $body),
            $timestamp,
        ]);

        # Verifying an HMAC needs the secret itself, so signed mode reads it back
        # out of the sealed column. A key created without one can only use the
        # send-the-secret mode.
        $secret = Secret::open($row['secret_cipher'] ?? null);
        if ($secret === null) return false;

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @param string $reason
     * @return false
     */
    private static function fail(string $reason): bool
    {
        self::$failure = $reason;
        return false;
    }

    /**
     * Refuse the request unless the key carries a scope.
     *
     * @param string $scope
     * @return void
     */
    public static function require(string $scope): void
    {
        $scopes = Support::json(self::$key['scopes'] ?? null);

        # No scopes recorded means read-only: a key created without an explicit
        # list should be the least dangerous thing, not the most.
        if (!count($scopes)) $scopes = ['read'];

        if (in_array('admin', $scopes, true) || in_array($scope, $scopes, true)) return;

        throw new ResponseSignal(403, ['Content-Type' => 'application/json'], json_encode([
            'ok'    => false,
            'error' => 'scope-required',
            'scope' => $scope,
        ]));
    }

    /**
     * Is this bucket within the key's reach?
     *
     * @param array $bucket
     * @return bool
     */
    public static function allows(array $bucket): bool
    {
        if (self::$key === null) return false;
        if ((int) $bucket['project_id'] !== self::projectId()) return false;

        $buckets = Support::json(self::$key['buckets'] ?? null);
        if (!count($buckets)) return true;

        return in_array((int) $bucket['id'], array_map('intval', $buckets), true);
    }

    /**
     * A label for audit columns.
     *
     * @return string
     */
    public static function label(): string
    {
        return self::$key ? 'key:' . self::$key['access_key'] : 'anonymous';
    }

    /**
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$key     = null;
        self::$failure = null;
    }
}
