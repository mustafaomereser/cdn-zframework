<?php

namespace Database\Migrations;

use App\Models\User;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Str;

class Users
{
    static $charset = "utf8mb4_general_ci";
    static $table   = "users";
    static $db      = "local";

    public static function columns()
    {
        return [
            'id'        => ['primary'],
            'username'  => ['varchar:51', 'unique:user'],
            'password'  => ['varchar:255'],
            'email'     => ['varchar:50', 'unique:user'],
            'api_token' => ['varchar:60', 'required'],

            # active | suspended. A suspended account cannot sign in and its
            # projects stop serving; the files are untouched, which is the
            # difference between suspending somebody and deleting them.
            'status'    => ['varchar:20', 'default:active', 'index'],

            # Set from the panel. `auth.operators` in config/cdn.php still wins
            # and is the way back in if this column ever leaves nobody holding
            # the keys - a list in a file cannot be revoked by a mistake in a
            # form.
            'is_operator' => ['tinyint:1', 'default:0'],

            'timestamps',
            'softDelete',
        ];
    }

    /**
     * @param string|null $db
     */
    public static function oncreateSeeder(?string $db = null)
    {
        (new User($db))->insert([
            'username'  => 'admin',
            'password'  => Auth::encodePassword('admin'),
            'email'     => 'admin@localhost.com',
            'api_token' => Str::rand(60)
        ]);
    }
}
