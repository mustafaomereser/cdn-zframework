<?php

namespace App\Cdn;

/**
 * Reversible encryption for the one value that has to be recoverable: an API
 * key's secret, when the key is used in signed-request mode.
 *
 * The bcrypt hash next to it answers "did the caller send the right secret".
 * It cannot answer "is this HMAC correct", which needs the secret itself - so
 * signed mode needs the secret back, and a hash is by definition a one-way
 * door.
 *
 * AES-256-GCM rather than the framework's Crypter: Crypter is obfuscation, and
 * says so - fine for a cookie value, not for the credential that authorises
 * deleting a bucket. GCM also authenticates, so a row edited in the database
 * fails to open rather than decrypting to something else.
 *
 * The key is derived from config/crypt.php, so rotating that invalidates every
 * sealed secret - which is the correct behaviour for a key rotation, and the
 * reason `security key --regen` should be followed by re-issuing API keys.
 */
class Secret
{
    private const CIPHER = 'aes-256-gcm';

    /**
     * @return string
     */
    private static function key(): string
    {
        $crypt = (array) \zFramework\Core\Facades\Config::get('crypt');

        # Not the config value directly: it is a 30 character printable string,
        # and the cipher wants 32 bytes of key material.
        return hash_hkdf('sha256', ($crypt['key'] ?? '') . ($crypt['salt'] ?? ''), 32, 'cdn-api-secret');
    }

    /**
     * @param string $plain
     * @return string
     */
    public static function seal(string $plain): string
    {
        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) throw new \RuntimeException('CDN: cannot seal the key secret.');

        return \zFramework\Core\Facades\Str::base64UrlEncode($iv . $tag . $cipher);
    }

    /**
     * @param string|null $sealed
     * @return string|null  Null when it cannot be opened - a rotated crypt key,
     *                      or a tampered row.
     */
    public static function open(?string $sealed): ?string
    {
        if (!$sealed) return null;

        $raw = \zFramework\Core\Facades\Str::base64UrlDecode($sealed);
        if (strlen($raw) < 29) return null;

        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plain === false ? null : $plain;
    }
}
