<?php

namespace App\Cdn;

/**
 * Shared plumbing: config access, path hygiene, mime resolution.
 *
 * Everything here is called on the hot delivery path, so it does no I/O it can
 * avoid and memoises what it reads.
 */
class Support
{
    /**
     * A config/cdn.php value by dot path, with a default.
     *
     * Config::get('cdn') does the reading and the caching; the walk is here
     * because Config::get('cdn.a.missing') returns the parent array rather than
     * null when a key is absent, so `?? $default` would quietly hand back an
     * array instead of the default. Asking for the whole file once and walking
     * it makes a missing key missing.
     *
     * @param string|null $key
     * @param mixed       $default
     * @return mixed
     */
    public static function config(?string $key = null, mixed $default = null): mixed
    {
        $config = (array) \zFramework\Core\Facades\Config::get('cdn');

        if ($key === null) return $config;

        $value = $config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) return $default;
            $value = $value[$segment];
        }

        return $value ?? $default;
    }

    /**
     * Make a client-supplied object path safe to store and compare.
     *
     * Traversal is not "cleaned up" into something else - a path containing ..
     * is refused. Rewriting it would mean two different requests silently
     * addressing the same object, which is how a signature ends up covering a
     * path that is not the one served.
     *
     * @param string $path
     * @return string|false
     */
    public static function normalizePath(string $path): string|false
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        while (strstr($path, '//')) $path = str_replace('//', '/', $path);

        if ($path === '' || strlen($path) > 255) return false;
        if (strstr($path, "\0") || strstr($path, '..')) return false;

        # Windows reserves these whatever the extension is; a file named con.txt
        # cannot be created and the failure surfaces far from here.
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') return false;
            if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i', $segment)) return false;
        }

        return $path;
    }

    /**
     * Turn a user-supplied name into a storable one, keeping it recognisable.
     *
     * Str::slug() does the transliteration - it already knows what to do with
     * Turkish characters. The extension is separated first, because a slug of
     * the whole name would turn the dot into a divider and lose it.
     *
     * @param string $name
     * @return string
     */
    public static function slugName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base      = trim(\zFramework\Core\Facades\Str::slug(pathinfo($name, PATHINFO_FILENAME)), '-');

        if ($base === '') $base = 'file';
        if (strlen($base) > 120) $base = rtrim(substr($base, 0, 120), '-');

        return $extension ? "$base." . preg_replace('/[^a-z0-9]/', '', $extension) : $base;
    }

    /**
     * Extension → mime. Deliberately a table rather than finfo: the delivery
     * path already knows the stored mime, and this is for the cases where it
     * does not - a pulled origin response with no content type, mostly.
     */
    private const MIMES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jpe' => 'image/jpeg',
        'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'avif' => 'image/avif', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon',
        'svg' => 'image/svg+xml', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
        'heic' => 'image/heic', 'heif' => 'image/heif',

        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm',
        'ogv' => 'video/ogg', 'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska', 'ts' => 'video/mp2t', 'm3u8' => 'application/vnd.apple.mpegurl',
        'mpd' => 'application/dash+xml',

        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg', 'm4a' => 'audio/mp4', 'flac' => 'audio/flac',
        'aac' => 'audio/aac', 'opus' => 'audio/opus',

        'css' => 'text/css', 'js' => 'application/javascript', 'mjs' => 'application/javascript',
        'json' => 'application/json', 'map' => 'application/json',
        'xml' => 'application/xml', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'md' => 'text/markdown', 'html' => 'text/html', 'htm' => 'text/html',
        'wasm' => 'application/wasm',

        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
        'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject',

        'pdf' => 'application/pdf', 'zip' => 'application/zip', 'gz' => 'application/gzip',
        'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed',
        'tar' => 'application/x-tar', 'br' => 'application/brotli',

        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /**
     * @param string $path
     * @param string $fallback
     * @return string
     */
    public static function mime(string $path, string $fallback = 'application/octet-stream'): string
    {
        return self::MIMES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? $fallback;
    }

    /**
     * The extension a mime type is normally written with.
     *
     * @param string $mime
     * @return string|null
     */
    public static function extensionFor(string $mime): ?string
    {
        $mime = strtolower(strtok($mime, ';'));
        foreach (self::MIMES as $extension => $type) if ($type === $mime) return $extension;
        return null;
    }

    /**
     * @param string $path
     * @return string
     */
    public static function extension(string $path): string
    {
        return strtolower(pathinfo(strtok($path, '?'), PATHINFO_EXTENSION));
    }

    /**
     * @param string|null $mime
     * @return bool
     */
    public static function isImage(?string $mime): bool
    {
        return $mime !== null && str_starts_with(strtolower($mime), 'image/');
    }

    /**
     * Images GD and Imagick can actually open. svg and ico are images, but not
     * ones a resampler has anything to say about.
     *
     * @param string|null $mime
     * @return bool
     */
    public static function isTransformable(?string $mime): bool
    {
        return in_array(strtolower((string) $mime), [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp',
        ], true);
    }

    /**
     * A json column, as an array whatever the driver handed back.
     *
     * @param mixed $value
     * @return array
     */
    public static function json(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || $value === '') return [];

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A random, url-safe identifier.
     *
     * random_bytes rather than the framework's Str::rand(): these name objects
     * and sign upload sessions, where guessability is the whole question.
     *
     * @param int $bytes
     * @return string
     */
    public static function token(int $bytes = 20): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * The request's Accept header, split into types.
     *
     * @return array
     */
    public static function accepts(): array
    {
        $header = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (!strlen($header)) return [];

        $types = [];
        foreach (explode(',', $header) as $part) $types[] = strtolower(trim(strtok($part, ';')));

        return array_filter($types);
    }

    /**
     * Request header by name, case insensitive, without getallheaders().
     *
     * getallheaders() does not exist under every SAPI, and building the whole
     * array to read one header is wasteful on a path that reads three.
     *
     * @param string $name
     * @return string|null
     */
    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (isset($_SERVER[$key])) return (string) $_SERVER[$key];
        if ($name === 'Content-Type' && isset($_SERVER['CONTENT_TYPE'])) return (string) $_SERVER['CONTENT_TYPE'];
        if ($name === 'Content-Length' && isset($_SERVER['CONTENT_LENGTH'])) return (string) $_SERVER['CONTENT_LENGTH'];

        return null;
    }

    /**
     * Does a host match one of the suffixes in a list?
     *
     * Suffix rather than substring: 'example.com' must not match
     * 'example.com.attacker.net'.
     *
     * @param string $host
     * @param array  $list
     * @return bool
     */
    public static function hostMatches(string $host, array $list): bool
    {
        $host = strtolower(rtrim($host, '.'));

        foreach ($list as $candidate) {
            $candidate = strtolower(ltrim(trim((string) $candidate), '*.'));
            if ($candidate === '') continue;
            if ($host === $candidate || str_ends_with($host, ".$candidate")) return true;
        }

        return false;
    }

    /**
     * Is this address inside a range the server should never be talked into
     * contacting? Loopback, link-local, private and the reserved blocks.
     *
     * @param string $ip
     * @return bool
     */
    public static function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        return true;
    }
}
