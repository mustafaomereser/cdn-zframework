<?php

namespace Database\Migrations;

/**
 * One row per delivered request, written after the response is sent.
 *
 * This table grows faster than everything else in the schema put together.
 * `cdn.logging.keep-days` prunes it and the daily rollup keeps the history that
 * matters, so treat it as a window rather than an archive.
 */
class CdnAccessLogs
{
    static $storageEngine = 'InnoDB';
    static $charset       = "utf8mb4_general_ci";
    static $table         = "cdn_access_logs";
    static $db            = "local";

    public static function columns()
    {
        return [
            'id'         => ['primary'],
            'project_id' => ['bigint', 'nullable', 'index'],
            'bucket_id'  => ['bigint', 'nullable', 'index:cdn_log_bucket'],
            'file_id'    => ['bigint', 'nullable', 'index'],

            'path'       => ['varchar:255', 'nullable'],
            'method'     => ['varchar:10', 'default:GET'],
            'status'     => ['smallint', 'default:200', 'index'],
            'bytes'      => ['bigint', 'default:0'],

            # hit | miss | revalidated | transformed | pulled | bypass | denied
            'cache'      => ['varchar:20', 'default:miss', 'index'],
            'variant'    => ['char:40', 'nullable'],

            'ip'         => ['varchar:45', 'nullable', 'index'],
            'country'    => ['char:2', 'nullable'],
            'referer'    => ['varchar:255', 'nullable'],
            'agent'      => ['varchar:255', 'nullable'],
            'api_key_id' => ['bigint', 'nullable'],

            'duration'   => ['int', 'default:0'],   # milliseconds

            # Sampling weight: 1 when everything is logged, 20 when one request
            # in twenty is. The rollup multiplies by it.
            'weight'     => ['int', 'default:1'],

            'created_at' => ['datetime', 'nullable', 'index:cdn_log_bucket'],
        ];
    }
}
