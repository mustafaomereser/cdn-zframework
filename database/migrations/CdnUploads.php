<?php

namespace Database\Migrations;

/**
 * A resumable upload in progress.
 *
 * Chunks land in storage/cdn/temp and are concatenated on completion. The row
 * is what makes a resume possible after the client's connection dies: it knows
 * which chunks arrived, so the client can ask and send only the rest.
 */
class CdnUploads
{
    static $storageEngine = 'InnoDB';
    static $charset       = "utf8mb4_general_ci";
    static $table         = "cdn_uploads";
    static $db            = "local";

    public static function columns()
    {
        return [
            'id'         => ['primary'],
            'upload_id'  => ['char:40', 'required', 'unique'],

            'project_id' => ['bigint', 'required', 'index'],
            'bucket_id'  => ['bigint', 'required', 'index'],
            'api_key_id' => ['bigint', 'nullable'],

            'path'       => ['varchar:255', 'required'],
            'name'       => ['varchar:255', 'required'],
            'mime'       => ['varchar:150', 'nullable'],
            'size'       => ['bigint', 'default:0'],
            'received'   => ['bigint', 'default:0'],
            'chunk_size' => ['int', 'default:0'],
            'chunks'     => ['json', 'nullable'],

            'temp_path'  => ['varchar:255', 'required'],

            # Optional client-supplied checksum, verified before the object is
            # accepted. A resumable upload has more chances to arrive corrupt
            # than a single POST.
            'checksum'   => ['char:64', 'nullable'],

            # pending | uploading | completed | aborted
            'status'     => ['varchar:20', 'default:pending', 'index'],
            'meta'       => ['json', 'nullable'],

            'expires_at' => ['datetime', 'required', 'index'],

            'timestamps',
        ];
    }
}
