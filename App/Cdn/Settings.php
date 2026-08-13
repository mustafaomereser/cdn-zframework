<?php

namespace App\Cdn;

use App\Models\Cdn\Settings as Model;

/**
 * The few settings an operator changes from the panel, over the ones in config.
 *
 * config/cdn.php stays the answer for anything that belongs in a deploy: it is
 * reviewable, versioned, and identical on every machine. This is for values
 * that are one installation's own - a cPanel token belongs here and nowhere
 * else - where asking somebody to edit a php file over ssh to paste one is
 * asking them not to.
 *
 * Read once per request. There are a handful of rows and they are read on the
 * pages that show them, so this is one query, not a cache to invalidate.
 */
class Settings
{
    private static ?array $cache = null;

    /**
     * @param string $name    Dotted, the same shape config uses.
     * @param mixed  $default
     * @return mixed
     */
    public static function get(string $name, mixed $default = null): mixed
    {
        self::load();

        # Config is the floor: a key nobody has set in the panel is whatever the
        # file says, which is what makes this an override rather than a second
        # source of truth.
        return self::$cache[$name] ?? Support::config($name, $default);
    }

    /**
     * @param array $values name => value
     * @return void
     */
    public static function put(array $values): void
    {
        self::load();

        foreach ($values as $name => $value) {
            $model = new Model;
            $row   = $model->where('name', $name)->closureMode(false)->first();

            # Null removes it, which is how a setting goes back to whatever
            # config says rather than becoming an empty string that overrides it.
            if ($value === null) {
                if ($row) $model->where('id', $row['id'])->delete();

                unset(self::$cache[$name]);

                continue;
            }

            if ($row) (new Model)->where('id', $row['id'])->update(['value' => (string) $value]);
            else (new Model)->insert(['name' => $name, 'value' => (string) $value]);

            self::$cache[$name] = (string) $value;
        }
    }

    /**
     * @return void
     */
    private static function load(): void
    {
        if (self::$cache !== null) return;

        self::$cache = [];

        try {
            foreach ((new Model)->closureMode(false)->get() as $row) self::$cache[$row['name']] = $row['value'];
        } catch (\Throwable $thrown) {
            # Before the table exists - during a first migrate - config is the
            # whole answer, and a settings read must not be what breaks that.
            self::$cache = [];
        }
    }

    /**
     * Drop what this request read. Only matters in a long-running worker.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$cache = null;
    }
}
