<?php

namespace Database\Migrations;

/**
 * What an operator did.
 *
 * Quotas, suspensions and deletions are the things somebody asks about a month
 * later - "who gave that account 200 GB", "why did this project stop serving".
 * Without a record the answer is a guess, and the access log is the wrong place
 * to look for it: that one is about traffic, is sampled, and is pruned.
 *
 * Nothing here is written by the delivery path, so this table stays small and
 * is never trimmed.
 */
class CdnAudits
{
    static $storageEngine = 'InnoDB';
    static $charset       = "utf8mb4_general_ci";
    static $table         = "cdn_audits";
    static $db            = "local";

    public static function columns()
    {
        return [
            'id'           => ['primary'],

            # Who. The e-mail is copied rather than joined: the point of this
            # table is to still make sense after the account is gone.
            'actor_id'     => ['bigint', 'nullable', 'index'],
            'actor_email'  => ['varchar:190', 'nullable'],

            'action'       => ['varchar:60', 'required', 'index'],   # quota, suspend, restore, delete, operator

            # What it was done to. Same reason for the label.
            'subject_type' => ['varchar:30', 'nullable', 'index'],   # user | project
            'subject_id'   => ['bigint', 'nullable', 'index'],
            'subject_label' => ['varchar:190', 'nullable'],

            # Before and after, as json. A quota change is only meaningful with
            # both numbers in it.
            'detail'       => ['json', 'nullable'],

            'ip'           => ['varchar:45', 'nullable'],

            'timestamps',
        ];
    }
}
