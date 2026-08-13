<?php

namespace Database\Migrations;

/**
 * Settings an operator can change from the panel.
 *
 * config/cdn.php is still where the defaults live and still the answer for
 * anything that belongs in a deploy - it is reviewable, versioned, and the same
 * on every machine. This is for the handful of values that are an account's
 * own: a cPanel token belongs to this installation and nowhere else, and asking
 * somebody to edit a php file over ssh to paste one is asking them not to.
 *
 * Read through App\Cdn\Settings, which falls back to config for every key it
 * does not hold.
 */
class CdnSettings
{
    static $storageEngine = 'InnoDB';
    static $charset       = "utf8mb4_general_ci";
    static $table         = "cdn_settings";
    static $db            = "local";

    public static function columns()
    {
        return [
            'id'    => ['primary'],

            # Dotted, the same shape config uses: `hosting.cpanel.domain`.
            'name'  => ['varchar:120', 'required', 'unique'],
            'value' => ['text', 'nullable'],

            'timestamps',
        ];
    }
}
