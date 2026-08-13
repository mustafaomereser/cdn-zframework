# cdn-zframework

A content delivery service built on [zFramework](docs/zframework.md) v3.

Sign up, upload a file, get a URL. Ask for a different size or format by
changing the query string. Everything the panel does, an API key can do.

```
https://cdn.example.com/cdn/photos/2026/hero.jpg?w=1200&fit=cover&format=webp
```

Not a wrapper around somebody else's CDN — this *is* the origin, the cache, the
image pipeline and the control plane. Run it for yourself, or open registration
and run it for other people.

---

## Table of Contents

1. [For the person using it](#1-for-the-person-using-it)
2. [Installing it](#2-installing-it)
3. [Concepts](#3-concepts)
4. [URLs](#4-urls)
5. [Image transforms](#5-image-transforms)
6. [The API](#6-the-api)
7. [Signed URLs](#7-signed-urls)
8. [The panel, page by page](#8-the-panel-page-by-page)
9. [Accounts, quotas and access](#9-accounts-quotas-and-access)
10. [Command line](#10-command-line)
11. [Cron](#11-cron)
12. [Configuration](#12-configuration)
13. [Going to production](#13-going-to-production)
14. [What it is not](#14-what-it-is-not)

---

## 1. For the person using it

1. **Create an account** at `/auth`. You get a project, a first bucket and a
   quota immediately — nothing to configure.
2. **Drag a file** onto the Overview page.
3. **Copy the URL.** That is the file. Paste it into an `<img src>` and you are
   done.
4. **Want it smaller?** Add `?w=400`. Want webp? `?format=webp`. The first
   request builds it, everything after is a cached read. You are not storing a
   second copy and there is nothing to keep in sync.
5. **Uploading from your own code?** Make a key on the API keys page and use
   [the API](#6-the-api).

That is the whole product. The rest of this document is detail you may never
need.

---

## 2. Installing it

Requires PHP 8.1+, MySQL 8, and `gd` or `imagick` for image transforms. `apcu`
and `redis` are optional and both earn their keep.

```bash
# 1. Point database/connections.php at a database, then:
php terminal db migrate

# 2. Create the storage directories
php cdn setup

# 3. Serve
php cdn serve
```

`cdn setup` prints what it made and — more usefully — what the machine can
actually do: which image driver was found, which formats this build of PHP can
write.

Open `http://127.0.0.1:8080`, create the first account, and you are an
[operator](#operators).

The running installation documents itself at **`/docs`**, in English and
Turkish, with every example written against the host it is being read on — so
it can be copied and run without editing. This README is the same material for
people reading the repository rather than the service.

> `php cdn serve` starts PHP's built-in server **with a router script**.
> `php terminal run` does not, and without one no URL with a file extension ever
> reaches PHP — which is every URL this application serves.

---

## 3. Concepts

**Project** — your space. One per account: your buckets, your files, your keys,
your quota. Nothing is shared and nothing is visible between accounts.

**Bucket** — a folder with rules. Its name is the first path segment of every
URL it serves, so it is unique across the whole installation. Caching, whether
URLs are public, which file types are accepted, who may hotlink — all per
bucket.

**File** — one object at a path inside a bucket.

**Object** — the stored bytes, addressed by their sha256. Two files with the
same content share one object; a reference count decides when bytes may be
deleted. Uploading the same asset to ten places costs one copy.

**Variant** — a generated image: one file, one set of parameters, one cached
result. Disposable, because it can always be rebuilt.

```
project ──< buckets ──< files >── objects
                          │
                          └──< variants
```

---

## 4. URLs

```
/cdn/<bucket>/<path>
```

What a request gets:

| | |
|---|---|
| **ETag** | Strong, from the content hash. Identical bytes always produce the same tag, so re-uploading the same file makes nobody download it again. |
| **304** | `If-None-Match` and `If-Modified-Since`, answered before the disk is touched. |
| **206** | `Range` — video seeking and resumable downloads. `If-Range` honoured. |
| **HEAD** | Headers with no body: how caches and download managers ask first. |
| **CORS** | Per bucket. `*` by default, or an exact echo of a matching origin with `Vary: Origin`. |
| **gzip** | Text-ish payloads under 4 MB that are not already compressed. Never on a ranged response. |
| **Streaming** | Written in 256 KB chunks, so a 4 GB file costs a 256 KB buffer and a cancelled download stops reading. |

Responses carry `X-Cdn-Cache: HIT|MISS`, `X-Cdn-Bucket`, and `X-Cdn-Variant`
when a generated image was served.

**Force a download** instead of displaying: add `?download=1`.

**Cache headers** come from the bucket. Be deliberate with `immutable`: it tells
browsers never to re-check, which is right when the filename changes with the
content and wrong when you overwrite files in place. Nothing you do here can
reach a copy already sitting in somebody's browser.

---

## 5. Image transforms

```
?w=800                    width; height follows the aspect ratio
?h=400
?w=400&h=400&fit=cover    cover | contain | fill | pad
?q=70                     quality, 1-100
?format=webp              webp | avif | jpg | png | gif — or auto
?dpr=2                    multiplies w/h, capped at 4
?crop=x,y,w,h             taken before the resize
?rotate=90                90 | 180 | 270, clockwise
?flip=h                   h | v | both
?blur=40                  0-100
?sharpen=30               0-100
?gray=1
?bg=ffffff                background for pad, and for flattening transparency
?p=thumb                  a named preset
```

**Fit modes.** `contain` fits inside the box. `cover` crops to the box's aspect
ratio, then scales. `pad` fits inside and fills the rest with `bg`. `fill`
stretches.

**Presets** live in `config/cdn.php` and are the interface worth using — a URL
that says `?p=thumb` is one you can redefine centrally later:

```php
'presets' => [
    'thumb' => ['w' => 160,  'h' => 160, 'fit' => 'cover', 'q' => 78],
    'og'    => ['w' => 1200, 'h' => 630, 'fit' => 'cover', 'format' => 'jpg'],
],
```

**Never upscales.** Enlarging stored pixels makes a bigger file that looks
worse; asking for 2000px of a 300px image gives you 300px.

**Auto format.** An image is served as avif or webp when the browser's `Accept`
header says it can read one. The response then carries `Vary: Accept` — any
cache in front of this **must** honour it, or one visitor's avif reaches a
browser that cannot decode it.

**Built once.** The cache key is `sha1(file, normalised parameters, bucket cache
version)`, so `?w=400&h=0` and `?w=400` share one result, and clearing a bucket
invalidates every generated image at once.

**Guarded**, because the parameters come from strangers: dimensions are clamped,
and a source image above `transform.max-pixels` is refused before it is decoded.
If a transform cannot be produced at all, **the original is served** — a
slightly larger image beats a broken one.

---

## 6. The API

Base URL: `https://your-host/api/cdn/v1`

### Authentication

Create a key in the panel (API keys → New key). The secret is shown **once**.

```
X-Cdn-Key: cdn_1a2b3c4d5e6f
X-Cdn-Secret: 9f8e7d…
```

Or sign the request instead, so the secret never travels:

```
X-Cdn-Key:       cdn_1a2b3c4d5e6f
X-Cdn-Timestamp: 1786000000
X-Cdn-Signature: <hmac-sha256 of METHOD\nPATH\nsha256(body)\ntimestamp>
```

`Authorization: Bearer cdn_key:secret` works too.

**Scopes** — a key only does what it was given: `read`, `upload`, `delete`,
`purge`. A key with none is read-only. Keys can also be locked to specific
buckets, specific IP addresses, and given an expiry.

### Endpoints

| Method | Path | Scope | Does |
|---|---|---|---|
| GET | `/` | — | Project, quota, limits |
| GET | `/buckets` | read | List buckets |
| GET | `/files?bucket=&prefix=&tag=&page=` | read | List files |
| GET | `/files/{id}` | read | One file |
| POST | `/files` | upload | Upload, or fetch a URL |
| DELETE | `/files/{id}` | delete | Delete by id |
| POST | `/files/delete` | delete | Delete by bucket + path |
| POST | `/uploads` | upload | Start a resumable upload |
| PUT | `/uploads/{id}?index=N` | upload | Send one chunk |
| POST | `/uploads/{id}/complete` | upload | Finish it |
| DELETE | `/uploads/{id}` | upload | Abandon it |
| POST | `/purge` | purge | Clear generated images |
| POST | `/sign` | read | Build a signed URL |
| GET | `/stats?bucket=&days=` | read | Traffic |

### Upload a file

```bash
curl -X POST https://cdn.example.com/api/cdn/v1/files \
  -H "X-Cdn-Key: cdn_1a2b3c4d5e6f" \
  -H "X-Cdn-Secret: 9f8e7d…" \
  -F bucket=photos \
  -F path=2026/hero.jpg \
  -F file=@hero.jpg
```

```json
{
  "ok": true,
  "files": [{
    "id": 41,
    "bucket": "photos",
    "path": "2026/hero.jpg",
    "name": "hero.jpg",
    "mime": "image/jpeg",
    "size": 384022,
    "size_human": "375.02KB",
    "hash": "cb86732f2235…",
    "etag": "\"cb86732f2235…\"",
    "width": 3000, "height": 2000,
    "url": "https://cdn.example.com/cdn/photos/2026/hero.jpg"
  }],
  "errors": []
}
```

`path` is optional — without it the file is filed under `YYYY/MM/` with a
cleaned-up version of its name. Send several `file` fields to upload several at
once. Add `tags=hero,homepage` to tag them, and `overwrite=0` to refuse
replacing something already at that path.

### Upload by URL

The server fetches it. https only by default, no private addresses, size-capped
while it arrives.

```bash
curl -X POST https://cdn.example.com/api/cdn/v1/files \
  -H "X-Cdn-Key: …" -H "X-Cdn-Secret: …" \
  -H "Content-Type: application/json" \
  -d '{"bucket":"photos","url":"https://example.com/image.jpg","path":"imported/image.jpg"}'
```

### Large files, resumably

A dropped connection costs one chunk instead of the whole upload.

```bash
# 1. Open a session
curl -X POST .../uploads -H "Content-Type: application/json" \
  -d '{"bucket":"video","path":"intro.mp4","name":"intro.mp4","size":734003200}'
# → {"ok":true,"upload":{"id":"5c33…","chunk_size":8388608,"size":734003200}}

# 2. Send each chunk as a raw body
curl -X PUT ".../uploads/5c33…?index=0" --data-binary @chunk0
# → {"ok":true,"received":8388608,"size":734003200,"complete":false}

# 3. Finish
curl -X POST .../uploads/5c33…/complete
```

Completion checks the chunk bookkeeping, not just the file length — a session
where every chunk failed is still exactly the right number of bytes.

`public_html/assets/js/cdn-upload.js` does all of this from a browser:

```html
<script src="/assets/js/cdn-upload.js"></script>
<script>
    const cdn = new CdnUploader({ endpoint: '/api/cdn/v1', key: '…', secret: '…', bucket: 'photos' });

    cdn.upload(fileInput.files[0], {
        path: 'uploads/' + fileInput.files[0].name,
        onProgress: (sent, total) => bar.value = sent / total,
    }).then(response => console.log(response.file.url));
</script>
```

> A key in a web page is readable by whoever loads the page. For browser
> uploads, issue an upload-only key locked to one bucket — or better, have your
> own backend open the session and hand the browser only the upload id.

### List and delete

```bash
curl ".../files?bucket=photos&prefix=2026/&page=2" -H "X-Cdn-Key: …" -H "X-Cdn-Secret: …"

curl -X POST .../files/delete -H "Content-Type: application/json" \
  -d '{"bucket":"photos","path":"2026/hero.jpg"}'
```

### Clear generated images

```bash
curl -X POST .../purge -H "Content-Type: application/json" \
  -d '{"bucket":"photos","type":"prefix","target":"2026/"}'
```

`type` is `bucket`, `prefix`, `path` or `tag`.

### Ask for a signed URL

Keeps the signing key on your server rather than in your client.

```bash
curl -X POST .../sign -H "Content-Type: application/json" \
  -d '{"bucket":"invoices","path":"2026-08.pdf","ttl":600,"query":{"w":400}}'
# → {"ok":true,"url":"https://…?w=400&exp=1786000600&sig=hK3…","expires_at":"…"}
```

### From PHP

```php
$curl = curl_init('https://cdn.example.com/api/cdn/v1/files');

curl_setopt_array($curl, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['X-Cdn-Key: cdn_…', 'X-Cdn-Secret: …'],
    CURLOPT_POSTFIELDS     => [
        'bucket' => 'photos',
        'path'   => 'products/' . $id . '.jpg',
        'file'   => new CURLFile($_FILES['photo']['tmp_name'], $_FILES['photo']['type'], $_FILES['photo']['name']),
    ],
]);

$response = json_decode(curl_exec($curl), true);
$url      = $response['files'][0]['url'];
```

Then in a template, ask for the size the page needs:

```html
<img src="<?= $url ?>?w=600&fit=cover"
     srcset="<?= $url ?>?w=600 600w, <?= $url ?>?w=1200 1200w"
     sizes="(max-width: 600px) 100vw, 600px" alt="">
```

### Errors

Failures answer with the right status and a reason:

```json
{ "ok": false, "error": "extension-blocked" }
```

| Status | Means |
|---|---|
| 401 | Key missing, unknown, revoked or expired |
| 403 | The key lacks the scope, or the bucket is not its bucket |
| 404 | No such bucket or file |
| 422 | The request was understood and refused — see `error` |
| 429 | Rate limited; `Retry-After` says when |

Every response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`.

---

## 7. Signed URLs

A bucket set to **signed** serves nothing without a valid signature.

```php
use App\Cdn\Signature;

Signature::url('invoices', '2026-08.pdf', [
    'bucket' => $bucket,      // the bucket's own key
    'ttl'    => 600,
    'query'  => ['w' => 400], // transform parameters are signed too
    'ip'     => ip(),         // optional: bind to one address
]);
```

The signature covers the path, the expiry and **every** query parameter — which
is what stops a signed thumbnail URL being edited into a signed 5000px render.
The expiry is itself signed, so it cannot be pushed forward by whoever holds the
link, and comparison is timing-safe.

From the command line:

```bash
php cdn sign bucket=invoices path=2026-08.pdf ttl=3600 host=https://cdn.example.com
```

---

## 8. The panel, page by page

**Overview** — the page you live on. Drag files in, pick the bucket, upload.
Today's traffic, the last 30 days, how much is served from cache, how much you
have stored. Recent files, your buckets, a traffic sparkline.

**Files** — everything you have, searchable, filterable by bucket. Click one
for its URL, a live size builder (change width, format, quality and watch the
URL and preview update), and the list of sizes already generated from it.

**Buckets** — the rules. The form is short by default: name, URL name, who can
open the URLs, whether image resizing is allowed. Everything else — cache
duration, hotlink protection, allowed types, mirroring another server — is
behind **Advanced**.

**API keys** — create, scope, lock to buckets or addresses, revoke. The secret
appears once, at creation.

**Activity** — two tabs. *Requests*: what was served, what was refused and why,
how long each took. *Cache clears*: what you have purged, and what it removed.

**Settings** — your project name and quota use, the API address, a copy-paste
curl example. Operators also see what the machine can do, disk space, every
project's usage and the maintenance commands.

---

## 9. Accounts, quotas and access

### Registration

Open by default. To run this privately:

```php
// config/cdn.php
'auth' => [
    'registration' => false,
],
```

The sign-up form disappears and POSTs to it are refused — the check is on the
server, not only in the template. Existing accounts sign in as usual; make new
ones by inserting a user row, and their project appears on first sign-in.

### Operators

An operator administers the installation rather than a project: system health,
disk space, every project's usage.

```php
'auth' => [
    'operators' => ['you@example.com'],
],
```

Empty means **the first registered account** — somebody has to be, and on a
fresh install there is nobody to grant it.

Operators have their own project like everyone else. There is no "see another
project's files": the scoping is structural, not a permission that can be
granted.

### Quotas

Applied when a project is created:

```php
'auth' => [
    'defaults' => [
        'storage-quota'   => 5  * 1024 * 1024 * 1024,   // 0 = unlimited
        'bandwidth-quota' => 50 * 1024 * 1024 * 1024,   // per month
        'bucket'          => 'assets',                  // first bucket, '' to skip
    ],
],
```

Storage full → uploads refused. Transfer spent → delivery answers `509` until
the month rolls over. Change an existing project's quota in `cdn_projects`.

---

## 10. Command line

```bash
php cdn                                     # list commands
php cdn setup [bucket=assets]               # directories, first project + bucket
php cdn serve [host=] [port=8080]           # dev server, with the router
php cdn import bucket=… from=… [prefix=…]   # bulk import a directory
php cdn key create name=deploy scopes=read,upload
php cdn key list
php cdn key revoke access=cdn_xxx
php cdn sign bucket=… path=… [ttl=] [host=]
php cdn purge bucket=… [prefix=… | path=… | tag=…]
php cdn gc [grace=3600]                     # orphans, expired uploads, eviction
php cdn rollup [date=YYYY-MM-DD]            # a day of logs into the charts
php cdn prune [days=30]                     # trim the request log
php cdn verify [--fix]                      # records vs disk, recompute counters
php cdn stats [days=7]
```

`php cdn` is separate from `php terminal` on purpose: the framework only
discovers commands inside its own directory, and zFramework is upgraded by
copying a new release over the top — anything added in there is lost.

`verify` is the one to run after anything unusual: a restore, a full disk, a
migration. Counters are maintained incrementally on the hot path, which is the
right trade there and means they can drift; `--fix` recounts them and
quarantines records whose bytes are gone.

---

## 11. Cron

```
0 * * * * php /path/to/cron/cdn.php
```

One entry, hourly. Order matters and the file knows it: the rollup reads the log
before pruning trims it, and the collector runs after evictions release their
references. Daily work notices it has already run today.

Without this: charts stay empty, deleted files never free their disk space, and
the request log grows forever.

---

## 12. Configuration

Everything is `config/cdn.php`, read per request — edit the file, no restart.

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
| `auth` | registration, operators, new-project defaults |
| `admin` / `api` | routes |
| `webhooks` | timeouts, retries |
| `gc` | which housekeeping tasks run |

---

## 13. Going to production

**Serve assets from their own hostname.** `cdn.example.com`, not
`example.com/cdn`. Cookies do not travel to it, and an html or svg served from
it cannot touch your main origin.

**Turn on offload.** The transfer is the web server's job.

```apache
XSendFile On
XSendFilePath /path/to/storage/cdn
```

```nginx
location /__cdn_objects/ {
    internal;
    alias /path/to/storage/cdn/objects/;
}
```

```php
'offload' => 'x-sendfile',   // or 'x-accel-redirect'
```

**Compile the route table** — on an asset host the router runs more often than
anything else:

```bash
php terminal route cache
```

**APCu at minimum**, Redis when there is more than one server.

**Sample the request log** once traffic is real: `logging.sample = 0.05` writes
one in twenty and the rollup scales by the weight. Bandwidth counters are never
sampled — they are billed against.

**Set `app.debug` to false.** It is checked per query.

**Back up `storage/cdn/objects`.** The generated-image cache is disposable and
temp is scratch; the object store is not.

---

## 14. What it is not

- **Not geographically distributed.** This is one origin. Put a real edge
  network in front of it if you need presence on other continents — everything
  here is built to sit behind one: honest `Cache-Control`, stable strong ETags,
  correct `Vary`.
- **Not multi-region storage.** One disk per bucket, several disks per install,
  no replication.
- **No HLS/DASH packaging.** Video is served, with ranges, as uploaded.
- **No virus scanning.** Extension denylist, content sniffing and SVG
  sanitising, which are not the same thing.
- **No billing.** Quotas are enforced; nobody is charged.

---

## License

MIT. See [LICENSE](LICENSE). The framework underneath is documented in
[docs/zframework.md](docs/zframework.md).
