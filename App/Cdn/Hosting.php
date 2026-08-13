<?php

namespace App\Cdn;

use zFramework\Core\Helpers\cPanel\API;

/**
 * What the hosting account itself says it is using.
 *
 * The Server page reads the filesystem, which answers "how full is the disk
 * this server has". On shared hosting that is not the number that stops you:
 * the account has its own disk quota and - more often the one that runs out
 * first - a limit on how many files it may hold at all. A content addressed
 * store spends one file per stored object and another per generated image, so
 * an installation can sit at 400 MB of an unlimited quota and still be a few
 * thousand files from a hard stop.
 *
 * Neither number is visible to PHP: they are filesystem quotas, and quotactl is
 * not something a web request gets to call. cPanel knows them, and will say so
 * over its own API to a token that belongs to the account.
 *
 * Off unless configured. It is one https call to the control panel, so it is
 * made only on the page that shows it, with a short timeout, and a failure is a
 * missing block rather than a broken page.
 */
class Hosting
{
    /**
     * @return bool
     */
    public static function configured(): bool
    {
        $config = (array) Support::config('hosting.cpanel', []);

        return (bool) ($config['enabled'] ?? false)
            && ($config['domain'] ?? '') !== ''
            && ($config['username'] ?? '') !== ''
            && ($config['token'] ?? '') !== '';
    }

    /**
     * Disk, files and bandwidth as the control panel reports them.
     *
     * @return array|null Null when it is not configured or did not answer.
     */
    public static function usage(): ?array
    {
        if (!self::configured()) return null;

        $config = (array) Support::config('hosting.cpanel', []);

        API::$domain   = (string) $config['domain'];
        API::$username = (string) $config['username'];
        API::$apiToken = (string) $config['token'];

        try {
            $response = API::request('ResourceUsage/get_usages');
        } catch (\Throwable $thrown) {
            return null;
        }

        if (!is_array($response) || !($response['status'] ?? 0) || !isset($response['data'])) return null;

        # The ids cPanel uses for the three that matter here. Everything else it
        # returns - addon domains, email accounts, subdomains - belongs on a
        # hosting panel rather than on this one.
        $wanted = [
            'disk_usage' => 'disk',
            'file_usage' => 'files',
            'bandwidth'  => 'bandwidth',
        ];

        $out = [];

        foreach ((array) $response['data'] as $entry) {
            $key = $wanted[$entry['id'] ?? ''] ?? null;

            if (!$key) continue;

            # `maximum` is null or 0 for unlimited, which is a different thing
            # from zero and has to stay distinguishable.
            $maximum = $entry['maximum'] ?? null;

            $out[$key] = [
                'used'    => (int) ($entry['usage'] ?? 0),
                'maximum' => $maximum === null || (int) $maximum === 0 ? null : (int) $maximum,
                'share'   => null,
            ];

            if ($out[$key]['maximum']) {
                $out[$key]['share'] = (int) round($out[$key]['used'] / $out[$key]['maximum'] * 100);
            }
        }

        return count($out) ? $out : null;
    }
}
