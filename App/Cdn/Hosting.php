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
     * What the last call did, for the page that offers to diagnose it.
     */
    private static array $last = [];

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

        return self::fetch(
            'https://' . $config['domain'] . ':2083/execute/' . $endpoint
                . (count($params) ? '?' . http_build_query($params) : '')
        );
    }

    /**
     * One API2 call.
     *
     * cPanel has two APIs and cron lives only in the older one - UAPI has no
     * Cron module at all, which is why asking it for one reads as "failed to
     * load module" rather than as a permission problem.
     *
     * @param string $module e.g. "Cron"
     * @param string $function e.g. "listcron"
     * @param array  $params
     * @return array|null The `cpanelresult` body, or null when it did not answer.
     */
    private static function call2(string $module, string $function, array $params = []): ?array
    {
        if (!self::configured()) return null;

        $config = self::credentials();

        $query = [
            'cpanel_jsonapi_user'       => $config['username'],
            'cpanel_jsonapi_apiversion' => 2,
            'cpanel_jsonapi_module'     => $module,
            'cpanel_jsonapi_func'       => $function,
        ] + $params;

        $response = self::fetch('https://' . $config['domain'] . ':2083/json-api/cpanel?' . http_build_query($query));

        if (!is_array($response)) return null;

        # API2 wraps everything one level deeper. Unwrapped here so the callers
        # never have to know which of the two APIs answered them.
        $result = $response['cpanelresult'] ?? null;

        if (!is_array($result)) return null;

        # And it reports failure in three different places depending on what
        # went wrong: a top-level error, an error inside the event, or a result
        # flag of zero. Any of them means no.
        $failed = isset($result['error'])
            || isset($result['event']['error'])
            || (isset($result['event']['result']) && !(int) $result['event']['result']);

        if ($failed) {
            self::$last['body'] = json_encode([
                'errors' => [(string) ($result['error'] ?? $result['event']['error'] ?? 'api2-failed')],
            ], JSON_UNESCAPED_UNICODE);

            return null;
        }

        return $result;
    }

    /**
     * The one place a request to cPanel is actually made.
     *
     * @param string $url
     * @return array|null
     */
    private static function fetch(string $url): ?array
    {
        $config = self::credentials();

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
        $code     = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        curl_close($curl);

        # Kept for the test button. Every failure here has a different fix and
        # "it did not answer" is the one message that helps with none of them.
        self::$last = ['error' => $error, 'code' => $code, 'body' => is_string($response) ? substr($response, 0, 400) : null];

        if ($error !== null) return null;

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

        # cPanel does not spell these the same on every version - disk_usage and
        # diskusage, bandwidth and bwlimit, file_usage and filesusage - so the
        # id is matched rather than looked up. Everything else it returns (addon
        # domains, email accounts, subdomains) belongs on a hosting panel rather
        # than on this one.
        $out = [];

        foreach ((array) $response['data'] as $entry) {
            $id = strtolower((string) ($entry['id'] ?? ''));

            if ($id === '') continue;

            $key = null;

            if (str_contains($id, 'file') || str_contains($id, 'inode')) $key = 'files';
            elseif (str_contains($id, 'disk')) $key = 'disk';
            elseif (str_contains($id, 'bandwidth') || str_contains($id, 'bwlimit')) $key = 'bandwidth';

            # First spelling wins: a version that sends both disk_usage and
            # diskusage is saying one thing twice, not two things.
            if (!$key || isset($out[$key])) continue;

            # `maximum` is null, 0 or the word "unlimited" depending on the
            # version, all of which mean no ceiling - a different thing from
            # zero, and it has to stay distinguishable.
            $maximum = $entry['maximum'] ?? null;

            if (!is_numeric($maximum)) $maximum = null;

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
     * Ask cPanel one question and report exactly how it went.
     *
     * The page used to say "cPanel did not answer" for every failure, which is
     * the one sentence that helps with none of them: a blocked port, a wrong
     * hostname, a token from another account and a token with the wrong
     * permissions each have a different fix and each says so here.
     *
     * @return array{ok:bool,message:string,detail:string|null}
     */
    public static function test(): array
    {
        if (!self::configured()) return ['ok' => false, 'message' => 'not-configured', 'detail' => null];

        $response = self::call('ResourceUsage/get_usages');
        $last     = self::$last;

        if (($response['status'] ?? 0)) {
            # Connected is not the same as useful. If it answered and nothing in
            # it reads as disk, files or bandwidth, say so with the ids it did
            # send - that is the whole diagnosis.
            $usage = self::usage();

            if ($usage) return ['ok' => true, 'message' => 'ok', 'detail' => null];

            $ids = array_values(array_filter(array_map(
                fn($entry) => (string) ($entry['id'] ?? ''),
                (array) ($response['data'] ?? [])
            )));

            return [
                'ok'      => false,
                'message' => 'no-metrics',
                'detail'  => count($ids) ? implode(', ', array_slice($ids, 0, 20)) : null,
            ];
        }

        # curl never got an answer: the port, the hostname, or DNS.
        if (($last['error'] ?? null) !== null) {
            return [
                'ok'      => false,
                'message' => str_contains(strtolower($last['error']), 'timed out') || str_contains(strtolower($last['error']), 'connect')
                    ? 'unreachable'
                    : 'curl',
                'detail'  => $last['error'],
            ];
        }

        # It answered, and said no.
        if (($last['code'] ?? 0) === 401 || ($last['code'] ?? 0) === 403) {
            return ['ok' => false, 'message' => 'rejected', 'detail' => 'HTTP ' . $last['code']];
        }

        if (($last['code'] ?? 0) >= 400) {
            return ['ok' => false, 'message' => 'http', 'detail' => 'HTTP ' . $last['code']];
        }

        # A 200 that is not the json this expects is nearly always a login page:
        # the address is a website, not the control panel.
        $errors = (array) ($response['errors'] ?? []);

        return [
            'ok'      => false,
            'message' => is_array($response) && count($errors) ? 'refused' : 'not-cpanel',
            'detail'  => count($errors) ? implode(' ', array_map('strval', $errors)) : ($last['body'] ?? null),
        ];
    }

    /**
     * The cron lines the account has, with ours marked.
     *
     * @return array|null Null when it is not configured or did not answer.
     */
    public static function crons(): ?array
    {
        if (!self::configured()) return null;

        # API2, not UAPI: cron is one of the few things cPanel never moved, and
        # UAPI answers "failed to load module Cron" because it genuinely has no
        # such module rather than because the token is short of a permission.
        $response = self::call2('Cron', 'listcron');

        if (!is_array($response)) return null;

        # `data` is the list of lines on every version seen so far, but at
        # least one wraps it in `jobs`. Both are the same answer, and a single
        # line arrives as a bare row rather than as a list of one.
        $lines = (array) ($response['data'] ?? []);

        if (isset($lines['jobs']) && is_array($lines['jobs'])) $lines = $lines['jobs'];

        if (isset($lines['command'])) $lines = [$lines];

        $out = [];

        foreach ($lines as $line) {
            if (!is_array($line)) continue;

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
     * Whatever went wrong on the last call, for the page that has to show it.
     *
     * @return string|null
     */
    public static function lastError(): ?string
    {
        $last = self::$last;

        if (($last['error'] ?? null)) return (string) $last['error'];

        $body = (string) ($last['body'] ?? '');

        # cPanel puts the real sentence in `errors`, and everything around it is
        # noise on a page that is already narrow.
        $decoded = $body !== '' ? json_decode($body, true) : null;

        if (is_array($decoded) && count((array) ($decoded['errors'] ?? []))) {
            return implode(' ', array_map('strval', (array) $decoded['errors']));
        }

        if (($last['code'] ?? 0) >= 400) return 'HTTP ' . $last['code'];

        return $body !== '' ? $body : null;
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

            $response = self::call2('Cron', 'edit_line', self::line($schedule) + [
                'linekey' => (string) $line['key'],
                'command' => self::command(),
            ]);

            return ['ok' => is_array($response), 'error' => is_array($response) ? null : self::lastError()];
        }

        $response = self::call2('Cron', 'add_line', self::line($schedule) + ['command' => self::command()]);

        return ['ok' => is_array($response), 'error' => is_array($response) ? null : self::lastError()];
    }

    /**
     * @param int $key
     * @return array{ok:bool,error?:string}
     */
    public static function removeCron(int $key): array
    {
        if (!self::configured()) return ['ok' => false, 'error' => 'not-configured'];

        # `line` alongside `linekey`, because which of the two remove_line wants
        # depends on the version and sending both satisfies either.
        $response = self::call2('Cron', 'remove_line', ['linekey' => (string) $key, 'line' => (string) $key]);

        return ['ok' => is_array($response), 'error' => is_array($response) ? null : self::lastError()];
    }

    /**
     * A cron expression as the five fields the API wants.
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
}
