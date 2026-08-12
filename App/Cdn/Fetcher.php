<?php

namespace App\Cdn;

use zFramework\Core\Facades\cURL;

/**
 * Server-side HTTP fetch, for upload-by-URL and origin pull.
 *
 * Both features amount to "the server requests whatever URL it is handed",
 * which is a request-forgery primitive unless it is fenced in. So: https only
 * by default, no address that resolves inside the network, an optional host
 * allowlist, a redirect budget, and a hard cap on the body enforced while it
 * arrives rather than after.
 *
 * The body goes to a temporary file, never to memory - the point of a CDN is to
 * handle files bigger than a php process.
 */
class Fetcher
{
    /**
     * @param string $url
     * @param array  $options
     *   max-size, timeout, max-redirect, schemes, allow-hosts, block-private, headers
     * @return array{ok:bool,status:int,mime:string,size:int,path:?string,error:?string,headers:array}
     */
    public static function get(string $url, array $options = []): array
    {
        $failure = ['ok' => false, 'status' => 0, 'mime' => '', 'size' => 0, 'path' => null, 'headers' => []];

        try {
            self::guard($url, $options);
        } catch (\InvalidArgumentException $e) {
            return $failure + ['error' => $e->getMessage()];
        }

        $maxSize  = (int) ($options['max-size'] ?? 128 * 1024 * 1024);
        $temporary = Storage::tempRoot() . '/fetch-' . Support::token(12) . '.part';

        $handle = @fopen($temporary, 'wb');
        if (!$handle) return $failure + ['error' => 'cannot-open-temp'];

        $headers = [];

        $response = cURL::set($url)->options([
            CURLOPT_FILE           => $handle,
            CURLOPT_FOLLOWLOCATION => (int) ($options['max-redirect'] ?? 3) > 0,
            CURLOPT_MAXREDIRS      => (int) ($options['max-redirect'] ?? 3),
            CURLOPT_CONNECTTIMEOUT => (int) ($options['timeout'] ?? 15),
            CURLOPT_TIMEOUT        => (int) ($options['timeout'] ?? 15),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            # A redirect can point somewhere the first check would have refused,
            # so curl is told to follow only what it was allowed to start with.
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS | CURLPROTO_HTTP,

            CURLOPT_USERAGENT      => 'zFramework-CDN/1.0',
            CURLOPT_HTTPHEADER     => self::headerLines((array) ($options['headers'] ?? [])),

            # Content-Length is a claim; this counts what actually arrives and
            # aborts mid-transfer when it goes over.
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_PROGRESSFUNCTION => function ($resource, $expected, $received) use ($maxSize) {
                return ($maxSize > 0 && ($received > $maxSize || $expected > $maxSize)) ? 1 : 0;
            },

            CURLOPT_HEADERFUNCTION => function ($resource, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
        ])->send(fn($body, $info, $error) => ['info' => $info, 'error' => $error]);

        fclose($handle);

        $info  = $response['info'] ?? [];
        $error = $response['error'] ?? [];

        $status = (int) ($info['http_code'] ?? 0);
        $size   = is_file($temporary) ? (int) filesize($temporary) : 0;

        if (!empty($error['error_no'])) {
            @unlink($temporary);
            return $failure + ['status' => $status, 'error' => $error['error_message'] ?: 'transfer-failed'];
        }

        if ($status < 200 || $status >= 300) {
            @unlink($temporary);
            return $failure + ['status' => $status, 'error' => "http-$status", 'headers' => $headers];
        }

        if ($maxSize > 0 && $size > $maxSize) {
            @unlink($temporary);
            return $failure + ['status' => $status, 'error' => 'too-large'];
        }

        # The server's declared type is a hint. What the bytes say wins, because
        # the whole point of storing it is that it will be served back later.
        $mime = strtolower(strtok((string) ($headers['content-type'] ?? ($info['content_type'] ?? '')), ';'));
        $sniffed = self::sniff($temporary);
        if ($sniffed !== null && $sniffed !== 'application/octet-stream') $mime = $sniffed;
        if ($mime === '') $mime = Support::mime((string) parse_url($url, PHP_URL_PATH));

        return [
            'ok'      => true,
            'status'  => $status,
            'mime'    => $mime,
            'size'    => $size,
            'path'    => $temporary,
            'error'   => null,
            'headers' => $headers,
        ];
    }

    /**
     * Refuse a url the server should not be making a request to.
     *
     * @param string $url
     * @param array  $options
     * @return void
     * @throws \InvalidArgumentException
     */
    public static function guard(string $url, array $options = []): void
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) throw new \InvalidArgumentException('invalid-url');

        $schemes = array_map('strtolower', (array) ($options['schemes'] ?? ['https']));
        if (!in_array(strtolower($parts['scheme'] ?? ''), $schemes, true)) throw new \InvalidArgumentException('scheme-not-allowed');

        $host  = $parts['host'];
        $hosts = array_filter((array) ($options['allow-hosts'] ?? []));

        if (count($hosts) && !Support::hostMatches($host, $hosts)) throw new \InvalidArgumentException('host-not-allowed');

        if (!($options['block-private'] ?? true)) return;

        # Resolved once. Not proof against a name that answers differently a
        # moment later - closing that needs the socket callback - but it stops
        # everything short of a deliberate rebind.
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_merge(
                array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'),
                array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
            );

        if (!count($addresses)) {
            $resolved  = gethostbyname($host);
            $addresses = filter_var($resolved, FILTER_VALIDATE_IP) ? [$resolved] : [];
        }

        # A name that resolves to nothing is left to curl: refusing here would
        # turn a dns hiccup into a permanently rejected asset.
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP)) continue;
            if (Support::isPrivateIp($address)) throw new \InvalidArgumentException('private-address');
        }
    }

    /**
     * @param array $headers
     * @return array
     */
    private static function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) $lines[] = "$name: $value";
        return $lines;
    }

    /**
     * What the bytes say the file is.
     *
     * @param string $path
     * @return string|null
     */
    public static function sniff(string $path): ?string
    {
        if (!class_exists('finfo')) return null;

        $mime = @(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        return $mime === false ? null : strtolower($mime);
    }
}
