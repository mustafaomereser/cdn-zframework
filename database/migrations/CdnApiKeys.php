<?php

namespace Database\Migrations;

/**
 * Credentials for the management API.
 *
 * The secret is stored as a bcrypt hash and shown once, at creation. A key that
 * can be read back out of the panel is a key that leaks with the panel.
 */
class CdnApiKeys
{
    static $storageEngine = 'InnoDB';
    static $charset       = "utf8mb4_general_ci";
    static $table         = "cdn_api_keys";
    static $db            = "local";

    public static function columns()
    {
        return [
            'id'          => ['primary'],
            'project_id'  => ['bigint', 'required', 'index'],
            'name'        => ['varchar:120', 'required'],

            'access_key'  => ['varchar:64', 'required', 'unique'],
            'secret_hash' => ['varchar:255', 'required'],

            # The same secret sealed rather than hashed, for signed-request
            # mode - verifying an HMAC needs the secret back, which a hash
            # cannot give. Null on a key that may only use the simpler mode.
            'secret_cipher' => ['varchar:255', 'nullable'],

            # read | upload | delete | purge | admin. Null is read-only.
            'scopes'      => ['json', 'nullable'],

            # Bucket ids this key may touch. Null is every bucket in the project.
            'buckets'     => ['json', 'nullable'],

            'allowed_ips' => ['json', 'nullable'],
            'status'      => ['varchar:20', 'default:active', 'index'],
            'expires_at'  => ['datetime', 'nullable'],

            'last_used_at' => ['datetime', 'nullable'],
            'last_used_ip' => ['varchar:45', 'nullable'],
            'requests'     => ['bigint', 'default:0'],

            'timestamps',
            'softDelete',
        ];
    }
}
