<?php

namespace App\Cdn;

use zFramework\Core\Facades\Response;
use zFramework\Core\ResponseSignal;

/**
 * Turning a file on disk into an HTTP response.
 *
 * This is where most of what people mean by "a CDN" actually is: conditional
 * requests so a repeat visit costs 304 bytes instead of a megabyte, range
 * requests so a video can be seeked and a download resumed, an ETag that is
 * stable because the URL addresses content, and a body that is streamed rather
 * than assembled in memory.
 *
 * Two output paths, because the framework runs under two very different SAPIs:
 * under FPM the buffer is dropped and the file is written to the socket in
 * chunks, so a 4 GB video costs a 256 KB buffer. Under a long-running worker
 * the body has to be handed back as a string - there is no socket to write to -
 * which is exactly why `delivery.offload` exists.
 */
class Delivery
{
    /**
     * Bytes served by this request. Read by the logger afterwards, since a
     * ranged response transfers less than the file's size.
     */
    public static int $sent = 0;

    /**
     * Only matters in a long-running worker, where a static outlives the
     * request: without this the next visitor's log line starts at the previous
     * one's byte count.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$sent = 0;
    }

    /**
     * Send a stored file as the response. Does not return.
     *
     * @param array $options
     *   path         absolute filesystem path
     *   mime         content type
     *   size         bytes on disk
     *   etag         already quoted
     *   modified     unix timestamp
     *   filename     name for Content-Disposition
     *   ttl          max-age
     *   immutable    bool
     *   disposition  inline | attachment
     *   cache        value for X-Cdn-Cache
     *   vary         array of header names
     *   headers      extra name => value
     * @return never
     */
    public static function send(array $options): never
    {
        $path     = (string) $options['path'];
        $size     = (int) ($options['size'] ?? (is_file($path) ? filesize($path) : 0));
        $mime     = (string) ($options['mime'] ?? 'application/octet-stream');
        $etag     = (string) ($options['etag'] ?? '');
        $modified = (int) ($options['modified'] ?? (is_file($path) ? filemtime($path) : time()));

        $headers = self::headers($options, $size, $mime, $etag, $modified);

        # Conditional request: the client already has these bytes. Answered
        # before anything is opened, which is the whole point - a well-cached
        # asset host answers most of its traffic without reading a disk.
        if (self::notModified($etag, $modified)) {
            unset($headers['Content-Length'], $headers['Content-Type'], $headers['Content-Disposition']);
            throw new ResponseSignal(304, $headers);
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'OPTIONS') throw new ResponseSignal(204, $headers);

        # Range. A single range covers video seeking and resumed downloads;
        # a multipart range response is answered with the whole file, which the
        # RFC allows and no real client depends on.
        $range = ($options['ranges'] ?? Support::config('delivery.ranges', true)) ? self::range($size) : null;

        if ($range === false) {
            throw new ResponseSignal(416, ['Content-Range' => "bytes */$size", 'Accept-Ranges' => 'bytes']);
        }

        $status = 200;
        $offset = 0;
        $length = $size;

        if ($range !== null) {
            [$offset, $end] = $range;
            $length = $end - $offset + 1;
            $status = 206;

            $headers['Content-Range']  = "bytes $offset-$end/$size";
            $headers['Content-Length'] = (string) $length;
        }

        if ($method === 'HEAD') throw new ResponseSignal($status, $headers);

        # Compression, only for a whole small-ish text payload. Compressing a
        # range would invalidate the byte offsets the client asked for, and
        # compressing an already-compressed image just spends CPU.
        if ($range === null && ($body = self::compress($path, $mime, $size, $headers)) !== null) {
            self::$sent = strlen($body);
            throw new ResponseSignal($status, $headers, $body);
        }

        # Hand the transfer to the web server when it is configured to take it:
        # PHP is then free after the headers instead of during the whole
        # download, which is the difference between 50 concurrent videos and 50
        # occupied workers.
        if (($offloaded = self::offload($options, $headers, $range === null)) !== null) throw $offloaded;

        self::$sent = $length;

        # A long-running worker has no socket of its own to write to; the body
        # goes back through the response object.
        if (PHP_SAPI === 'cli') {
            $body = self::readRange($path, $offset, $length);
            throw new ResponseSignal($status, $headers, $body);
        }

        self::stream($path, $offset, $length, $status, $headers);
    }

    /**
     * Send bytes already in memory - a pulled origin response, a generated
     * placeholder - with the same caching semantics.
     *
     * @param string $body
     * @param array  $options
     * @return never
     */
    public static function sendBody(string $body, array $options): never
    {
        $size = strlen($body);
        $etag = (string) ($options['etag'] ?? '"' . md5($body) . '"');

        $headers = self::headers($options, $size, (string) ($options['mime'] ?? 'application/octet-stream'), $etag, (int) ($options['modified'] ?? time()));

        if (self::notModified($etag, (int) ($options['modified'] ?? time()))) {
            unset($headers['Content-Length'], $headers['Content-Type']);
            throw new ResponseSignal(304, $headers);
        }

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') throw new ResponseSignal(200, $headers);

        self::$sent = $size;
        throw new ResponseSignal(200, $headers, $body);
    }

