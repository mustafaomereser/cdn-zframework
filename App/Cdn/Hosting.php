<?php

namespace App\Cdn;



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
        $config = self::credentials();

        return (bool) $config['enabled']
            && $config['domain'] !== ''
            && $config['username'] !== ''
            && $config['token'] !== '';
    }

    /**
     * What the panel holds, falling back to config for anything it does not.
     *
     * @return array{enabled:bool,domain:string,username:string,token:string}
     */
    public static function credentials(): array
    {
        return [
            'enabled'  => (bool) Settings::get('hosting.cpanel.enabled', false),
            'domain'   => trim((string) Settings::get('hosting.cpanel.domain', '')),
            'username' => trim((string) Settings::get('hosting.cpanel.username', '')),
            'token'    => trim((string) Settings::get('hosting.cpanel.token', '')),
        ];
    }

    /**
     * One UAPI call.
     *
     * The framework ships a client for this and it is not used, for one reason:
     * it sets no timeout. A wrong hostname in the form would then hold the
     * operator's page open for however long the OS takes to give up on a
     * connection - half a minute is normal - on a page whose whole job is to
     * tell somebody the hostname is wrong.
     *
     * @param string $endpoint  Module/function, e.g. "ResourceUsage/get_usages"
     * @param array  $params
     * @return array|null Null when it could not be asked or did not answer.
     */
    private static function call(string $endpoint, array $params = []): ?array
    {
        if (!self::configured()) return null;

        $config = self::credentials();

        $url = 'https://' . $config['domain'] . ':2083/execute/' . $endpoint
            . (count($params) ? '?' . http_build_query($params) : '');

        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12,

            # cPanel's own certificate is usually self-signed and for a
            # hostname nobody has a certificate for. The token is what
            # authenticates here; the transport is still encrypted.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,

            CURLOPT_HTTPHEADER     => ['Authorization: cpanel ' . $config['username'] . ':' . $config['token']],
        ]);

        $response = curl_exec($curl);
        $error    = curl_errno($curl) ? curl_error($curl) : null;

        curl_close($curl);

        if ($error !== null) return ['status' => 0, 'errors' => [$error]];

        $decoded = json_decode((string) $response, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Disk, files and bandwidth as the control panel reports them.
     *
     * @return array|null Null when it is not configured or did not answer.
     */
    public static function usage(): ?array
    {
        if (!self::configured()) return null;

        $response = self::call('ResourceUsage/get_usages');

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

    /**
     * The cron lines the account has, with ours marked.
     *
     * @return array|null Null when it is not configured or did not answer.
     */
    public static function crons(): ?array
    {
        if (!self::configured()) return null;

        $response = self::call('Cron/listcron');

        if (!is_array($response) || !($response['status'] ?? 0)) return null;

        $ours = self::command();
        $out  = [];

        foreach ((array) ($response['data'] ?? []) as $line) {
            $command = (string) ($line['command'] ?? '');

            $out[] = [
                'key'      => (int) ($line['linekey'] ?? 0),
                'schedule' => trim(implode(' ', [
                    $line['minute'] ?? '*', $line['hour'] ?? '*', $line['day'] ?? '*',
                    $line['month'] ?? '*', $line['weekday'] ?? '*',
                ])),
                'command'  => $command,

                # Ours by the path it ends in rather than by an exact match: the
                # php binary in front of it differs between hosts and is exactly
                # the part somebody edits.
                'mine'     => str_contains($command, self::script()),
            ];
        }

        return $out;
    }

    /**
     * The cron line this installation wants.
     *
     * @return string
     */
    public static function command(): string
    {
        $binary = PHP_BINARY && !in_array(basename(PHP_BINARY), ['httpd', 'apache2', 'nginx'], true)
            ? PHP_BINARY
            : 'php';

        return $binary . ' ' . self::script();
    }

    /**
     * @return string
     */
    public static function script(): string
    {
        return str_replace(chr(92), '/', BASE_PATH) . '/cron/cdn.php';
    }

    /**
     * Add the housekeeping line, or move an existing one onto this schedule.
     *
     * @param string $schedule
     * @return array{ok:bool,error?:string}
     */
    public static function installCron(string $schedule = '0 * * * *'): array
    {
        if (!self::configured()) return ['ok' => false, 'error' => 'not-configured'];

        $existing = self::crons();

        foreach ((array) $existing as $line) {
            if (!$line['mine']) continue;

            $response = self::call('Cron/edit_line', self::line($schedule) + [
                'linekey' => (int) $line['key'],
                'command' => self::command(),
            ]);

            return ['ok' => (bool) ($response['status'] ?? 0), 'error' => self::error($response)];
        }

        $response = self::call('Cron/add_line', self::line($schedule) + ['command' => self::command()]);

        return ['ok' => (bool) ($response['status'] ?? 0), 'error' => self::error($response)];
    }

    /**
     * @param int $key
     * @return array{ok:bool,error?:string}
     */
    public static function removeCron(int $key): array
    {
        if (!self::configured()) return ['ok' => false, 'error' => 'not-configured'];

        $response = self::call('Cron/remove_line', ['linekey' => $key]);

        return ['ok' => (bool) ($response['status'] ?? 0), 'error' => self::error($response)];
    }

    /**
     * A cron expression as the five fields UAPI wants.
     *
     * @param string $schedule
     * @return array
     */
    private static function line(string $schedule): array
    {
        $parts = array_pad(preg_split('/\s+/', trim($schedule)) ?: [], 5, '*');

        return [
            'minute'  => $parts[0],
            'hour'    => $parts[1],
            'day'     => $parts[2],
            'month'   => $parts[3],
            'weekday' => $parts[4],
        ];
    }

    /**
     * Whatever cPanel said went wrong, in one string.
     *
     * @param mixed $response
     * @return string|null
     */
    private static function error(mixed $response): ?string
    {
        if (!is_array($response)) return 'no-response';

        if (isset($response['error'])) return (string) $response['error'];

        $errors = (array) ($response['errors'] ?? []);

        return count($errors) ? implode(' ', array_map('strval', $errors)) : null;
    }
}
