<?php

namespace App\Controllers\Cdn;

use App\Cdn\Delivery;
use App\Cdn\Guard;
use App\Cdn\Metrics;
use App\Cdn\Origin;
use App\Cdn\Registry;
use App\Cdn\Signature;
use App\Cdn\Storage;
use App\Cdn\Support;
use App\Cdn\Transform;
use App\Models\Cdn\Files;
use zFramework\Core\ResponseSignal;

/**
 * The public delivery endpoint: GET /cdn/<bucket>/<path>.
 *
 * This runs on every asset request, so the shape of it matters more than
 * anywhere else in the application. In order: resolve the bucket from cache,
 * refuse what can be refused without touching the disk, find the file, produce
 * a derivative if one was asked for, and hand the whole thing to Delivery.
 *
 * Nothing here writes to the database. Counters and the access log are
 * registered as deferred work and run after the bytes are gone.
 */
class DeliveryController
{
    /**
     * Route parameters arrive as separate segments - the router matches segment
     * by segment, so a catch-all is spelled out as a fixed number of optional
     * ones. `delivery.depth` in config and the route definition have to agree.
     *
     * @return mixed
     */
    public function serve(
        string $project,
        string $bucket,
        string $p1,
        ?string $p2 = null,
        ?string $p3 = null,
        ?string $p4 = null,
        ?string $p5 = null,
        ?string $p6 = null,
        ?string $p7 = null,
        ?string $p8 = null
    ): mixed {
        $started = hrtime(true);

        # A preflight is answered before anything is resolved. It carries no
        # signature - the browser does not attach one - so running it through
        # the guard would refuse the check that decides whether the real,
        # properly signed request is even allowed to be made.
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') return $this->options();

        $segments = array_filter([$p1, $p2, $p3, $p4, $p5, $p6, $p7, $p8], fn($segment) => $segment !== null && $segment !== '');
        $path     = Support::normalizePath(implode('/', array_map('rawurldecode', $segments)));

        if ($path === false) return $this->refuse(400, 'bad-path', null, null, (string) $bucket, $started);

        $bucketRow = Registry::bucket($project, $bucket);
        if (!$bucketRow) return $this->refuse(404, 'unknown-bucket', null, null, $path, $started);

        $projectRow = Registry::project((int) $bucketRow['project_id']);

        # What the signature covers: the project, the bucket and the path,
        # exactly as they appear in the URL.
        $signedPath = strtolower(trim($project, '/') . '/' . trim($bucket, '/')) . '/' . $path;

        if ($refusal = Guard::delivery($bucketRow, $projectRow, $signedPath)) {
            if (isset($refusal['retry-after'])) \zFramework\Core\Facades\Response::header('Retry-After', (string) $refusal['retry-after']);
            return $this->refuse($refusal['status'], $refusal['reason'], $bucketRow, null, $path, $started);
        }

        $file  = (new Files)
            ->where('bucket_id', $bucketRow['id'])
            ->where('path', $path)
            ->closureMode(false)
            ->first();

        $cache = 'hit';

        # Origin pull: a bucket mirroring somewhere else answers a miss by
        # fetching, and the file exists from then on.
        if (!$file && !empty($bucketRow['origin_url'])) {
            $file  = Origin::pull($bucketRow, $path);
            $cache = 'pulled';
        }

        if (!$file || ($file['status'] ?? 'ready') !== 'ready') return $this->refuse(404, 'not-found', $bucketRow, null, $path, $started);

        if (Origin::stale($file, $bucketRow)) {
            $file  = Origin::refresh($file, $bucketRow);
            $cache = 'pulled';
        }

        # A file may be more restricted than its bucket - one private object in
        # an otherwise public bucket.
        if (($file['visibility'] ?? 'inherit') !== 'inherit' && $file['visibility'] !== 'public') {
            if ($file['visibility'] === 'private') return $this->refuse(404, 'file-private', $bucketRow, $file, $path, $started);
            if (Signature::verify($signedPath, $_GET, $bucketRow) !== true) return $this->refuse(403, 'file-signature', $bucketRow, $file, $path, $started);
        }

        $ttl = (int) ($bucketRow['cache_ttl'] ?? Support::config('delivery.default-ttl', 31536000));

        $options = [
            'bucket'      => $bucketRow,
            'disk'        => $file['disk'],
            'ttl'         => $ttl,
            'immutable'   => (bool) ($bucketRow['immutable'] ?? false),
            'disposition' => request('download') !== false ? 'attachment' : 'inline',
            'filename'    => $file['name'],
        ];

        # A derivative, when one was asked for and can be produced. A failure
        # here is not an error: the original is a correct answer to "give me
        # this image, smaller", just a larger one.
        $params = Transform::parse($_GET, $file, $bucketRow);

        if ($params !== null && ($variant = Transform::resolve($file, $bucketRow, $params))) {
            $row = $variant['row'];

            Metrics::variantHit((int) $row['id']);

            $this->log($bucketRow, $file, $path, 200, $variant['built'] ? 'transformed' : 'hit', $started, (string) $row['signature']);

            Delivery::send($options + [
                'path'     => Storage::variantAbsolute($row['storage_path']),
                'mime'     => $row['mime'] ?: Transform::mimeOf((string) $row['format']),
                'size'     => (int) $row['size'],
                'etag'     => $row['etag'] ?: '"' . substr($row['signature'], 0, 32) . '"',
                'modified' => strtotime($row['created_at'] ?? 'now'),
                'cache'    => $variant['built'] ? 'MISS' : 'HIT',
                'variant'  => $row['signature'],

                # The chosen format depends on Accept when auto-format picked it,
                # so any cache in front has to key on that header too.
                'vary'     => isset($_GET['format']) ? [] : ['Accept'],
            ]);
        }

        $absolute = Storage::absolute($file['storage_path'], $file['disk']);

        if (!is_file($absolute)) return $this->refuse(410, 'object-missing', $bucketRow, $file, $path, $started);

        $this->log($bucketRow, $file, $path, 200, $cache, $started);

        Delivery::send($options + [
            'path'     => $absolute,
            'mime'     => $file['mime'],
            'size'     => (int) $file['size'],
            'etag'     => $file['etag'],
            'modified' => strtotime($file['updated_at'] ?: $file['created_at'] ?: 'now'),
            'cache'    => $cache === 'pulled' ? 'MISS' : 'HIT',
        ]);
    }

