<?php

namespace Database\Migrations;

/**
 * Audit trail for invalidation.
 *
 * "The old image is still showing" is the most common CDN complaint there is,
 * and it is unanswerable without a record of what was purged, when, and by
 * whom.
 */
class CdnPurges
{
    static $storageEngine = 'InnoDB';
    static $charset       = "utf8mb4_general_ci";
    static $table         = "cdn_purges";
    static $db            = "local";

    public static function columns()
    {
        return [
            'id'         => ['primary'],
            'project_id' => ['bigint', 'nullable', 'index'],
            'bucket_id'  => ['bigint', 'nullable', 'index'],

            # all | bucket | prefix | path | tag | variants
            'type'       => ['varchar:20', 'required'],
            'target'     => ['varchar:255', 'nullable'],

            'files'      => ['int', 'default:0'],
            'variants'   => ['int', 'default:0'],
            'bytes'      => ['bigint', 'default:0'],

            'issued_by'  => ['varchar:120', 'nullable'],
            'ip'         => ['varchar:45', 'nullable'],

            'created_at' => ['datetime', 'nullable', 'index'],
        ];
    }
}
