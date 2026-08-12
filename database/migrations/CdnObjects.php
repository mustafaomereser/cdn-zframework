<?php

namespace Database\Migrations;

/**
 * The stored bytes, one row per distinct sha256.
 *
 * Deduplication needs a reference count somewhere, and deriving it with a
 * COUNT over cdn_files every time a file is deleted does not scale. This row
 * carries it, so a delete is one decrement and the collector only has to look
 * at objects that reached zero.
 */
class CdnObjects
{
    static $storageEngine = 'InnoDB';
    static $charset       = "utf8mb4_general_ci";
    static $table         = "cdn_objects";
    static $db            = "local";

    public static function columns()
    {
        return [
            'id'         => ['primary'],
            'hash'       => ['char:64', 'required', 'unique'],
            'disk'       => ['varchar:40', 'default:local'],
            'storage_path' => ['varchar:255', 'required'],
            'size'       => ['bigint', 'default:0'],
            'mime'       => ['varchar:150', 'nullable'],

            'refs'       => ['int', 'default:0', 'index'],

            # Set when refs first hits zero. The collector deletes bytes only
            # after a grace period, so a delete followed by a re-upload of the
            # same content does not have to move data again.
            'orphan_at'  => ['datetime', 'nullable', 'index'],

            'timestamps',
        ];
    }
}
