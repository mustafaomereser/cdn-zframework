<?php

namespace Database\Migrations;

/**
 * A tenant. Buckets, keys, quota and billing all hang off this row.
 *
 * A single-tenant installation still has one: the delivery path resolves a
 * project on every request, and "there is always exactly one" is a much simpler
 * rule than "there may be none".
 */
class CdnProjects
{
    static $storageEngine = 'InnoDB';
    static $charset       = "utf8mb4_general_ci";
    static $table         = "cdn_projects";
    static $db            = "local";

    public static function columns()
    {
        return [
            'id'               => ['primary'],
            'name'             => ['varchar:120', 'required'],
            'slug'             => ['varchar:120', 'required', 'unique'],
            'status'           => ['varchar:20', 'default:active', 'index'],   # active | suspended

            # 0 means unlimited. Bytes, both of them.
            'storage_quota'    => ['bigint', 'default:0'],
            'bandwidth_quota'  => ['bigint', 'default:0'],

            # Maintained by the delivery path rather than recomputed: counting
            # every object on every request is not something a CDN can afford.
            'storage_used'     => ['bigint', 'default:0'],
            'bandwidth_used'   => ['bigint', 'default:0'],
            'bandwidth_period' => ['varchar:7', 'nullable'],   # YYYY-MM the counter belongs to

            'owner_id'         => ['bigint', 'nullable', 'index'],
            'meta'             => ['json', 'nullable'],

            'timestamps',
            'softDelete',
        ];
    }
}
