# cdn-zframework

A content delivery service built on [zFramework](docs/zframework.md) v3.

Not a wrapper around somebody else's CDN — this is the origin, the cache, the
image pipeline and the control plane. Point a hostname at it and it serves
files: content-addressed storage, conditional requests, range requests, signed
URLs, on-the-fly image derivatives, per-tenant quotas, an access log that rolls
up into daily statistics, and a panel to watch it from.

```
GET /cdn/assets/images/hero.jpg?w=1200&fit=cover&format=webp
```

---

## Table of Contents

1. [Install](#1-install)
2. [Concepts](#2-concepts)
3. [Delivery](#3-delivery)
4. [Image transforms](#4-image-transforms)
5. [Signed URLs](#5-signed-urls)
6. [Uploading](#6-uploading)
7. [Origin pull](#7-origin-pull)
8. [Purging](#8-purging)
9. [Management API](#9-management-api)
10. [Panel](#10-panel)
11. [Terminal](#11-terminal)
12. [Cron](#12-cron)
13. [Configuration](#13-configuration)
14. [Going to production](#14-going-to-production)
15. [What it is not](#15-what-it-is-not)

---

## 1. Install

Requires PHP 8.1+, MySQL 8, and one of `gd` / `imagick` if you want image
transforms. `apcu` and `redis` are optional and both earn their keep.

```bash
# 1. Point database/connections.php at a database, then:
php terminal db migrate

# 2. Create the storage directories and a first project + bucket
php terminal cdn setup bucket=assets

# 3. Serve
php terminal run
```

`cdn setup` prints what it made and, more usefully, what the machine can
actually do — which image driver was found, which output formats this build of
PHP can write.

Upload something and fetch it back:

```bash
php terminal cdn import bucket=assets from=./some-images prefix=images
```

```
http://localhost:8000/cdn/assets/images/whatever.jpg
```

The panel is at `/cdn-admin` and needs a signed-in user (`/auth`).

---

## 2. Concepts

**Project** — a tenant. Owns buckets, API keys and quotas. A single-tenant
install still has exactly one; the panel creates it on first use.

**Bucket** — a namespace and a policy. The slug is the first path segment of
every URL it serves, so it is globally unique. Almost everything the delivery
path decides — how long to cache, whether a signature is required, which
extensions are accepted, who may hotlink — is a column on this row, so the
answer to "why is this file behaving like that" is one row rather than a hunt
through config.

**File** — one logical object at a path inside a bucket.

**Object** — the stored bytes, addressed by their sha256. Two files with
identical content share one object, and a reference count decides when the bytes
may actually be deleted. This is why deleting a file is instant and why
uploading the same asset to ten buckets costs one copy.

**Variant** — a derivative: one file, one set of transform parameters, one
cached result. Regenerable, so it is safe to evict and cheap to purge.

```
cdn_projects ──< cdn_buckets ──< cdn_files >── cdn_objects
                                     │
                                     └──< cdn_variants
```

---

## 3. Delivery

```
/cdn/<bucket>/<path>
```

What a request gets:

| | |
|---|---|
| **ETag** | Strong, derived from the content hash. The same bytes always produce the same tag, so re-uploading an identical file does not make anyone download it again. |
| **304** | `If-None-Match` and `If-Modified-Since`, answered before the disk is touched. |
| **206** | `Range`, single range. Video seeking and resumed downloads. `If-Range` is honoured. |
| **HEAD** | Headers without a body — how every cache and download manager asks first. |
| **CORS** | Per bucket. `*` by default, or an exact echo of a matching origin plus `Vary: Origin`. |
| **gzip** | For text-ish payloads under 4 MB that are not already compressed. Never on a ranged response. |
| **Streaming** | The body is written in 256 KB chunks, so a 4 GB file costs a 256 KB buffer, and a cancelled transfer stops reading. |

Responses carry `X-Cdn-Cache: HIT|MISS`, `X-Cdn-Bucket`, and `X-Cdn-Variant`
when a derivative was served.

**Cache headers** come from the bucket: `cache_ttl` becomes `max-age`,
`immutable` adds the immutable directive, `delivery.swr` adds
`stale-while-revalidate`.

Be deliberate about `immutable`. It tells a browser never to revalidate, which
is correct when the URL contains a content hash and wrong when you intend to
overwrite the same path — no purge can reach a copy that is already in
somebody's browser.

---

## 4. Image transforms

```
?w=800                    width, height follows the aspect ratio
?h=400
?w=400&h=400&fit=cover    cover | contain | fill | pad
?q=70                     quality, 1-100
?format=webp              webp | avif | jpg | png | gif — or auto
?dpr=2                    multiplies w/h, capped at 4
?crop=x,y,w,h             before the resize
?rotate=90                90 | 180 | 270, clockwise
?flip=h                   h | v | both
?blur=40                  0-100
?sharpen=30               0-100
?gray=1
?bg=ffffff                background for pad, and for flattening transparency
?p=thumb                  a named preset from config
```

Presets live in `config/cdn.php` and are the recommended interface — a URL that
says `?p=thumb` is one a designer can change centrally later.

**Never upscales.** Enlarging stored pixels produces a bigger file that looks
worse, and it is almost always a mistake in the URL.

**Auto format.** With `transform.auto-format` on, an image is served as avif or
webp when the browser's `Accept` header says it can read one. The response then
carries `Vary: Accept` — any cache in front of this **must** honour it, or one
visitor's avif reaches a browser that cannot decode it.

**Caching.** Each distinct combination is built once. The cache key is
`sha1(file, normalised parameters, bucket cache version)`, which is why
`?w=400&h=0` and `?w=400` share one derivative, and why bumping the bucket's
version invalidates everything at once.

**Guards**, because the parameters come from a stranger: dimensions are clamped
to `transform.max-width` / `max-height`, and a source image above
`transform.max-pixels` is refused before it is decoded — a 30000×30000 png is a
few hundred KB on disk and several GB in memory.

If a transform cannot be produced — no image library, an unreadable source, a
format this build cannot write — the **original is served**. A slightly larger
image beats a broken one.

Set `transform.signed-only` on a public bucket to stop a stranger filling the
derivative cache by walking `?w=1` through `?w=5000`.

---

## 5. Signed URLs

A bucket set to `signed` serves nothing without a valid signature.

```php
use App\Cdn\Signature;

Signature::url('assets', 'invoices/2026-08.pdf', [
    'bucket' => $bucket,          // uses the bucket's own signing key
    'ttl'    => 600,
    'query'  => ['w' => 400],     // transform parameters are signed too
    'ip'     => ip(),             // optional: bind to one address
]);
```

```
/cdn/assets/invoices/2026-08.pdf?w=400&exp=1786000000&sig=hK3...
```

The signature covers the path, the expiry and **every** query parameter, which
is what stops a signed thumbnail URL being edited into a signed 5000px render.
Comparison is timing-safe, and the expiry is itself signed, so it cannot be
pushed forward by whoever holds the link.

The key is per bucket, falling back to `signing.key`, falling back to
`config/crypt.php`. Rotating one bucket's key invalidates that bucket's links
and nothing else.

From the command line, or the API, when you do not want to ship the key:

```bash
php terminal cdn sign bucket=assets path=images/logo.png ttl=3600
```

---

## 6. Uploading

**Multipart**, for ordinary files:

```bash
curl -X POST https://cdn.example.com/api/cdn/v1/files \
  -H "X-Cdn-Key: cdn_xxx" -H "X-Cdn-Secret: yyy" \
  -F bucket=assets -F path=images/hero.jpg -F file=@hero.jpg
```

**By URL**, fetched by the server:

```bash
curl -X POST https://cdn.example.com/api/cdn/v1/files \
  -H "X-Cdn-Key: cdn_xxx" -H "X-Cdn-Secret: yyy" \
  -H "Content-Type: application/json" \
  -d '{"bucket":"assets","url":"https://example.com/photo.jpg"}'
```

**Resumable**, for large files — open a session, send chunks, complete. A
dropped connection costs one chunk. `public_html/assets/js/cdn-upload.js` is a
browser client for it.

Every route ends in the same validation, so none of them can skip a step:

- extension denylist first, including every part of a double extension — a
  `photo.php.jpg` is refused whatever the last dot says;
- content sniffed with `finfo` and the sniffed type kept, not the declared one;
- per-bucket extension and mime allowlists;
- size limits, project storage quota;
- SVG stripped of scripts, event handlers, external references and entity
  declarations — before hashing, so what is stored is what was checked;
- sha256, deduplication, atomic write.

Uploads land outside the document root and are served only through the delivery
route. `cdn setup` warns loudly if the object root is inside the public
directory, because that arrangement bypasses every check above.

---

## 7. Origin pull

Give a bucket an `origin_url` and it behaves like an edge: a miss is fetched
upstream, stored as an ordinary object, and every request after that is a local
read. Nobody has to upload anything.

```
origin_url  https://origin.example.com/assets
origin_ttl  86400        # after this, the copy is refreshed on next request
```

Misses are remembered for `origin.negative-ttl` seconds — without that, a bot
walking `/1.jpg` … `/99999.jpg` becomes that many upstream requests. Fetches go
through the same guard as upload-by-URL: https, no private addresses, a redirect
budget and a hard size cap enforced while the body arrives. With
`origin.stale-on-error` on, an origin outage does not take the cached copies down
with it.

---

## 8. Purging

```bash
php terminal cdn purge bucket=assets                    # whole bucket
php terminal cdn purge bucket=assets prefix=images/     # a subtree
php terminal cdn purge bucket=assets path=logo.png      # one file
php terminal cdn purge bucket=assets tag=hero           # by tag
```

A bucket purge bumps `cache_version`, which changes every derivative signature
at once — effective immediately, whatever the disk is doing. The files are then
deleted for the disk's sake, not for correctness.

**A purge cannot reach copies in browsers or in a cache in front of this
server.** Only the URL changing, or a short `max-age`, can. Every purge is
recorded in `cdn_purges` with what it removed and who asked, because "the old
image is still showing" is unanswerable without that.

---

## 9. Management API

Base: `/api/cdn/v1`. Authenticate with a key and secret:

```
X-Cdn-Key: cdn_xxx
X-Cdn-Secret: yyy
```

or sign the request instead, so the secret never leaves the client:

```
X-Cdn-Key:       cdn_xxx
X-Cdn-Timestamp: 1786000000
X-Cdn-Signature: hmac-sha256(secret, METHOD\nPATH\nsha256(body)\ntimestamp)
```

| Method | Path | Scope |
|---|---|---|
| GET | `/` | — |
| GET | `/buckets` | read |
| GET | `/files?bucket=&prefix=&tag=&page=` | read |
| GET | `/files/{id}` | read |
| POST | `/files` | upload |
| DELETE | `/files/{id}` | delete |
| POST | `/files/delete` | delete |
| POST | `/uploads` | upload |
| PUT | `/uploads/{id}?index=N` | upload |
| POST | `/uploads/{id}/complete` | upload |
| DELETE | `/uploads/{id}` | upload |
| POST | `/purge` | purge |
| POST | `/sign` | read |
| GET | `/stats?bucket=&days=` | read |

Scopes are `read`, `upload`, `delete`, `purge`, `admin`. A key with no scopes
recorded is read-only — a key created carelessly should be the least dangerous
thing, not the most. Keys can be bound to specific buckets, to specific
addresses, and given an expiry.

Rate limits are per key, and every response carries `X-RateLimit-*`.

---

## 10. Panel

`/cdn-admin`, behind the application's auth. Restrict it with `admin.emails`.

- **Dashboard** — transfer, hit ratio, stored bytes, derivative count, requests
  per day, today's traffic read live from the log (the rollup is nightly), most
  requested files.
- **Buckets** — every policy, with the caching trade-offs written next to the
  checkbox rather than in a manual.
- **Files** — browse, search, upload, preview, per-file derivative list with
  hit counts and build times. That last number is what tells you whether a
  preset is worth pre-generating.
- **API keys** — create, scope, bind, revoke. The secret is shown once.
- **Access log** — filter by bucket, outcome and status. Refusals are recorded
  with their reason.
- **Purges** — the audit trail.
- **Settings** — what is configured, and separately what this machine can
  actually do: image driver, writable formats, APCu, Redis, finfo, disk space.

---

## 11. Terminal

```bash
php terminal cdn setup [bucket=assets]     # directories, first project + bucket
php terminal cdn import bucket=… from=…    # bulk import a directory
php terminal cdn key create name=deploy scopes=read,upload
php terminal cdn key list
php terminal cdn key revoke access=cdn_xxx
php terminal cdn sign bucket=… path=… [ttl=3600]
php terminal cdn purge bucket=… [prefix=… | path=… | tag=…]
php terminal cdn gc [grace=3600]           # orphans, expired uploads, eviction
php terminal cdn rollup [date=YYYY-MM-DD]  # a day of logs into cdn_stats
php terminal cdn prune [days=30]           # trim the access log
php terminal cdn verify [--fix]            # rows vs disk, recompute counters
php terminal cdn stats [days=7]
```

`verify` is the one to run after anything unusual — a restore, a disk that
filled, a migration. Counters are maintained incrementally on the hot path,
which is the right trade there and means they can drift; `--fix` recounts them
and quarantines rows whose bytes are gone.

---

## 12. Cron

```
0 * * * * php /path/to/cron/cdn.php
```

One entry, hourly. The order matters and the file knows it: the rollup reads the
log before the pruning trims it, and the collector runs after evictions have
released their references. Daily work notices for itself that it has already run
today, so calling it more often is harmless.

---

## 13. Configuration

Everything is `config/cdn.php`, read per request — edit the file, no restart.
It is commented at the level of *why*, not *what*. The sections:

| | |
|---|---|
| `storage` | disks, roots, fanout depth |
| `delivery` | url prefix, path depth, ttl, ranges, compression, CORS, offload |
| `signing` | key, algorithm, ttl, address binding, parameter names |
| `transform` | driver, clamps, quality, formats, presets, cache size and ttl |
| `upload` | size limits, chunk size, denylist, mime verification, svg sanitising, remote fetch |
| `origin` | timeouts, size cap, negative ttl, stale-on-error |
| `limits` | rate limiting per address, per key, per upload |
| `security` | hotlink policy, address rules, response headers, forced downloads |
| `logging` | driver, sample rate, retention, exact counters |
| `cache` | registry ttl |
| `admin` / `api` | routes, access |
| `webhooks` | timeouts, retries |
| `gc` | which housekeeping tasks run |

---

## 14. Going to production

**Serve the assets from their own hostname.** `cdn.example.com`, not
`example.com/cdn`. Cookies do not travel to it, so every asset request is a few
hundred bytes smaller, and an html or svg served from it cannot touch the main
origin.

**Turn on offload.** The transfer is the web server's job; PHP has nothing to
add to it after the headers. Without this, a worker is occupied for the whole of
every download.

```apache
# Apache, needs mod_xsendfile
XSendFile On
XSendFilePath /path/to/storage/cdn
```

```nginx
# nginx
location /__cdn_objects/ {
    internal;
    alias /path/to/storage/cdn/objects/;
}
```

```php
'offload' => 'x-sendfile',        // or 'x-accel-redirect'
```

**Compile the route table** — on an asset host the router runs more often than
anything else. Every CDN route is declared as `[Controller::class, 'method']`
precisely so it can be:

```bash
php terminal route cache
```

**APCu at minimum.** Bucket and project rows are read on every request and
change a few times a day; `cache.registry-ttl` keeps them out of the database.
Add Redis when there is more than one server, so rate limits and cache
invalidation are shared.

**Sample the access log** once traffic is real. `logging.sample = 0.05` writes
one request in twenty and the rollup scales by the weight, so the totals stay
approximately right at a twentieth of the disk. Bandwidth counters are never
sampled — they are billed against.

**Set `app.debug` to false.** It is checked per query.

**Back up `storage/cdn/objects`.** The derivative cache is regenerable and the
temp directory is disposable; the object store is not.

---

## 15. What it is not

Worth being explicit, because the word "CDN" promises some of this:

- **Not geographically distributed.** This is one origin. Put a real edge
  network in front of it if you need presence in other continents — everything
  here is designed to sit behind one correctly: honest `Cache-Control`, stable
  strong ETags, correct `Vary`.
- **Not multi-region storage.** One disk per bucket, several disks per install.
  No replication.
- **No HLS/DASH packaging.** Video is served, with ranges, exactly as uploaded.
- **No virus scanning.** Extension denylist, content sniffing and SVG
  sanitising, which are not the same thing.

---

## License

MIT. See [LICENSE](LICENSE).

The framework underneath is documented in [docs/zframework.md](docs/zframework.md).
