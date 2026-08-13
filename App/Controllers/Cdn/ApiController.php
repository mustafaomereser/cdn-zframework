<?php

namespace App\Controllers\Cdn;

use App\Cdn\Credentials;
use App\Cdn\Metrics;
use App\Cdn\Purger;
use App\Cdn\Registry;
use App\Cdn\Signature;
use App\Cdn\Storage;
use App\Cdn\Support;
use App\Cdn\Uploader;
use App\Models\Cdn\Buckets;
use App\Models\Cdn\Files;
use App\Models\Cdn\Projects;
use zFramework\Core\Facades\Response;
use zFramework\Core\Helpers\File;

/**
 * The management API.
 *
 * Everything an integration needs and the panel does not do for you: upload,
 * list, delete, purge, sign, and read usage. Authentication is the ApiKey
 * middleware; each method names the scope it needs, so a key issued for uploads
 * cannot delete a bucket even by calling the right URL.
 */
class ApiController
{
    /**
     * A json body, whichever way the client sent it.
     *
     * A json content type is parsed from the raw input; anything else is
     * already in $_REQUEST. Handled here rather than per method, or half the
     * endpoints would quietly only work with form encoding.
     *
     * @return array
     */
    private function input(): array
    {
        $type = (string) (Support::header('Content-Type') ?? '');

        if (stripos($type, 'application/json') !== false) {
            $decoded = json_decode((string) @file_get_contents('php://input'), true);
            if (is_array($decoded)) return $decoded + (array) $_REQUEST;
        }

        return (array) $_REQUEST;
    }

