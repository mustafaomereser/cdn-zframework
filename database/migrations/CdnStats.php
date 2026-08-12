<?php

namespace Database\Migrations;

/**
 * Daily rollup per bucket. Survives log pruning, and is what the dashboard
 * charts read - a year of traffic is 365 rows per bucket rather than a table
 * scan over hundreds of millions of log lines.
 */
class CdnStats
{
    static $storageEngine = 'InnoDB';
    static $charset       = "utf8mb4_general_ci";
    static $table         = "cdn_stats";
    static $db            = "local";

    public static function columns()
    {
        return [
            'id'         => ['primary'],
            'date'       => ['date', 'required', 'unique:cdn_stat_key'],
            'project_id' => ['bigint', 'required', 'unique:cdn_stat_key'],
            'bucket_id'  => ['bigint', 'required', 'unique:cdn_stat_key'],

            'requests'   => ['bigint', 'default:0'],
            'bytes'      => ['bigint', 'default:0'],
            'hits'       => ['bigint', 'default:0'],
            'misses'     => ['bigint', 'default:0'],
            'transforms' => ['bigint', 'default:0'],
            'errors'     => ['bigint', 'default:0'],
            'denied'     => ['bigint', 'default:0'],
            'visitors'   => ['int', 'default:0'],

            'timestamps',
        ];
    }
}
