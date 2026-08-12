<?php

namespace Database\Migrations;

/**
 * Outbound notifications: upload, delete, purge, quota.
 *
 * Delivery carries an HMAC of the body in X-Cdn-Signature, signed with `secret`
 * - the receiver has no other way to know the call came from here.
 */
class CdnWebhooks
{
    static $storageEngine = 'InnoDB';
    static $charset       = "utf8mb4_general_ci";
    static $table         = "cdn_webhooks";
    static $db            = "local";

    public static function columns()
    {
        return [
            'id'         => ['primary'],
            'project_id' => ['bigint', 'required', 'index'],
            'bucket_id'  => ['bigint', 'nullable', 'index'],

            'url'        => ['varchar:255', 'required'],
            'events'     => ['json', 'nullable'],
            'secret'     => ['varchar:120', 'required'],

            'status'      => ['varchar:20', 'default:active', 'index'],
            'last_status' => ['smallint', 'nullable'],
            'last_error'  => ['varchar:255', 'nullable'],
            'last_called_at' => ['datetime', 'nullable'],

            # Consecutive failures. The sender gives up on a hook that has been
            # failing for a while rather than retrying it forever.
            'failures'   => ['int', 'default:0'],

            'timestamps',
            'softDelete',
        ];
    }
}