    /**
     * A CORS preflight, answered without looking anything up.
     *
     * @return mixed
     */
    public function options(): mixed
    {
        $config = (array) Support::config('delivery.cors', []);

        throw new ResponseSignal(204, [
            'Access-Control-Allow-Origin'  => (string) ($_SERVER['HTTP_ORIGIN'] ?? '*'),
            'Access-Control-Allow-Methods' => (string) ($config['methods'] ?? 'GET, HEAD, OPTIONS'),
            'Access-Control-Allow-Headers' => (string) ($config['headers'] ?? 'Range, Content-Type'),
            'Access-Control-Max-Age'       => (string) ($config['max-age'] ?? 86400),
            'Vary'                         => 'Origin',
        ]);
    }

    /**
     * Liveness, for a load balancer.
     *
     * Deliberately shallow: it says this process can serve, not that every
     * dependency is healthy. A health check that touches the database takes the
     * whole pool out of rotation when the database hiccups.
     *
     * @return mixed
     */
    public function health(): mixed
    {
        throw new ResponseSignal(200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], json_encode([
            'status' => 'ok',
            'time'   => time(),
            'driver' => \App\Cdn\Transform::driver(),
        ]));
    }

    /**
     * Refuse a request, with the reason recorded.
     *
     * @param int         $status
     * @param string      $reason
     * @param array|null  $bucket
     * @param array|null  $file
     * @param string      $path
     * @param float|int   $started
     * @return never
     */
    private function refuse(int $status, string $reason, ?array $bucket, ?array $file, string $path, float|int $started): never
    {
        $this->log($bucket, $file, $path, $status, 'denied', $started, null, $reason);

        # No body worth sending, and a stock error page on an asset host is a
        # surprisingly large response to send a few million times.
        throw new ResponseSignal($status, [
            'Cache-Control' => 'no-store',
            'Content-Type'  => 'text/plain; charset=utf-8',
            'X-Cdn-Reason'  => $reason,
        ], match ($status) {
            403 => "Forbidden\n",
            404 => "Not Found\n",
            410 => "Gone\n",
            429 => "Too Many Requests\n",
            509 => "Bandwidth Limit Exceeded\n",
            default => "Error $status\n",
        });
    }

    /**
     * Register the access log entry and the counters.
     *
     * Evaluated after the response - see Metrics::deferred - because the byte
     * count is not known until the body has been written.
     *
     * @param array|null  $bucket
     * @param array|null  $file
     * @param string      $path
     * @param int         $status
     * @param string      $cache
     * @param float|int   $started
     * @param string|null $variant
     * @param string|null $reason
     * @return void
     */
    private function log(?array $bucket, ?array $file, string $path, int $status, string $cache, float|int $started, ?string $variant = null, ?string $reason = null): void
    {
        $entry = [
            'project_id' => $bucket['project_id'] ?? null,
            'bucket_id'  => $bucket['id'] ?? null,
            'file_id'    => $file['id'] ?? null,
            'path'       => $reason ? "$path ($reason)" : $path,
            'method'     => strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'status'     => $status,
            'cache'      => $cache,
            'variant'    => $variant,
            'ip'         => function_exists('ip') ? ip() : null,
            'referer'    => $_SERVER['HTTP_REFERER'] ?? null,
            'agent'      => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];

        $counters = $status === 200 ? [
            'project_id' => $bucket['project_id'] ?? 0,
            'bucket_id'  => $bucket['id'] ?? 0,
            'file_id'    => $file['id'] ?? 0,
        ] : [];

        Metrics::deferred(function () use ($entry, $counters, $started) {
            $sent = Delivery::$sent;

            $entry['bytes']    = $sent;
            $entry['duration'] = (int) round((hrtime(true) - $started) / 1e6);

            return [$entry, count($counters) ? $counters + ['bytes' => $sent] : []];
        });
    }
}