    /**
     * The response headers for a delivered asset.
     *
     * @param array  $options
     * @param int    $size
     * @param string $mime
     * @param string $etag
     * @param int    $modified
     * @return array
     */
    private static function headers(array $options, int $size, string $mime, string $etag, int $modified): array
    {
        $ttl       = (int) ($options['ttl'] ?? Support::config('delivery.default-ttl', 31536000));
        $immutable = (bool) ($options['immutable'] ?? Support::config('delivery.immutable', false));

        # An html or svg served from the asset host would run in the asset
        # host's origin, so those types are pushed to a download whatever the
        # caller asked for.
        $disposition = (string) ($options['disposition'] ?? 'inline');
        if (in_array(strtolower(strtok($mime, ';')), (array) Support::config('security.force-download', []), true)) $disposition = 'attachment';

        $cacheControl = $ttl > 0 ? "public, max-age=$ttl" : 'no-store, no-cache, must-revalidate, max-age=0';

        if ($ttl > 0) {
            if ($immutable) $cacheControl .= ', immutable';

            # Lets a shared cache keep answering while it refreshes in the
            # background, so an expiry does not become a thundering herd.
            $swr = (int) Support::config('delivery.swr', 0);
            if ($swr > 0) $cacheControl .= ", stale-while-revalidate=$swr";
        }

        $headers = [
            'Content-Type'   => self::withCharset($mime),
            'Content-Length' => (string) $size,
            'Cache-Control'  => $cacheControl,
            'Accept-Ranges'  => ($options['ranges'] ?? Support::config('delivery.ranges', true)) ? 'bytes' : 'none',
            'Last-Modified'  => gmdate('D, d M Y H:i:s', $modified) . ' GMT',
            'Date'           => gmdate('D, d M Y H:i:s') . ' GMT',
        ];

        if ($etag !== '') $headers['ETag'] = $etag;
        if ($ttl > 0)     $headers['Expires'] = gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT';

        if (!empty($options['filename'])) {
            $name = str_replace(['"', "\r", "\n"], '', (string) $options['filename']);
            # Both forms: the plain one for old clients, the RFC 5987 one so a
            # non-ascii name survives.
            $headers['Content-Disposition'] = $disposition . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode((string) $options['filename']);
        } elseif ($disposition === 'attachment') {
            $headers['Content-Disposition'] = 'attachment';
        }

        foreach ((array) Support::config('security.headers', []) as $name => $value) $headers[$name] = $value;

        if ($origin = self::cors($options['bucket'] ?? null)) $headers += $origin;

        if ($timing = Support::config('delivery.timing-allow-origin')) $headers['Timing-Allow-Origin'] = (string) $timing;

        $vary = (array) ($options['vary'] ?? []);
        if (count($vary)) $headers['Vary'] = implode(', ', array_unique($vary));

        if (!empty($options['cache']))   $headers['X-Cdn-Cache']   = (string) $options['cache'];
        if (!empty($options['variant'])) $headers['X-Cdn-Variant'] = substr((string) $options['variant'], 0, 16);
        if (!empty($options['bucket']['slug'])) $headers['X-Cdn-Bucket'] = (string) $options['bucket']['slug'];

        foreach ((array) ($options['headers'] ?? []) as $name => $value) $headers[$name] = (string) $value;

        return $headers;
    }

    /**
     * CORS headers for a bucket.
     *
     * A font or a canvas-read image is unusable cross-origin without them, so
     * the default is permissive; a bucket that lists origins gets an exact
     * echo of the matching one plus `Vary: Origin`, which is the only correct
     * way to answer a list.
     *
     * @param array|null $bucket
     * @return array
     */
    private static function cors(?array $bucket): array
    {
        $config  = (array) Support::config('delivery.cors', []);
        $origins = Support::json($bucket['cors'] ?? null);
        if (!count($origins)) $origins = (array) ($config['origins'] ?? ['*']);

        $request = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

        $headers = [
            'Access-Control-Allow-Methods' => (string) ($config['methods'] ?? 'GET, HEAD, OPTIONS'),
            'Access-Control-Allow-Headers' => (string) ($config['headers'] ?? 'Range, Content-Type'),
            'Access-Control-Expose-Headers' => (string) ($config['expose'] ?? 'Content-Length, Content-Range, ETag'),
            'Access-Control-Max-Age'       => (string) ($config['max-age'] ?? 86400),
        ];

        if (in_array('*', $origins, true)) return $headers + ['Access-Control-Allow-Origin' => '*'];

        if ($request === '') return [];

        $host = strtolower((string) parse_url($request, PHP_URL_HOST));
        if (!Support::hostMatches($host, $origins)) return [];

        return $headers + ['Access-Control-Allow-Origin' => $request, 'Vary' => 'Origin'];
    }

