<?php

namespace App\Cdn;

use zFramework\Core\Facades\Session;

/**
 * A message that survives a redirect.
 *
 * The framework's Alerts do not. run.php calls `Alerts::unset()` immediately
 * after a ResponseSignal is sent - which is what `redirect()` and `back()` are -
 * so a message set just before one is deleted from the session by the very
 * request that set it. That works for the case it was built for, where the
 * response is rendered in the same request and carries its own alerts, and
 * silently loses every "Saved." in a post-redirect-get.
 *
 * Which is most of a panel. So this is a second, smaller flash under a key the
 * framework does not touch, read once by whichever page renders next.
 *
 * Same shape as Alerts - [type, message] - so the panel can hand both to the
 * same toast call, and the sign-in page can render both as markup.
 */
class Flash
{
    private const KEY = 'cdn-flash';

    /**
     * @param string $message
     * @return void
     */
    public static function success(string $message): void
    {
        self::push('success', $message);
    }

    /**
     * @param string $message
     * @return void
     */
    public static function danger(string $message): void
    {
        self::push('danger', $message);
    }

    /**
     * @param string $message
     * @return void
     */
    public static function info(string $message): void
    {
        self::push('info', $message);
    }

    /**
     * @param string $type
     * @param string $message
     * @return void
     */
    public static function push(string $type, string $message): void
    {
        if (trim($message) === '') return;

        $list = (array) (Session::get(self::KEY) ?? []);

        # Keyed by content: a double submit, or a loop that reports the same
        # refusal per row, should not stack ten identical toasts.
        $list[md5($type . $message)] = [$type, $message];

        Session::set(self::KEY, $list);
    }

    /**
     * Everything pending, and it is pending no longer.
     *
     * @return array
     */
    public static function take(): array
    {
        $list = (array) (Session::get(self::KEY) ?? []);

        if (count($list)) Session::delete(self::KEY);

        return $list;
    }
}
