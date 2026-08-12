<?php

namespace Database\Migrations;

/**
 * One logical object in a bucket.
 *
 * The row is the name; `hash` is the bytes. Two rows sharing a hash share the
 * stored object, which is why deleting a row cannot delete the file - only the
 * collector, once nothing references it, can.
 */
class CdnFiles
{
    static $storageEngine = 'InnoDB';
    static $charset       = "utf8mb4_general_ci";
    static $table         = "cdn_files";
    static $db            = "local";

    public static function columns()
    {
        return [
            'id'          => ['primary'],
            'project_id'  => ['bigint', 'required', 'index'],
            'bucket_id'   => ['bigint', 'required', 'index:cdn_file_key'],

            # The URL path inside the bucket, without a leading slash.
            'path'        => ['varchar:255', 'required', 'index:cdn_file_key'],

            'name'        => ['varchar:255', 'required'],
            'ext'         => ['varchar:20', 'nullable', 'index'],
            'mime'        => ['varchar:150', 'default:application/octet-stream'],
            'size'        => ['bigint', 'default:0'],

            'hash'        => ['char:64', 'required', 'index'],
            'disk'        => ['varchar:40', 'default:local'],
            'storage_path' => ['varchar:255', 'required'],

            # inherit | public | signed | private
            'visibility'  => ['varchar:20', 'default:inherit'],

            'width'       => ['int', 'nullable'],
            'height'      => ['int', 'nullable'],
            'duration'    => ['decimal', 'nullable'],

            'etag'        => ['varchar:80', 'required'],

            # ready | processing | quarantine | pulling
            'status'      => ['varchar:20', 'default:ready', 'index'],

            'downloads'    => ['bigint', 'default:0'],
            'bytes_served' => ['bigint', 'default:0'],
            'last_accessed_at' => ['datetime', 'nullable'],

            # Origin pull bookkeeping: when this copy was fetched and until when
            # it may be served without asking upstream again.
            'origin_fetched_at' => ['datetime', 'nullable'],
            'origin_expires_at' => ['datetime', 'nullable'],

            'uploaded_by' => ['varchar:120', 'nullable'],
            'tags'        => ['json', 'nullable'],
            'meta'        => ['json', 'nullable'],

            'timestamps',
            'softDelete',
        ];
    }
}