    /**
     * Does the client already have this?
     *
     * If-None-Match wins over If-Modified-Since when both are sent, per RFC
     * 9110 - the tag is exact, the date is a second-resolution approximation.
     *
     * @param string $etag
     * @param int    $modified
     * @return bool
     */
    private static function notModified(string $etag, int $modified): bool
    {
        $noneMatch = Support::header('If-None-Match');

        if ($noneMatch !== null && $etag !== '') {
            if (trim($noneMatch) === '*') return true;

            foreach (explode(',', $noneMatch) as $candidate) {
                $candidate = trim($candidate);
                # A cache that recompressed the body marks its tag weak; it is
                # still the same entity.
                if (ltrim($candidate, 'W/') === ltrim($etag, 'W/')) return true;
            }

            return false;
        }

        $since = Support::header('If-Modified-Since');
        if ($since === null) return false;

        $time = strtotime($since);
        return $time !== false && $modified <= $time;
    }

    /**
     * Parse a Range header.
     *
     * @param int $size
     * @return array|null|false  [start, end], null for no range, false for unsatisfiable.
     */
    private static function range(int $size): array|null|false
    {
        $header = Support::header('Range');
        if ($header === null || $size <= 0) return null;
        if (!preg_match('/^bytes=(.+)$/i', trim($header), $matches)) return null;

        # Several ranges in one request: answered with the whole file rather
        # than a multipart body.
        if (strstr($matches[1], ',')) return null;

        # An If-Range that no longer matches means the client's copy changed
        # under it; the correct answer is the whole file, not a patch onto
        # something else.
        if (($ifRange = Support::header('If-Range')) !== null) {
            $etag = $_SERVER['HTTP_IF_RANGE'] ?? '';
            if (strstr($ifRange, '"') === false && strtotime($ifRange) === false) return null;
            unset($etag);
        }

        [$start, $end] = array_pad(explode('-', trim($matches[1]), 2), 2, '');

        if ($start === '' && $end === '') return null;

        if ($start === '') {
            # bytes=-500: the last 500 bytes.
            $length = (int) $end;
            if ($length <= 0) return false;
            $start = max(0, $size - $length);
            $end   = $size - 1;
        } else {
            $start = (int) $start;
            $end   = $end === '' ? $size - 1 : (int) $end;
        }

        if ($start > $end || $start >= $size) return false;

        return [$start, min($end, $size - 1)];
    }

    /**
     * gzip a whole small text payload, when the client asked for it.
     *
     * Returns the compressed body and adjusts the headers, or null to leave the
     * response alone.
     *
     * @param string $path
     * @param string $mime
     * @param int    $size
     * @param array  $headers
     * @return string|null
     */
    private static function compress(string $path, string $mime, int $size, array &$headers): ?string
    {
        $config = (array) Support::config('delivery.compress', []);
        if (!($config['enabled'] ?? false) || !function_exists('gzencode')) return null;

        if ($size < (int) ($config['min-size'] ?? 1024)) return null;

        # Above this the memory cost of holding both copies outweighs the
        # transfer saved, and a big text asset should be pre-compressed anyway.
        if ($size > 4 * 1024 * 1024) return null;

        $mime  = strtolower(strtok($mime, ';'));
        $match = false;
        foreach ((array) ($config['types'] ?? []) as $candidate) {
            if (str_starts_with($mime, strtolower((string) $candidate))) {
                $match = true;
                break;
            }
        }
        if (!$match) return null;

        $accept = strtolower((string) (Support::header('Accept-Encoding') ?? ''));
        if (!strstr($accept, 'gzip')) return null;

        $body = @file_get_contents($path);
        if ($body === false) return null;

        $compressed = @gzencode($body, (int) ($config['level'] ?? 5));
        if ($compressed === false || strlen($compressed) >= $size) return null;

        $headers['Content-Encoding'] = 'gzip';
        $headers['Content-Length']   = (string) strlen($compressed);
        $headers['Vary']             = trim(($headers['Vary'] ?? '') . ', Accept-Encoding', ', ');

        # The body is no longer byte-identical to the stored file, so the tag
        # must differ from the identity one or a cache will mix them up.
        if (isset($headers['ETag'])) $headers['ETag'] = 'W/' . $headers['ETag'];

        return $compressed;
    }

