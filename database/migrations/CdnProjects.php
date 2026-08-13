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

            # Both unique across the installation. The slug has to be - it is a
            # path segment - and the name follows it so that two projects a
            # person has to choose between in a dropdown can never read the same.
            'name'             => ['varchar:120', 'required', 'unique'],
            'slug'             => ['varchar:120', 'required', 'unique'],
            'status'           => ['varchar:20', 'default:active', 'index'],   # active | suspended

            # Why, in the operator's own words. Shown to the owner on the
            # project's page: "suspended" with no reason is a support ticket,
            # and the person who could answer it is the one who typed nothing.
            'suspend_reason'   => ['varchar:255', 'nullable'],

            # 0 means unlimited. Bytes, both of them.
            #
            # These are a copy of the owner's allowance, because the delivery
            # path has this row in hand and nothing else. quota_mode says where
            # the copy comes from:
            #
            #   account  the owner's numbers, rewritten whenever those change.
            #   custom   this project's own, set by an operator and left alone
            #            by an account-level change.
            #
            # Without the flag there is no way to tell a project that was given
            # 50 GB on purpose from one that happens to match its owner - and the
            # next account-level edit would silently take it away.
            'storage_quota'    => ['bigint', 'default:0'],
            'bandwidth_quota'  => ['bigint', 'default:0'],
            'quota_mode'       => ['varchar:10', 'default:account'],

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
