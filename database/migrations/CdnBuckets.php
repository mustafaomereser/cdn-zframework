<?php

namespace Database\Migrations;

/**
 * A namespace in the delivery URL: /cdn/<slug>/<path>.
 *
 * Almost every policy the delivery path applies is a column here, because the
 * answer to "why is this file cached for a year and that one for a minute" has
 * to be visible in one row rather than spread across config and code.
 */
class CdnBuckets
{
    static $storageEngine = 'InnoDB';
    static $charset       = "utf8mb4_general_ci";
    static $table         = "cdn_buckets";
    static $db            = "local";

    public static function columns()
    {
        return [
            'id'            => ['primary'],
            'project_id'    => ['bigint', 'required', 'index', 'unique:cdn_bucket_slug'],
            'name'          => ['varchar:120', 'required'],

            # Unique within the project, not across the installation: the url
            # carries the project as its own segment, so /cdn/ayse/photos and
            # /cdn/mehmet/photos are different buckets and both people get to
            # call theirs "photos".
            'slug'          => ['varchar:120', 'required', 'unique:cdn_bucket_slug'],

            # public - anyone with the URL
            # signed - a valid signature required
            # private - management API only, never served publicly
            'visibility'    => ['varchar:20', 'default:public', 'index'],

            'disk'          => ['varchar:40', 'default:local'],

            'cache_ttl'     => ['int', 'default:31536000'],
            'immutable'     => ['bool', 'default:0'],

            # Bumped by a purge. It is part of the cache key, so incrementing it
            # invalidates every derivative of the bucket at once without touching
            # a single file.
            'cache_version' => ['int', 'default:1'],

            'transform'     => ['bool', 'default:1'],
            'signed_only'   => ['bool', 'default:0'],

            'allowed_mimes' => ['json', 'nullable'],
            'allowed_ext'   => ['json', 'nullable'],
            'max_file_size' => ['bigint', 'default:0'],

            'cors'          => ['json', 'nullable'],
            'referers'      => ['json', 'nullable'],
            'ip_rules'      => ['json', 'nullable'],

            # Origin pull. With this set the bucket answers a miss by fetching
            # from upstream and storing what comes back.
            'origin_url'    => ['varchar:255', 'nullable'],
            'origin_ttl'    => ['int', 'default:86400'],

            # Per-bucket signing secret. Null uses the global key, so rotating
            # one bucket's links does not invalidate the others.
            'signing_key'   => ['varchar:120', 'nullable'],

            'files_count'   => ['bigint', 'default:0'],
            'storage_used'  => ['bigint', 'default:0'],
            'bandwidth_used' => ['bigint', 'default:0'],

            'status'        => ['varchar:20', 'default:active', 'index'],
            'meta'          => ['json', 'nullable'],

            'timestamps',
            'softDelete',
        ];
    }
}
