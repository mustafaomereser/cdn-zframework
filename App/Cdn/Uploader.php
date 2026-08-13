<?php

namespace App\Cdn;

use App\Models\Cdn\Buckets;
use App\Models\Cdn\Files;
use App\Models\Cdn\Objects;
use App\Models\Cdn\Projects;
use App\Models\Cdn\Uploads;
use App\Models\Cdn\Variants;
use zFramework\Core\Facades\DB;

/**
 * Getting bytes into the store, whichever door they came through: a multipart
 * POST, a URL the server fetches, or a resumable session in chunks.
 *
 * They all end in the same place - store() - so validation, deduplication,
 * quota and accounting exist once. A second path that skipped one of them
 * would be the hole.
 */
class Uploader
{
    /**
     * Accept a file that is already on local disk and register it in a bucket.
     *
     * @param array  $bucket
     * @param string $source Absolute path; moved, not copied.
     * @param array  $meta   path, name, mime, uploaded_by, tags, visibility, overwrite
     * @return array{ok:bool,file?:array,error?:string,message?:string}
     */
    public static function store(array $bucket, string $source, array $meta = []): array
    {
        if (!is_file($source)) return ['ok' => false, 'error' => 'source-missing'];

        $size = (int) filesize($source);
        $name = Support::slugName((string) ($meta['name'] ?? basename($source)));

        # Where it will live in the bucket. Given explicitly, or derived from the
        # name under a date prefix - which keeps a bucket that receives a few
        # thousand files a day navigable.
        $path = (string) ($meta['path'] ?? date('Y/m/') . $name);
        $path = Support::normalizePath($path);

        if ($path === false) {
            @unlink($source);
            return ['ok' => false, 'error' => 'invalid-path'];
        }

        $extension = Support::extension($path) ?: Support::extension($name);
        $mime      = (string) ($meta['mime'] ?? '');

        if (Support::config('upload.verify-mime', true)) {
            $sniffed = Fetcher::sniff($source);

            # The declared type is what the client says; the sniffed type is what
            # the bytes are. Where they disagree the bytes win, and where the
            # extension disagrees with both, that is the interesting case - a
            # .png that is really html.
            if ($sniffed !== null) $mime = $sniffed;
        }

        if ($mime === '') $mime = Support::mime($path);

        # finfo cannot tell a stylesheet from a script from a shopping list -
        # all three are text/plain - and a stylesheet served as text/plain is a
        # stylesheet the browser refuses to apply. Where the bytes are plain
        # text and the extension names something more specific, the extension
        # wins.
        #
        # This does not reopen what sniffing is for. The case that matters is a
        # .png whose bytes are html, and those bytes sniff as text/html, not as
        # text/plain - so they never reach this. Nothing here can promote a file
        # to a type a browser will execute in this origin.
        $textual = ['css', 'js', 'mjs', 'json', 'xml', 'csv', 'md', 'txt'];

        if (in_array($mime, ['text/plain', 'application/octet-stream'], true) && in_array($extension, $textual, true)) {
            $mime = Support::mime($path, $mime);
        }

        if ($reason = Guard::acceptable($bucket, $mime, $extension)) {
            @unlink($source);
            return ['ok' => false, 'error' => $reason];
        }

        $maxSize = (int) ($bucket['max_file_size'] ?: Support::config('upload.max-size', 0));
        if ($maxSize > 0 && $size > $maxSize) {
            @unlink($source);
            return ['ok' => false, 'error' => 'too-large', 'message' => 'Maximum is ' . \zFramework\Core\Helpers\File::humanFileSize($maxSize) . '.'];
        }

        $project = (new Projects)->closureMode(false)->find((string) $bucket['project_id']);

        if ($project && (int) $project['storage_quota'] > 0 && ((int) $project['storage_used'] + $size) > (int) $project['storage_quota']) {
            @unlink($source);
            return ['ok' => false, 'error' => 'storage-quota-exceeded'];
        }

        # SVG is a document that can run script. Cleaned before it is hashed, so
        # what is stored is what was checked - sanitising on the way out would
        # mean the dangerous version is the one on disk.
        if ($mime === 'image/svg+xml' && Support::config('upload.sanitize-svg', true)) {
            $clean = self::sanitizeSvg((string) file_get_contents($source));
            file_put_contents($source, $clean);
            $size = strlen($clean);
        }

        $hash = Storage::hashFile($source);
        if ($hash === false) {
            @unlink($source);
            return ['ok' => false, 'error' => 'hash-failed'];
        }

        if (!empty($meta['checksum']) && !hash_equals(strtolower((string) $meta['checksum']), $hash)) {
            @unlink($source);
            return ['ok' => false, 'error' => 'checksum-mismatch'];
        }

        $disk        = (string) ($bucket['disk'] ?? 'local');
        $objectPath  = Storage::objectPath($hash);
        $objectModel = new Objects;

        # Deduplication: the same bytes are stored once however many names point
        # at them. `refs` is what lets a delete know whether the bytes are still
        # wanted by somebody else.
        $object = $objectModel->where('hash', $hash)->closureMode(false)->first();
        $stored = $object && Storage::exists($object['storage_path'], $object['disk']);

        if ($stored || !Support::config('upload.deduplicate', true)) {
            if (!$stored && !Storage::place($source, $objectPath, $disk)) return ['ok' => false, 'error' => 'write-failed'];
            @unlink($source);
        } elseif (!Storage::place($source, $objectPath, $disk)) {
            return ['ok' => false, 'error' => 'write-failed'];
        }

        $dimensions = self::dimensions(Storage::absolute($objectPath, $disk), $mime);

        $existing = (new Files)->where('bucket_id', $bucket['id'])->where('path', $path)->closureMode(false)->first();

        if ($existing && !($meta['overwrite'] ?? true)) return ['ok' => false, 'error' => 'already-exists'];

        $columns = [
            'project_id'   => $bucket['project_id'],
            'bucket_id'    => $bucket['id'],
            'path'         => $path,
            'name'         => $name,
            'ext'          => $extension,
            'mime'         => $mime,
            'size'         => $size,
            'hash'         => $hash,
            'disk'         => $disk,
            'storage_path' => $objectPath,
            'visibility'   => (string) ($meta['visibility'] ?? 'inherit'),
            'width'        => $dimensions['width'],
            'height'       => $dimensions['height'],

            # Strong, and derived from the content: two uploads of the same bytes
            # produce the same tag, so a client that already has one is not made
            # to download it again under a different name.
            'etag'         => '"' . substr($hash, 0, 32) . '"',
            'status'       => 'ready',
            'uploaded_by'  => $meta['uploaded_by'] ?? null,
            'tags'         => isset($meta['tags']) ? json_encode(array_values((array) $meta['tags'])) : null,
            'meta'         => isset($meta['meta']) ? json_encode((array) $meta['meta']) : null,
        ];

        if (!empty($meta['origin_ttl'])) {
            $columns['origin_fetched_at'] = date('Y-m-d H:i:s');
            $columns['origin_expires_at'] = date('Y-m-d H:i:s', time() + (int) $meta['origin_ttl']);
        }

        $files = new Files;

        if ($existing) {
            # Overwriting a path: the old bytes lose a reference and the old
            # derivatives no longer describe what is there.
            if ($existing['hash'] !== $hash) {
                self::dereference($existing['hash']);
                Purger::variantsOf((int) $existing['id']);
            }

            $files->where('id', $existing['id'])->update($columns);
            $file = $files->closureMode(false)->find((string) $existing['id']);

            self::reference($hash, $disk, $objectPath, $size, $mime, $existing['hash'] === $hash ? 0 : 1);
            self::account($bucket, $size - (int) $existing['size'], 0);
        } else {
            $file = $files->insert($columns);
            self::reference($hash, $disk, $objectPath, $size, $mime, 1);
            self::account($bucket, $size, 1);
        }

        Webhook::fire($bucket, $existing ? 'file.updated' : 'file.uploaded', [
            'bucket' => $bucket['slug'],
            'path'   => $path,
            'size'   => $size,
            'mime'   => $mime,
            'hash'   => $hash,
        ]);

        return ['ok' => true, 'file' => $file];
    }