    /**
     * Hand the transfer to the web server, if it is set up for it.
     *
     * Deliberately skipped for ranged responses: the server does its own range
     * handling from the file, so PHP must not also send Content-Range.
     *
     * @param array $options
     * @param array $headers
     * @param bool  $whole
     * @return ResponseSignal|null
     */
    private static function offload(array $options, array $headers, bool $whole): ?ResponseSignal
    {
        $mode = Support::config('delivery.offload', false);
        if (!$mode || PHP_SAPI === 'cli') return null;

        # A ranged request is the server's to answer from the file; PHP sending
        # its own Content-Range alongside would describe a body it is not
        # producing.
        if (!$whole) return null;

        $path = (string) $options['path'];

        if ($mode === 'x-sendfile') {
            unset($headers['Content-Length']);
            return new ResponseSignal(200, $headers + ['X-Sendfile' => $path]);
        }

        if ($mode === 'x-accel-redirect') {
            $root     = rtrim(str_replace('\\', '/', Storage::root($options['disk'] ?? null)), '/');
            $internal = rtrim((string) Support::config('delivery.x-accel', '/__cdn_objects'), '/');
            $relative = str_replace($root, '', str_replace('\\', '/', $path));

            unset($headers['Content-Length']);
            return new ResponseSignal(200, $headers + ['X-Accel-Redirect' => $internal . $relative]);
        }

        return null;
    }

    /**
     * Read a byte range into memory. Only used where the body must be returned
     * rather than written.
     *
     * @param string $path
     * @param int    $offset
     * @param int    $length
     * @return string
     */
    private static function readRange(string $path, int $offset, int $length): string
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) return '';

        if ($offset > 0) fseek($handle, $offset);
        $body = (string) stream_get_contents($handle, $length);
        fclose($handle);

        return $body;
    }

    /**
     * Write the file to the client in chunks.
     *
     * Every buffer above this is discarded first - the framework opens one per
     * request, and leaving it in place would collect the entire file in memory
     * before a single byte left, which is the failure this method exists to
     * avoid.
     *
     * @param string $path
     * @param int    $offset
     * @param int    $length
     * @param int    $status
     * @param array  $headers
     * @return never
     */
    private static function stream(string $path, int $offset, int $length, int $status, array $headers): never
    {
        while (ob_get_level() > 0) ob_end_clean();

        # zlib would compress the stream and then the Content-Length above would
        # be a lie the client counts against.
        @ini_set('zlib.output_compression', 'Off');

        Response::status($status);

        # index.php sends a no-store Cache-Control and a Pragma before anything
        # else runs, which is right for a page and wrong for an asset. The first
        # is replaced by name; Pragma has to be removed, since it has no
        # replacement value that means "cacheable".
        if (!headers_sent()) @header_remove('Pragma');
        foreach ($headers as $name => $value) Response::header($name, (string) $value);

        $handle = @fopen($path, 'rb');
        if (!$handle) throw new ResponseSignal(500);

        if ($offset > 0) fseek($handle, $offset);

        $chunk     = max(8192, (int) Support::config('delivery.chunk', 262144));
        $remaining = $length;

        while ($remaining > 0 && !feof($handle)) {
            $read = fread($handle, (int) min($chunk, $remaining));
            if ($read === false || $read === '') break;

            echo $read;
            $remaining -= strlen($read);

            # Stop reading a file nobody is receiving any more - a cancelled
            # video seek would otherwise pump the whole thing into a dead socket.
            if (connection_aborted()) break;

            flush();
        }

        fclose($handle);

        self::$sent = $length - max(0, $remaining);

        # The response is complete. Empty signal: everything was written above.
        throw new ResponseSignal();
    }
    /**
     * A text type, with the encoding said out loud.
     *
     * A javascript file served as `application/javascript` with no charset is a
     * file the browser decodes with a guess - the document's encoding, or the
     * locale's, or windows-1252. Every non-ascii character in it then arrives
     * as mojibake, and the file is byte-for-byte correct on disk the whole
     * time, which is what makes it hard to see.
     *
     * Only for the types where an encoding means anything. An image with a
     * charset is nonsense, and a charset on `application/octet-stream` is a
     * claim about bytes nobody has looked at.
     *
     * @param string $mime
     * @return string
     */
    private static function withCharset(string $mime): string
    {
        # Already said, by a stored value or by a bucket.
        if (str_contains(strtolower($mime), 'charset=')) return $mime;

        $charset = (string) Support::config('delivery.charset', 'utf-8');
        if ($charset === '') return $mime;

        $type    = strtolower(trim(strtok($mime, ';')));
        $textual = [
            'application/javascript', 'application/x-javascript', 'text/javascript',
            'application/json', 'application/ld+json', 'application/xml',
            'image/svg+xml', 'application/manifest+json',
        ];

        if (!str_starts_with($type, 'text/') && !in_array($type, $textual, true)) return $mime;

        return $mime . '; charset=' . $charset;
    }

}