    /**
     * @param array $data
     * @param int   $status
     * @return string
     */
    private function json(array $data, int $status = 200): string
    {
        Response::status($status);
        return Response::json($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Resolve a bucket the caller is allowed to touch.
     *
     * @param string|null $slug
     * @return array|null
     */
    private function bucket(?string $slug): ?array
    {
        if (!$slug) return null;

        # Scoped to the key`s project: a bucket slug is unique inside a project,
        # not across the installation, so a bare slug means nothing on its own.
        $bucket = (new Buckets)
            ->where("project_id", Credentials::projectId())
            ->where("slug", (string) $slug)
            ->closureMode(false)
            ->first();

        if (!$bucket || !Credentials::allows($bucket)) return null;

        return $bucket;
    }

    /**
     * A bucket of the key`s project, by id.
     *
     * @param int $id
     * @return array|null
     */
    private function bucketById(int $id): ?array
    {
        $bucket = (new Buckets)
            ->where("project_id", Credentials::projectId())
            ->where("id", $id)
            ->closureMode(false)
            ->first();

        return $bucket && Credentials::allows($bucket) ? $bucket : null;
    }

    /**
     * What this key can see.
     *
     * @return mixed
     */
    public function index(): mixed
    {
        $project = (new Projects)->closureMode(false)->find((string) Credentials::projectId());

        return $this->json([
            'ok'      => true,
            'project' => $project ? [
                'name'    => $project['name'],
                'slug'    => $project['slug'],
                'storage' => [
                    'used'  => (int) $project['storage_used'],
                    'quota' => (int) $project['storage_quota'],
                    'human' => File::humanFileSize((int) $project['storage_used']),
                ],
                'bandwidth' => [
                    'used'   => (int) $project['bandwidth_used'],
                    'quota'  => (int) $project['bandwidth_quota'],
                    'period' => $project['bandwidth_period'],
                    'human'  => File::humanFileSize((int) $project['bandwidth_used']),
                ],
            ] : null,
            'scopes'  => Support::json(Credentials::key()['scopes'] ?? null) ?: ['read'],
            'limits'  => [
                'upload-max' => (int) Support::config('upload.max-size', 0),
                'chunk-size' => (int) Support::config('upload.chunk-size', 0),
            ],
        ]);
    }

    /**
     * @return mixed
     */
    public function buckets(): mixed
    {
        Credentials::require('read');

        $rows = (new Buckets)->where('project_id', Credentials::projectId())->closureMode(false)->get();

        $buckets = [];
        foreach ($rows as $row) {
            if (!Credentials::allows($row)) continue;

            $buckets[] = [
                'slug'       => $row['slug'],
                'name'       => $row['name'],
                'visibility' => $row['visibility'],
                'files'      => (int) $row['files_count'],
                'storage'    => (int) $row['storage_used'],
                'cache_ttl'  => (int) $row['cache_ttl'],
                'transform'  => (bool) $row['transform'],
                'origin'     => $row['origin_url'],
            ];
        }

        return $this->json(['ok' => true, 'buckets' => $buckets]);
    }

    /**
     * List files, newest first.
     *
     * @return mixed
     */
    public function files(): mixed
    {
        Credentials::require('read');

        $bucket = $this->bucket(request('bucket') ?: null);
        if (!$bucket) return $this->json(['ok' => false, 'error' => 'unknown-bucket'], 404);

        $query = (new Files)->where('bucket_id', $bucket['id'])->closureMode(false);

        if ($prefix = request('prefix')) $query->where('path', 'LIKE', ltrim((string) $prefix, '/') . '%');
        if ($tag = request('tag'))       $query->whereRaw("JSON_SEARCH(tags, 'one', :tag) IS NOT NULL", ['tag' => $tag]);

        $result = $query->orderBy(['id' => 'DESC'])->paginate((int) (request('per_page') ?: 50));

        return $this->json([
            'ok'    => true,
            'files' => array_map(fn($file) => $this->present($file, $bucket), $result['items']),
            'page'  => [
                'current' => $result['current_page'],
                'pages'   => $result['page_count'],
                'total'   => $result['item_count'],
            ],
        ]);
    }

    /**
     * One file.
     *
     * @param string $id
     * @return mixed
     */
    public function show(string $id): mixed
    {
        Credentials::require('read');

        $file   = (new Files)->closureMode(false)->find($id);
        $bucket = $file ? $this->bucketById((int) $file['bucket_id']) : null;

        if (!$file || !$bucket) return $this->json(['ok' => false, 'error' => 'not-found'], 404);

        return $this->json(['ok' => true, 'file' => $this->present($file, $bucket)]);
    }

    /**
     * Upload: a multipart file, or a url for the server to fetch.
     *
     * @return mixed
     */
    public function upload(): mixed
    {
        Credentials::require('upload');

        $input  = $this->input();
        $bucket = $this->bucket($input['bucket'] ?? null);

        if (!$bucket) return $this->json(['ok' => false, 'error' => 'unknown-bucket'], 404);

        $limit = \App\Cdn\RateLimiter::check('upload', 'key:' . Credentials::key()['access_key']);
        if ($limit !== null && !$limit['allowed']) return $this->json(['ok' => false, 'error' => 'rate-limited'], 429);

        $meta = [
            'path'        => $input['path'] ?? null,
            'name'        => $input['name'] ?? null,
            'visibility'  => $input['visibility'] ?? 'inherit',
            'tags'        => isset($input['tags']) ? (is_array($input['tags']) ? $input['tags'] : explode(',', (string) $input['tags'])) : null,
            'overwrite'   => !isset($input['overwrite']) || (bool) $input['overwrite'],
            'checksum'    => $input['checksum'] ?? null,
            'uploaded_by' => Credentials::label(),
        ];

        # A path was given but no name: the last segment is the name, so a
        # client that only ever sets `path` still gets sensible metadata.
        if (!empty($meta['path']) && empty($meta['name'])) $meta['name'] = basename((string) $meta['path']);

        $results = [];

        if (!empty($_FILES['file']['name'])) {
            # One input, several files: the browser flattens them into parallel
            # arrays, so they are unpicked back into entries here.
            $entries = is_array($_FILES['file']['name'])
                ? array_map(fn($index) => array_combine(array_keys($_FILES['file']), array_column($_FILES['file'], $index)), array_keys($_FILES['file']['name']))
                : [$_FILES['file']];

            foreach ($entries as $entry) {
                # With several files, one explicit path cannot serve them all.
                $single = count($entries) > 1 ? ['path' => null, 'name' => null] + $meta : $meta;
                $results[] = Uploader::fromRequest($bucket, $entry, $single);
            }
        } elseif (!empty($input['url'])) {
            $results[] = Uploader::fromUrl($bucket, (string) $input['url'], $meta);
        } else {
            return $this->json(['ok' => false, 'error' => 'no-file'], 400);
        }

        Registry::forgetBucket($bucket);

        $files  = [];
        $errors = [];

        foreach ($results as $result) {
            if ($result['ok']) $files[] = $this->present($result['file'], $bucket);
            else $errors[] = ['error' => $result['error'], 'message' => $result['message'] ?? null];
        }

        Metrics::record([
            'project_id' => $bucket['project_id'],
            'bucket_id'  => $bucket['id'],
            'path'       => 'api:upload',
            'method'     => 'POST',
            'status'     => count($files) ? 201 : 422,
            'cache'      => 'bypass',
            'ip'         => ip(),
            'api_key_id' => Credentials::key()['id'] ?? null,
        ]);

        if (!count($files)) return $this->json(['ok' => false, 'errors' => $errors], 422);

        return $this->json(['ok' => true, 'files' => $files, 'errors' => $errors], 201);
    }

    /**
     * Open a resumable upload.
     *
     * @return mixed
     */
    public function uploadBegin(): mixed
    {
        Credentials::require('upload');

        $input  = $this->input();
        $bucket = $this->bucket($input['bucket'] ?? null);

        if (!$bucket) return $this->json(['ok' => false, 'error' => 'unknown-bucket'], 404);

        $result = Uploader::begin($bucket, [
            'path'       => $input['path'] ?? null,
            'name'       => $input['name'] ?? null,
            'size'       => (int) ($input['size'] ?? 0),
            'mime'       => $input['mime'] ?? null,
            'chunk_size' => isset($input['chunk_size']) ? (int) $input['chunk_size'] : null,
            'checksum'   => $input['checksum'] ?? null,
            'api_key_id' => Credentials::key()['id'] ?? null,
        ]);

        return $this->json($result, $result['ok'] ? 201 : 422);
    }

    /**
     * Receive one chunk.
     *
     * The body is the bytes, raw. A multipart wrapper would mean the whole
     * chunk goes through PHP's form parser and a temporary file first, for no
     * benefit - the index is in the URL and the length is in the body.
     *
     * @param string $upload
     * @return mixed
     */
    public function uploadChunk(string $upload): mixed
    {
        Credentials::require('upload');

        $index = (int) (request('index') ?: Support::header('X-Cdn-Chunk-Index') ?: 0);
        $bytes = (string) @file_get_contents('php://input');

        if ($bytes === '') return $this->json(['ok' => false, 'error' => 'empty-chunk'], 400);

        $result = Uploader::chunk($upload, $index, $bytes);

        return $this->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Assemble a finished session.
     *
     * @param string $upload
     * @return mixed
     */
    public function uploadComplete(string $upload): mixed
    {
        Credentials::require('upload');

        $result = Uploader::complete($upload, ['uploaded_by' => Credentials::label()]);

        if (!$result['ok']) return $this->json($result, 422);

        $bucket = $this->bucketById((int) $result['file']['bucket_id']);
        Registry::forgetBucket($bucket);

        return $this->json(['ok' => true, 'file' => $this->present($result['file'], $bucket ?: [])], 201);
    }

    /**
     * @param string $upload
     * @return mixed
     */
    public function uploadAbort(string $upload): mixed
    {
        Credentials::require('upload');

        return $this->json(['ok' => Uploader::abort($upload)]);
    }

    /**
     * Delete by id, or by bucket and path.
     *
     * @param string|null $id
     * @return mixed
     */
    public function delete(?string $id = null): mixed
    {
        Credentials::require('delete');

        $input = $this->input();

        if ($id !== null) {
            $file = (new Files)->closureMode(false)->find($id);
        } else {
            $bucket = $this->bucket($input['bucket'] ?? null);
            if (!$bucket) return $this->json(['ok' => false, 'error' => 'unknown-bucket'], 404);

            $path = Support::normalizePath((string) ($input['path'] ?? ''));
            if ($path === false) return $this->json(['ok' => false, 'error' => 'invalid-path'], 400);

            $file = (new Files)->where('bucket_id', $bucket['id'])->where('path', $path)->closureMode(false)->first();
        }

        if (!$file) return $this->json(['ok' => false, 'error' => 'not-found'], 404);

        $bucket = $this->bucketById((int) $file['bucket_id']);
        if (!$bucket) return $this->json(['ok' => false, 'error' => 'forbidden'], 403);

        # A suspended project does not change. Uploads are refused inside
        # Uploader; this is the other half.
        if ($reason = \App\Cdn\Guard::frozen($bucket)) return $this->json(['ok' => false, 'error' => $reason], 403);

        Uploader::delete($file);
        Registry::forgetBucket($bucket);

        return $this->json(['ok' => true, 'deleted' => $file['path']]);
    }

    /**
     * Invalidate derivatives.
     *
     * @return mixed
     */
    public function purge(): mixed
    {
        Credentials::require('purge');

        $input  = $this->input();
        $bucket = $this->bucket($input['bucket'] ?? null);

        if (!$bucket) return $this->json(['ok' => false, 'error' => 'unknown-bucket'], 404);

        $type   = strtolower((string) ($input['type'] ?? 'bucket'));
        $target = (string) ($input['target'] ?? '');
        $by     = Credentials::label();

        $result = match ($type) {
            'path'   => Purger::path($bucket, $target, $by),
            'prefix' => Purger::prefix($bucket, $target, $by),
            'tag'    => Purger::tag($bucket, $target, $by),
            'bucket' => Purger::bucket($bucket, $by),
            default  => ['ok' => false, 'error' => 'unknown-type'],
        };

        return $this->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /**
     * Build a signed URL without shipping the signing key to the client.
     *
     * @return mixed
     */
    public function sign(): mixed
    {
        Credentials::require('read');

        $input  = $this->input();
        $bucket = $this->bucket($input['bucket'] ?? null);

        if (!$bucket) return $this->json(['ok' => false, 'error' => 'unknown-bucket'], 404);

        $path = Support::normalizePath((string) ($input['path'] ?? ''));
        if ($path === false) return $this->json(['ok' => false, 'error' => 'invalid-path'], 400);

        $ttl   = (int) ($input['ttl'] ?? Support::config('signing.ttl', 3600));
        $query = (array) ($input['query'] ?? []);

        $url = Signature::url($this->projectSlug(), $bucket['slug'], $path, [
            'bucket'  => $bucket,
            'ttl'     => $ttl,
            'query'   => $query,
            'ip'      => $input['ip'] ?? null,
        ]);

        return $this->json(['ok' => true, 'url' => $url, 'expires_at' => date('c', time() + $ttl)]);
    }

    /**
     * Traffic and storage.
     *
     * @return mixed
     */
    public function stats(): mixed
    {
        Credentials::require('read');

        $bucket = request('bucket') ? $this->bucket(request('bucket')) : null;
        $days   = max(1, min(365, (int) (request('days') ?: 30)));

        return $this->json([
            'ok'     => true,
            'series' => Metrics::series($bucket['id'] ?? null, $days),
            'disk'   => [
                'free' => Storage::freeSpace(),
            ],
        ]);
    }


    /**
     * A file as the API describes it.
     *
     * @param array $file
     * @param array $bucket
     * @return array
     */
    private function present(array $file, array $bucket): array
    {
        $prefix = rtrim((string) Support::config('delivery.url-prefix', '/cdn'), '/');
        $url    = (function_exists('host') ? host() : '') . $prefix . '/' . $this->projectSlug() . '/' . ($bucket['slug'] ?? '') . '/' . $file['path'];

        return [
            'id'         => (int) $file['id'],
            'bucket'     => $bucket['slug'] ?? null,
            'path'       => $file['path'],
            'name'       => $file['name'],
            'mime'       => $file['mime'],
            'size'       => (int) $file['size'],
            'size_human' => File::humanFileSize((int) $file['size']),
            'hash'       => $file['hash'],
            'etag'       => $file['etag'],
            'width'      => $file['width'] !== null ? (int) $file['width'] : null,
            'height'     => $file['height'] !== null ? (int) $file['height'] : null,
            'visibility' => $file['visibility'],
            'tags'       => Support::json($file['tags'] ?? null),
            'downloads'  => (int) $file['downloads'],
            'created_at' => $file['created_at'],

            # A signed bucket has no usable plain url, so what is returned is one
            # that works - otherwise every caller writes the same signing code.
            'url'        => ($bucket['visibility'] ?? 'public') === 'public'
                ? $url
                : Signature::url($this->projectSlug(), (string) ($bucket['slug'] ?? ''), $file['path'], ['bucket' => $bucket]),
        ];
    }

    /**
     * The slug of the key's project - the first segment of every URL it can
     * produce. Resolved once per request.
     *
     * @return string
     */
    private function projectSlug(): string
    {
        static $slug = null;

        if ($slug === null) {
            $project = (new Projects)->closureMode(false)->find((string) Credentials::projectId());
            $slug    = (string) ($project['slug'] ?? '');
        }

        return $slug;
    }
}
