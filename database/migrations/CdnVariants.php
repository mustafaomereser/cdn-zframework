<?php

namespace Database\Migrations;

/**
 * A derivative: one file, one set of transform parameters, one stored result.
 *
 * `signature` is a hash of (file id, normalised parameters, bucket cache
 * version), so it is both the lookup key and the reason a purge can invalidate
 * everything by bumping a single integer.
 */
class CdnVariants
{
    static $storageEngine = 'InnoDB';
    static $charset       = "utf8mb4_general_ci";
    static $table         = "cdn_variants";
    static $db            = "local";

    public static function columns()
    {
        return [
            'id'         => ['primary'],
            'file_id'    => ['bigint', 'required', 'index:cdn_variant_key'],
            'bucket_id'  => ['bigint', 'required', 'index'],
            'signature'  => ['char:40', 'required', 'index:cdn_variant_key'],

            'params'     => ['json', 'nullable'],
            'format'     => ['varchar:10', 'nullable'],
            'mime'       => ['varchar:150', 'nullable'],
            'width'      => ['int', 'nullable'],
            'height'     => ['int', 'nullable'],
            'size'       => ['bigint', 'default:0'],

            'storage_path' => ['varchar:255', 'required'],
            'etag'       => ['varchar:80', 'nullable'],

            'hits'       => ['bigint', 'default:0'],
            'last_accessed_at' => ['datetime', 'nullable', 'index'],

            # How long the resample took. Kept because it is the number that says
            # whether a preset is worth pre-generating.
            'build_ms'   => ['int', 'default:0'],

            'timestamps',
        ];
    }
}
