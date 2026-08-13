<?php

/**
 * CDN configuration.
 *
 * Everything the delivery path reads on a hot request is here, so a change is a
 * file edit rather than a deploy. Per-bucket columns override most of it - this
 * file is the default a bucket inherits when it says nothing.
 */
return [

    /**
     * Object storage.
     *
     * Objects are content addressed: the sha256 of the bytes decides the path,
     * so the same file uploaded twice is stored once and a stored object is
     * never mutated. That is what makes `immutable` cache headers honest.
     *
     * root lives outside public_html on purpose - every byte leaves through the
     * delivery route, which is where signing, hotlink rules and accounting are.
     */
    'storage' => [
        'disk'  => 'local',

        'disks' => [
            'local' => [
                'driver' => 'local',
                'root'   => BASE_PATH . '/storage/cdn/objects',
            ],

            # A second local disk is the simplest way to spread objects over more
            # than one volume. Point a bucket at it with cdn_buckets.disk.
            // 'cold' => ['driver' => 'local', 'root' => 'D:/cdn-cold/objects'],
        ],

        # Derivatives (resized / reformatted images). Regenerable, so this is a
        # cache directory: safe to delete, expensive to lose all at once.
        'variants' => BASE_PATH . '/storage/cdn/variants',

        # Partially received uploads.
        'temp'     => BASE_PATH . '/storage/cdn/temp',

        # Objects are stored as <root>/ab/cd/<hash>. Two levels of 256 keeps any
        # single directory small enough for a filesystem to list quickly.
        'fanout'   => 2,
    ],

    /**
     * Delivery: what the public URL does.
     *
     * url-prefix   Where the delivery route is mounted.
     * depth        How many path segments after the bucket are matched. A file
     *              stored deeper than this is unreachable by URL.
     * default-ttl  max-age when the bucket does not set one.
     * immutable    Add `immutable` to Cache-Control. Correct for content
     *              addressed URLs; wrong for a path you intend to overwrite.
     * offload      false | 'x-sendfile' | 'x-accel-redirect'. Hands the file to
     *              the web server so PHP is free during the transfer. Requires
     *              mod_xsendfile (Apache) or an internal location (nginx) -
     *              until it is configured, leave it false or every response is
     *              an empty 200.
     */
    'delivery' => [
        'url-prefix'  => '/cdn',
        'depth'       => 8,
        'default-ttl' => 31536000,
        'immutable'   => false,
        'swr'         => 86400,   # stale-while-revalidate seconds, 0 to omit
        'chunk'       => 262144,  # read size while streaming, bytes

        'offload'     => false,
        'x-accel'     => '/__cdn_objects',   # internal nginx location mapped to storage root

        # Ranged requests. Video seeking and resumable downloads need this; there
        # is no reason to turn it off other than debugging.
        'ranges'      => true,

        # On-the-fly compression for text-ish payloads that are not already
        # compressed. Images and video are skipped whatever this says.
        'compress'    => [
            'enabled'  => true,
            'min-size' => 1024,
            'level'    => 5,
            'types'    => [
                'text/', 'application/json', 'application/javascript', 'application/xml',
                'image/svg+xml', 'application/wasm', 'font/ttf', 'font/otf',
            ],
        ],

        # Sent with every asset so a browser's Resource Timing API can read the
        # real transfer numbers cross-origin. '*' or null.
        'timing-allow-origin' => '*',

        # Answered with 204 and the CORS headers before the browser sends a
        # cross-origin GET with custom headers.
        'cors' => [
            'origins' => ['*'],
            'methods' => 'GET, HEAD, OPTIONS',
            'headers' => 'Range, Content-Type, If-None-Match, If-Modified-Since',
            'expose'  => 'Content-Length, Content-Range, ETag, X-Cdn-Cache, X-Cdn-Variant',
            'max-age' => 86400,
        ],
    ],

    /**
     * Signed URLs.
     *
     * A signed bucket serves nothing without a valid signature, so a leaked URL
     * stops working when it expires. The signature covers the path, the expiry,
     * the transform parameters and - when bound - the client IP.
     *
     * key   null falls back to config/crypt.php. Set it to rotate signing
     *       without invalidating cookies and tokens elsewhere in the app.
     */
    'signing' => [
        'key'        => null,
        'algo'       => 'sha256',
        'ttl'        => 3600,
        'bind-ip'    => false,

        # Query parameter names. Short ones keep URLs readable; they are excluded
        # from the signed payload by name, so renaming them breaks old links.
        'params'     => [
            'expires'   => 'exp',
            'signature' => 'sig',
            'ip'        => 'sip',
        ],

        # Seconds of clock skew tolerated on the expiry check.
        'leeway'     => 30,
    ],

    /**
     * Image transformation.
     *
     * Derivatives are cached by a hash of (file, parameters), so the work
     * happens once per distinct URL. The guards below exist because the
     * parameters come from the URL: without them a visitor can ask for a
     * 20000x20000 resample and spend the machine's memory doing it.
     */
    'transform' => [
        'enabled'      => true,
        'driver'       => 'auto',      # auto | imagick | gd
        'max-width'    => 5000,
        'max-height'   => 5000,
        'max-pixels'   => 25000000,    # source dimensions, before decoding
        'quality'      => 82,
        'formats'      => ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'],

        # Pick webp/avif from the Accept header when the URL says nothing. The
        # response then carries `Vary: Accept`, which any cache in front must
        # honour or one visitor's avif reaches a browser that cannot read it.
        'auto-format'  => true,

        # Only serve transforms whose URL is signed. Turn on if the origin is
        # public: it stops a stranger from filling the variant cache by walking
        # ?w=1..5000.
        'signed-only'  => false,

        # Named shortcuts: /cdn/bucket/photo.jpg?p=thumb
        'presets'      => [
            'thumb'  => ['w' => 160,  'h' => 160,  'fit' => 'cover', 'q' => 78],
            'small'  => ['w' => 480,               'fit' => 'contain'],
            'medium' => ['w' => 1024,              'fit' => 'contain'],
            'large'  => ['w' => 1920,              'fit' => 'contain'],
            'og'     => ['w' => 1200, 'h' => 630,  'fit' => 'cover', 'format' => 'jpg'],
        ],

        # Refuse anything not listed. Empty means any combination is allowed.
        'allowed-presets-only' => false,

        'cache' => [
            'enabled'  => true,
            'max-size' => 5 * 1024 * 1024 * 1024,   # evicted LRU by `cdn gc`
            'ttl'      => 2592000,                   # unused derivative lifetime
        ],
    ],

    /**
     * Uploads.
     *
     * blocked-ext wins over allowed-ext. It is a denylist of things that
     * execute on a misconfigured server - the point is that an upload directory
     * is never also an execution directory, and this is the second lock.
     */
    'upload' => [
        'max-size'    => 512 * 1024 * 1024,
        'chunk-size'  => 8 * 1024 * 1024,
        'session-ttl' => 86400,

        'allowed-ext' => [],   # empty = anything not blocked
        'blocked-ext' => [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'inc',
            'exe', 'dll', 'so', 'bat', 'cmd', 'com', 'scr', 'msi', 'jar',
            'sh', 'bash', 'ps1', 'psm1', 'vbs', 'js.map.php', 'htaccess', 'htpasswd',
        ],

        # Verify the declared type against what the bytes say. A mismatch is
        # rejected rather than corrected - a png that sniffs as html is not a
        # naming mistake.
        'verify-mime'  => true,

        # SVG is a script container. Stripped of <script>, event handlers and
        # external references on the way in; the alternative is serving it as an
        # attachment, which defeats the point of an image.
        'sanitize-svg' => true,

        # Store the same bytes once, however many buckets reference them.
        'deduplicate'  => true,

        # Fetch-by-URL: the server makes the request, so it must not be talked
        # into fetching from inside the network.
        'remote' => [
            'enabled'      => true,
            'timeout'      => 20,
            'max-size'     => 128 * 1024 * 1024,
            'max-redirect' => 3,
            'schemes'      => ['https'],
            'block-private' => true,
            'allow-hosts'  => [],   # suffix allowlist, empty = any public host
        ],
    ],

    /**
     * Origin pull.
     *
     * A bucket carrying origin_url serves from cache and fetches on a miss, the
     * way an edge does. What is fetched is stored as a normal object, so the
     * second request is a local read.
     */
    'origin' => [
        'enabled'      => true,
        'timeout'      => 15,
        'max-size'     => 256 * 1024 * 1024,
        'negative-ttl' => 60,    # remember a 404 this long instead of refetching
        'stale-on-error' => true,
    ],

    /**
     * Rate limiting.
     *
     * A token bucket in GlobalCache (Redis when configured, APCu otherwise).
     * Without either it degrades to no limiting rather than to an error - the
     * limiter is a guard, not a gate the CDN depends on.
     */
    'limits' => [
        'enabled' => true,

        'ip'  => ['requests' => 1200, 'window' => 60],
        'key' => ['requests' => 6000, 'window' => 60],

        # Applied to writes only; uploads are far more expensive than reads.
        'upload' => ['requests' => 120, 'window' => 60],

        # Sent with a 429 so a client knows when to come back.
        'retry-after' => true,
    ],

    /**
     * Access rules evaluated before anything is read from disk.
     */
    'security' => [
        # Hotlink protection default for buckets that define no policy of their
        # own. mode: off | allow | deny. An empty Referer is treated by
        # allow-empty - direct navigation and most privacy settings send none.
        'referers' => [
            'mode'        => 'off',
            'list'        => [],
            'allow-empty' => true,
        ],

        'deny-ip'  => [],
        'allow-ip' => [],   # non-empty means everything else is denied

        # Substring match against User-Agent, case insensitive.
        'block-agents' => [],

        # Response headers on every delivered asset.
        'headers' => [
            'X-Content-Type-Options' => 'nosniff',

            # An html or svg served from the asset host runs in the asset host's
            # origin. This keeps it from reaching anything.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
        ],

        # Types forced to download rather than render, whatever the bucket says.
        'force-download' => ['text/html', 'application/xhtml+xml', 'text/xml'],
    ],

    /**
     * Access logging and statistics.
     *
     * driver  defer | queue | sync | off. `defer` writes after the response is
     *         sent, which keeps the log off the visitor's clock; `queue` needs
     *         redis and a worker but survives the process dying.
     * sample  Fraction of requests written. Rollups are scaled by it, so a
     *         sampled log still reports the real totals - approximately.
     */
    'logging' => [
        'enabled'    => true,
        'driver'     => 'defer',
        'sample'     => 1,
        'keep-days'  => 30,
        'store-ua'   => true,
        'store-referer' => true,

        # Counters that must be exact - bandwidth billing, storage quota - are
        # not sampled. They are accumulated per request and flushed here.
        'counters'   => true,
    ],

    /**
     * Lookup caching.
     *
     * registry-ttl  Seconds a bucket or project row may be served from
     *               GlobalCache. Every write path invalidates its own key, so
     *               this is only the window for a change made outside the
     *               application - a row edited directly in the database.
     */
    'cache' => [
        'registry-ttl' => 300,
    ],

    /**
     * Administration.
     */
    'admin' => [
        'enabled' => true,
        'route'   => '/cdn-admin',

        # Users allowed in. Empty means any authenticated user, which is only
        # reasonable while the application has no other users.
        'emails'  => [],
    ],

    /**
     * Management API.
     */
    'api' => [
        'route' => '/api/cdn',

        # Signed request mode: the client signs (method, path, body hash,
        # timestamp) with the key secret instead of sending it. Slower to
        # implement on the client, immune to a leaked log line.
        'hmac'  => ['enabled' => true, 'window' => 300],
    ],

    /**
     * Webhooks fired after upload, delete and purge.
     */
    'webhooks' => [
        'enabled' => true,
        'timeout' => 8,
        'retries' => 3,
    ],

    /**
     * Housekeeping, run by `php cdn gc` from cron.
     */
    'gc' => [
        'orphan-objects'   => true,   # stored bytes no row references
        'expired-uploads'  => true,
        'variant-eviction' => true,
        'log-pruning'      => true,
        'stat-rollup'      => true,
    ],
];