    /**
     * Store an entry from $_FILES.
     *
     * @param array $bucket
     * @param array $file  One $_FILES entry
     * @param array $meta
     * @return array
     */
    public static function fromRequest(array $bucket, array $file, array $meta = []): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'upload-error-' . ($file['error'] ?? 'unknown')];
        if (!is_uploaded_file($file['tmp_name'] ?? '')) return ['ok' => false, 'error' => 'not-an-upload'];

        # move_uploaded_file first: store() moves and unlinks, and doing that to
        # a php temporary directly would bypass the SAPI's own bookkeeping.
        $temporary = Storage::tempRoot() . '/post-' . Support::token(12) . '.part';
        if (!move_uploaded_file($file['tmp_name'], $temporary)) return ['ok' => false, 'error' => 'move-failed'];

        return self::store($bucket, $temporary, ['name' => $file['name'] ?? null, 'mime' => $file['type'] ?? null] + $meta);
    }

    /**
     * Fetch a url and store what comes back.
     *
     * @param array  $bucket
     * @param string $url
     * @param array  $meta
     * @return array
     */
    public static function fromUrl(array $bucket, string $url, array $meta = []): array
    {
        $config = (array) Support::config('upload.remote', []);
        if (!($config['enabled'] ?? false)) return ['ok' => false, 'error' => 'remote-disabled'];

        $result = Fetcher::get($url, $config);
        if (!$result['ok']) return ['ok' => false, 'error' => $result['error'] ?? 'fetch-failed'];

        $name = $meta['name'] ?? basename((string) parse_url($url, PHP_URL_PATH));
        if ($name === '' || $name === '/') $name = 'download';

        # A url with no extension still has a type, and the stored name should
        # carry it or every later consumer has to sniff again.
        if (Support::extension($name) === '' && ($extension = Support::extensionFor($result['mime']))) $name .= ".$extension";

        return self::store($bucket, $result['path'], ['name' => $name, 'mime' => $result['mime']] + $meta);
    }

    /**
     * Open a resumable upload session.
     *
     * @param array $bucket
     * @param array $meta path, name, size, mime, chunk_size, checksum, api_key_id
     * @return array
     */
    public static function begin(array $bucket, array $meta): array
    {
        $size = (int) ($meta['size'] ?? 0);
        if ($size <= 0) return ['ok' => false, 'error' => 'size-required'];

        $maxSize = (int) ($bucket['max_file_size'] ?: Support::config('upload.max-size', 0));
        if ($maxSize > 0 && $size > $maxSize) return ['ok' => false, 'error' => 'too-large'];

        $name = Support::slugName((string) ($meta['name'] ?? 'upload'));
        $path = Support::normalizePath((string) ($meta['path'] ?? date('Y/m/') . $name));
        if ($path === false) return ['ok' => false, 'error' => 'invalid-path'];

        # Refused now rather than after the client has spent an hour uploading.
        if ($reason = Guard::acceptable($bucket, (string) ($meta['mime'] ?? Support::mime($path)), Support::extension($path))) return ['ok' => false, 'error' => $reason];

        $uploadId = Support::token(20);
        $chunk    = (int) ($meta['chunk_size'] ?? Support::config('upload.chunk-size', 8 * 1024 * 1024));

        $row = (new Uploads)->insert([
            'upload_id'  => $uploadId,
            'project_id' => $bucket['project_id'],
            'bucket_id'  => $bucket['id'],
            'api_key_id' => $meta['api_key_id'] ?? null,
            'path'       => $path,
            'name'       => $name,
            'mime'       => $meta['mime'] ?? Support::mime($path),
            'size'       => $size,
            'chunk_size' => $chunk,
            'chunks'     => json_encode([]),
            'temp_path'  => Storage::tempPath($uploadId),
            'checksum'   => $meta['checksum'] ?? null,
            'status'     => 'pending',
            'expires_at' => date('Y-m-d H:i:s', time() + (int) Support::config('upload.session-ttl', 86400)),
        ]);

        # Allocated up front so the chunks can be written at their offsets in any
        # order, and so a disk that cannot hold the file says so now.
        $handle = @fopen($row['temp_path'], 'wb');
        if ($handle) {
            @ftruncate($handle, $size);
            fclose($handle);
        }

        return ['ok' => true, 'upload' => ['id' => $uploadId, 'chunk_size' => $chunk, 'size' => $size, 'expires_at' => $row['expires_at']]];
    }

    /**
     * Write one chunk of a session.
     *
     * @param string $uploadId
     * @param int    $index
     * @param string $bytes
     * @return array
     */
    public static function chunk(string $uploadId, int $index, string $bytes): array
    {
        $model  = new Uploads;
        $upload = $model->where('upload_id', $uploadId)->closureMode(false)->first();

        if (!$upload) return ['ok' => false, 'error' => 'unknown-upload'];
        if ($upload['status'] === 'completed') return ['ok' => false, 'error' => 'already-completed'];
        if (strtotime($upload['expires_at']) < time()) return ['ok' => false, 'error' => 'expired'];

        $chunkSize = (int) $upload['chunk_size'];
        $offset    = $index * $chunkSize;

        if ($index < 0 || $offset >= (int) $upload['size'] + $chunkSize) return ['ok' => false, 'error' => 'bad-index'];
        if (strlen($bytes) > $chunkSize) return ['ok' => false, 'error' => 'chunk-too-large'];

        $handle = @fopen($upload['temp_path'], 'c+b');
        if (!$handle) return ['ok' => false, 'error' => 'temp-missing'];

        # An exclusive lock, because a client that parallelises its chunks has
        # several requests writing into the same file at once.
        flock($handle, LOCK_EX);
        fseek($handle, $offset);
        fwrite($handle, $bytes);
        flock($handle, LOCK_UN);
        fclose($handle);

        $chunks = Support::json($upload['chunks']);
        $known  = isset($chunks[(string) $index]);
        $chunks[(string) $index] = strlen($bytes);

        # Re-sent chunks are counted once - a resume after a dropped connection
        # usually repeats the chunk that was in flight.
        $received = (int) $upload['received'] + ($known ? 0 : strlen($bytes));

        $model->where('id', $upload['id'])->update([
            'chunks'   => json_encode($chunks),
            'received' => $received,
            'status'   => 'uploading',
        ]);

        return ['ok' => true, 'received' => $received, 'size' => (int) $upload['size'], 'complete' => $received >= (int) $upload['size']];
    }

    /**
     * Finish a session: the assembled file becomes an object.
     *
     * @param string $uploadId
     * @param array  $meta
     * @return array
     */
    public static function complete(string $uploadId, array $meta = []): array
    {
        $model  = new Uploads;
        $upload = $model->where('upload_id', $uploadId)->closureMode(false)->first();

        if (!$upload) return ['ok' => false, 'error' => 'unknown-upload'];
        if (!is_file($upload['temp_path'])) return ['ok' => false, 'error' => 'temp-missing'];

        # The file was allocated at full size when the session opened, so its
        # length says nothing about how much of it arrived - a session where
        # every chunk failed is still exactly the right number of bytes, all of
        # them zero. What arrived is the chunk bookkeeping, and that is what
        # decides.
        $received = (int) $upload['received'];
        $expected = (int) $upload['size'];

        if ($received < $expected) return ['ok' => false, 'error' => 'incomplete', 'message' => "$received of $expected bytes received"];

        # Then the length, as a second opinion: a truncated temp file means
        # something went wrong outside the chunk path.
        $actual = (int) filesize($upload['temp_path']);
        if ($actual !== $expected) return ['ok' => false, 'error' => 'size-mismatch', 'message' => "$actual on disk, $expected expected"];

        # Every chunk index between 0 and the last must be present. Byte totals
        # alone would accept a client that sent chunk 3 twice and never sent 5.
        $chunks = Support::json($upload['chunks']);
        $needed = (int) ceil($expected / max(1, (int) $upload['chunk_size']));

        for ($index = 0; $index < $needed; $index++) {
            if (!isset($chunks[(string) $index])) return ['ok' => false, 'error' => 'missing-chunk', 'message' => "chunk $index never arrived"];
        }

        $bucket = (new Buckets)->closureMode(false)->find((string) $upload['bucket_id']);
        if (!$bucket) return ['ok' => false, 'error' => 'bucket-missing'];

        $result = self::store($bucket, $upload['temp_path'], [
            'path'     => $upload['path'],
            'name'     => $upload['name'],
            'mime'     => $upload['mime'],
            'checksum' => $upload['checksum'] ?: null,
        ] + $meta);

        $model->where('id', $upload['id'])->update(['status' => $result['ok'] ? 'completed' : 'aborted']);

        return $result;
    }

    /**
     * Abandon a session and its partial file.
     *
     * @param string $uploadId
     * @return bool
     */
    public static function abort(string $uploadId): bool
    {
        $model  = new Uploads;
        $upload = $model->where('upload_id', $uploadId)->closureMode(false)->first();
        if (!$upload) return false;

        @unlink($upload['temp_path']);
        $model->where('id', $upload['id'])->update(['status' => 'aborted']);

        return true;
    }

    /**
     * Remove a file from a bucket.
     *
     * The row goes and the bytes do not: another name may point at the same
     * object. What actually happens is a decrement, and the collector deals
     * with whatever reaches zero.
     *
     * @param array $file
     * @return bool
     */
    public static function delete(array $file): bool
    {
        $files = new Files;

        Purger::variantsOf((int) $file['id']);
        $files->where('id', $file['id'])->delete();

        self::dereference($file['hash']);

        $bucket = (new Buckets)->closureMode(false)->find((string) $file['bucket_id']);
        if ($bucket) {
            self::account($bucket, -(int) $file['size'], -1);
            Webhook::fire($bucket, 'file.deleted', ['bucket' => $bucket['slug'], 'path' => $file['path'], 'size' => (int) $file['size']]);
        }

        return true;
    }

    /**
     * Register or increment an object's reference count.
     *
     * @param string $hash
     * @param string $disk
     * @param string $path
     * @param int    $size
     * @param string $mime
     * @param int    $delta
     * @return void
     */
    private static function reference(string $hash, string $disk, string $path, int $size, string $mime, int $delta = 1): void
    {
        $model  = new Objects;
        $object = $model->where('hash', $hash)->closureMode(false)->first();

        if ($object) {
            if ($delta !== 0) (new DB)->prepare("UPDATE cdn_objects SET refs = refs + :delta, orphan_at = NULL WHERE id = :id", ['delta' => $delta, 'id' => $object['id']]);
            return;
        }

        $model->insert([
            'hash'         => $hash,
            'disk'         => $disk,
            'storage_path' => $path,
            'size'         => $size,
            'mime'         => $mime,
            'refs'         => max(1, $delta),
        ], just_insert: true);
    }

    /**
     * Drop a reference; stamp the object when nothing points at it any more.
     *
     * @param string $hash
     * @return void
     */
    private static function dereference(string $hash): void
    {
        (new DB)->prepare(
            "UPDATE cdn_objects
                SET refs = GREATEST(0, refs - 1),
                    orphan_at = IF(refs - 1 <= 0, :now, NULL)
              WHERE hash = :hash",
            ['now' => date('Y-m-d H:i:s'), 'hash' => $hash]
        );
    }

    /**
     * Adjust the stored-bytes and file-count totals.
     *
     * @param array $bucket
     * @param int   $bytes
     * @param int   $files
     * @return void
     */
    private static function account(array $bucket, int $bytes, int $files): void
    {
        $db = new DB;

        $db->prepare(
            "UPDATE cdn_buckets SET storage_used = GREATEST(0, storage_used + :bytes), files_count = GREATEST(0, files_count + :files) WHERE id = :id",
            ['bytes' => $bytes, 'files' => $files, 'id' => $bucket['id']]
        );

        $db->prepare(
            "UPDATE cdn_projects SET storage_used = GREATEST(0, storage_used + :bytes) WHERE id = :id",
            ['bytes' => $bytes, 'id' => $bucket['project_id']]
        );
    }

    /**
     * Image dimensions, when the type has any.
     *
     * @param string $path
     * @param string $mime
     * @return array{width:?int,height:?int}
     */
    private static function dimensions(string $path, string $mime): array
    {
        if (!Support::isImage($mime) || !is_file($path)) return ['width' => null, 'height' => null];

        $size = @getimagesize($path);
        return $size ? ['width' => (int) $size[0], 'height' => (int) $size[1]] : ['width' => null, 'height' => null];
    }

    /**
     * Strip everything executable out of an svg.
     *
     * An svg served from the asset host is a document in that host's origin.
     * Script tags, event handlers, javascript: urls, external references and
     * entity declarations all go; what is left is a picture.
     *
     * A denylist is the weaker half of the answer - `security.headers` sends a
     * sandboxing CSP with every asset, which is the half that does not depend
     * on this being exhaustive.
     *
     * @param string $svg
     * @return string
     */
    public static function sanitizeSvg(string $svg): string
    {
        # Entities can pull in a local file (billion laughs, XXE) before any of
        # the element filtering below is reached.
        $svg = preg_replace('/<!DOCTYPE.*?>/is', '', $svg);
        $svg = preg_replace('/<!ENTITY.*?>/is', '', $svg);

        $svg = preg_replace('/<\s*(script|foreignObject|iframe|embed|object|audio|video|animate|set|handler)\b.*?<\s*\/\s*\1\s*>/is', '', $svg);
        $svg = preg_replace('/<\s*(script|foreignObject|iframe|embed|object|use|image|handler)\b[^>]*\/\s*>/is', '', $svg);

        # on* attributes, quoted or bare.
        $svg = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/is', '', $svg);

        # javascript:, data:text/html and friends anywhere a url is accepted.
        $svg = preg_replace('/(href|xlink:href|src|from|to|values|begin|style)\s*=\s*("|\')\s*(javascript|vbscript|data:text\/html|data:application)[^"\']*(\2)/is', '', $svg);

        # <style> can carry url() and @import.
        $svg = preg_replace('/<\s*style\b.*?<\s*\/\s*style\s*>/is', '', $svg);

        return trim($svg);
    }
}
