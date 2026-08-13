<h1>Documentation</h1>
<p class="lead">
    Upload a file, get a URL. Ask for a different size, format or crop by changing the query string.
    Everything the panel does, an API key can do.
</p>

<section id="start">
    <h2>Quick start</h2>

    <ol class="steps">
        <li>
            <b>Create an account.</b> <a href="<?= route('auth-form') ?>">Sign up</a> — you get a project,
            a first bucket and a quota straight away. Nothing to configure.
        </li>
        <li>
            <b>Upload something.</b> Drag it onto the panel's <em>Overview</em> page, or send it from your
            own code with <a href="#api">the API</a>.
        </li>
        <li>
            <b>Use the URL.</b> Copy it from the file's page and paste it into an
            <code>&lt;img src&gt;</code>.
        </li>
        <li>
            <b>Want it smaller?</b> Add <code>?w=400</code>. The first request builds it; every request
            after that is a cached read.
        </li>
    </ol>

    <div class="note">
        That is the whole product. The rest of this page is detail for when you need it.
    </div>
</section>

<section id="urls">
    <h2>URLs</h2>

    <pre><code><?= $host . $prefix ?>/&lt;project&gt;/&lt;bucket&gt;/&lt;path&gt;</code></pre>

    <p>
        The first segment is your project, the second is the bucket, and the rest is the file's path
        inside it. The project is a namespace of its own, so a bucket name only has to be unique within
        yours — another account can have a <code>photos</code> bucket too:
    </p>

    <pre><code><?= $host . $prefix ?>/<?= $project ?>/photos/2026/hero.jpg</code></pre>

    <h3>What a request gets</h3>

    <table class="table">
        <thead><tr><th>What</th><th>Why it matters</th></tr></thead>
        <tbody>
            <tr>
                <td><code>ETag</code></td>
                <td>Derived from the content. Identical bytes always produce the same tag, so re-uploading
                    the same file makes nobody download it again.</td>
            </tr>
            <tr>
                <td><code>304</code></td>
                <td>No body when the browser already has the file. Answered before the disk is touched.</td>
            </tr>
            <tr>
                <td><code>206</code></td>
                <td><code>Range</code> support: seeking in video, resuming an interrupted download.</td>
            </tr>
            <tr>
                <td><code>HEAD</code></td>
                <td>Headers with no body — how caches and download managers ask first.</td>
            </tr>
            <tr>
                <td>CORS</td>
                <td>Per bucket. <code>*</code> by default, or an exact echo of an allowed origin.</td>
            </tr>
            <tr>
                <td>gzip</td>
                <td>For uncompressed text types under 4 MB.</td>
            </tr>
        </tbody>
    </table>

    <p>
        Responses carry <code>X-Cdn-Cache: HIT|MISS</code>, so you can see whether a request was answered
        from cache or produced.
    </p>

    <h3>Force a download</h3>
    <pre><code><?= $host . $prefix ?>/<?= $project ?>/files/report.pdf?download=1</code></pre>
</section>

<section id="projects">
    <h2>Projects</h2>

    <p>
        A project is the first segment of your URLs, and a namespace: buckets, files, keys and your quota
        all belong to one. You get one when you sign up; make a second in the panel under
        <em>Projects → New project</em>.
    </p>

    <p>
        <b>Every project's URL starts with your account's name.</b> Your first project is that name; the
        rest are <code>&lt;account&gt;-&lt;project&gt;</code>:
    </p>

    <pre><code><?= $host . $prefix ?>/<?= $slug ?>/logos/mark.svg
<?= $host . $prefix ?>/<?= $slug ?>-staging/logos/mark.svg</code></pre>

    <p>
        So a URL says whose it is, and nobody can take a name somebody else wanted. The URL name is decided
        when the project is created and <b>never changes</b> — it is in every address that project has ever
        served. The display name can be changed whenever you like; addresses are unaffected.
    </p>

    <div class="note">
        <b>Your main project cannot be deleted or renamed.</b> Its name is your namespace and every other
        project's is derived from it. The rest can be deleted, however few are left — and deleting one takes
        its buckets, files and keys with it.
    </div>

    <p>
        The switcher at the top of the sidebar decides what the panel is showing: files, buckets, keys and
        activity are all narrowed to the selected project. <em>All projects</em> puts them back together.
    </p>
</section>

<section id="images">
    <h2>Image sizes</h2>

    <p>
        For images, add parameters to the URL to ask for the size you want. Each combination is built
        <b>once</b> and then served from cache. You are not storing a second copy.
    </p>

    <pre><code>?w=800                    width; height follows the aspect ratio
?h=400
?w=400&amp;h=400&amp;fit=cover    cover | contain | fill | pad
?q=70                     quality, 1-100
?format=webp              webp | avif | jpg | png | gif
?dpr=2                    multiplies w/h, capped at 4
?crop=x,y,w,h             taken before the resize
?rotate=90                90 | 180 | 270, clockwise
?flip=h                   h | v | both
?blur=40                  0-100
?sharpen=30               0-100
?gray=1                   greyscale
?bg=ffffff                background for pad, and for flattening transparency
?p=thumb                  a ready-made size</code></pre>

    <h3>Fit modes</h3>

    <table class="table">
        <thead><tr><th>Value</th><th>Behaviour</th></tr></thead>
        <tbody>
            <tr><td><code>contain</code></td><td>Fits inside the box, keeps the aspect ratio. The default.</td></tr>
            <tr><td><code>cover</code></td><td>Crops to the requested aspect ratio, then scales. This is the one for square avatars.</td></tr>
            <tr><td><code>pad</code></td><td>Fits inside and fills the rest with <code>bg</code>.</td></tr>
            <tr><td><code>fill</code></td><td>Stretches to exactly the given size, distorting it.</td></tr>
        </tbody>
    </table>

    <div class="note">
        <b>It never upscales.</b> Ask for 2000px of a 300px image and you get 300px. Enlarging stored
        pixels produces a bigger file that looks worse, and it is almost always a mistake in the URL.
    </div>

    <h3>Automatic format</h3>
    <p>
        With no <code>format</code>, the browser's <code>Accept</code> header decides: avif if it reads
        avif, webp if it reads webp, otherwise the original type. The response then carries
        <code>Vary: Accept</code>.
    </p>

    <h3>Ready-made sizes</h3>
    <p>
        Named sizes like <code>?p=thumb</code> are defined on the server. They are the interface worth
        using: when the design changes you redefine one preset instead of every URL on every page.
    </p>
    <pre><code><?= $host . $prefix ?>/<?= $project ?>/photos/hero.jpg?p=thumb</code></pre>

    <h3>Responsive images</h3>
    <pre><code>&lt;img src="<?= $host . $prefix ?>/<?= $project ?>/photos/hero.jpg?w=600&amp;fit=cover"
     srcset="<?= $host . $prefix ?>/<?= $project ?>/photos/hero.jpg?w=600 600w,
             <?= $host . $prefix ?>/<?= $project ?>/photos/hero.jpg?w=1200 1200w"
     sizes="(max-width: 600px) 100vw, 600px" alt=""&gt;</code></pre>
</section>

<section id="minify">
    <h2>CSS and JS</h2>

    <p>Stylesheets and scripts can be served minified. Add <code>?min=1</code>:</p>

    <pre><code><?= $host . $prefix ?>/<?= $slug ?>/assets/site.css?min=1</code></pre>

    <p>
        Turn on <em>Minify css and js</em> in the bucket and the plain URL serves the smaller copy with no
        parameter at all. <code>?min=0</code> always returns the original — which is the first thing to try
        when a file looks mangled.
    </p>

    <div class="note">
        <b>The file you uploaded is never touched.</b> The minified copy is a derivative, like a resized
        image: stored separately, cleared when you clear the bucket, rebuilt when it is needed. If minifying
        breaks a file or does not make it smaller, the original is served.
    </div>

    <p>
        Anything already named <code>*.min.js</code> is skipped. Text files are served with
        <code>charset=utf-8</code>, so non-ascii characters are not left to the browser to guess.
    </p>
</section>

<section id="buckets">
    <h2>Buckets</h2>

    <p>
        A bucket is a folder with rules. Its name is the segment after the project in every URL it serves,
        and only has to be unique inside that project.
    </p>

    <table class="table">
        <thead><tr><th>Setting</th><th>What it means</th></tr></thead>
        <tbody>
            <tr>
                <td>Visibility</td>
                <td>
                    <b>Anyone with the link</b> — normal for site assets.<br>
                    <b>Signed</b> — a valid, expiring signature is required.<br>
                    <b>Private</b> — no public door at all, API only.
                </td>
            </tr>
            <tr>
                <td>Cache duration</td>
                <td>How long a browser may keep its copy. Longer is faster and cheaper, but
                    <b>you cannot reach a copy already in somebody's browser</b>.</td>
            </tr>
            <tr>
                <td>Image resizing</td>
                <td>Whether <code>?w=400</code> and friends work in this bucket.</td>
            </tr>
            <tr>
                <td>Hotlink protection</td>
                <td>Which sites may embed your files, so your bandwidth does not go to somebody else's
                    page.</td>
            </tr>
            <tr>
                <td>Allowed types</td>
                <td>Extension and content-type limits. Empty means anything outside the security
                    denylist.</td>
            </tr>
            <tr>
                <td>Origin (mirroring)</td>
                <td>Give an address and a file nobody uploaded is fetched from there the first time it is
                    asked for, then served from here.</td>
            </tr>
        </tbody>
    </table>

    <div class="note">
        <b>Choosing a cache duration:</b> if you overwrite files at the same path, keep it short. If your
        filenames change with the content (<code>hero.a1b2c3.jpg</code>), make it a year and mark it
        <em>immutable</em>.
    </div>
</section>

    <h3>Moving and deleting in bulk</h3>

    <p>
        Tick a few files on the Files page and a bar appears: delete them, or move them into another bucket -
        including a bucket in another of your projects.
    </p>

    <div class="note">
        A move copies nothing on disk; the bytes are content addressed, so it is three columns and the
        counters either side of them. What changes is the <b>URL</b>: the project and the bucket are both
        segments of it, so the file's old address stops working and no redirect is left behind.
    </div>

<section id="signed">
    <h2>Signed URLs</h2>

    <p>
        A signed bucket serves nothing without a valid signature. The signature covers the path, the
        expiry and <b>every</b> query parameter — so a signed thumbnail URL cannot be edited into a
        signed 5000px render.
    </p>

    <p>Ask your server for one, and the signing key never reaches the client:</p>

    <pre><code>curl -X POST <?= $host . $api ?>/sign \
  -H "X-Cdn-Key: cdn_..." \
  -H "X-Cdn-Secret: ..." \
  -H "Content-Type: application/json" \
  -d '{"bucket":"invoices","path":"2026-08.pdf","ttl":600}'</code></pre>

    <pre><code>{
  "ok": true,
  "url": "<?= $host . $prefix ?>/<?= $project ?>/invoices/2026-08.pdf?exp=1786000600&amp;sig=hK3...",
  "expires_at": "2026-08-13T03:20:00+03:00"
}</code></pre>

    <p>
        Past the expiry the URL answers 403. Pushing the expiry forward invalidates the signature; you
        have to ask for a new one.
    </p>
</section>

<section id="api">
    <h2>API</h2>

    <p>Base URL:</p>
    <pre><code><?= $host . $api ?></code></pre>

    <h3>Authentication</h3>
    <p>
        Create a key on the panel's <em>API keys</em> page. The secret is shown <b>once</b>: it is not
        stored, so a lost one has to be revoked and replaced.
    </p>

    <pre><code>X-Cdn-Key: cdn_1a2b3c4d5e6f
X-Cdn-Secret: 9f8e7d...</code></pre>

    <p>Or sign the request, and the secret never travels:</p>

    <pre><code>X-Cdn-Key:       cdn_1a2b3c4d5e6f
X-Cdn-Timestamp: 1786000000
X-Cdn-Signature: hmac-sha256( METHOD\nPATH\nsha256(body)\ntimestamp , secret )</code></pre>

    <h3>Scopes</h3>
    <p>
        A key does only what it was given: <code>read</code>, <code>upload</code>, <code>delete</code>,
        <code>purge</code>. With none selected it is read-only. A key can also be locked to one bucket,
        to specific IP addresses, and given an expiry.
    </p>

    <h3>Endpoints</h3>

    <table class="table">
        <thead><tr><th>Method</th><th>Path</th><th>Scope</th><th>Does</th></tr></thead>
        <tbody>
            <tr><td>GET</td><td><code>/</code></td><td>—</td><td>Project, quota, limits</td></tr>
            <tr><td>GET</td><td><code>/buckets</code></td><td>read</td><td>List buckets</td></tr>
            <tr><td>GET</td><td><code>/files</code></td><td>read</td><td>List files</td></tr>
            <tr><td>GET</td><td><code>/files/{id}</code></td><td>read</td><td>One file</td></tr>
            <tr><td>POST</td><td><code>/files</code></td><td>upload</td><td>Upload, or fetch a URL</td></tr>
            <tr><td>DELETE</td><td><code>/files/{id}</code></td><td>delete</td><td>Delete by id</td></tr>
            <tr><td>POST</td><td><code>/files/delete</code></td><td>delete</td><td>Delete by bucket + path</td></tr>
            <tr><td>POST</td><td><code>/uploads</code></td><td>upload</td><td>Start a chunked upload</td></tr>
            <tr><td>PUT</td><td><code>/uploads/{id}?index=N</code></td><td>upload</td><td>Send a chunk</td></tr>
            <tr><td>POST</td><td><code>/uploads/{id}/complete</code></td><td>upload</td><td>Finish it</td></tr>
            <tr><td>POST</td><td><code>/purge</code></td><td>purge</td><td>Clear generated sizes</td></tr>
            <tr><td>POST</td><td><code>/sign</code></td><td>read</td><td>Build a signed URL</td></tr>
            <tr><td>GET</td><td><code>/stats</code></td><td>read</td><td>Traffic</td></tr>
        </tbody>
    </table>

    <p>Every response carries <code>X-RateLimit-Limit</code> and <code>X-RateLimit-Remaining</code>.</p>
</section>

<section id="upload">
    <h2>Uploading</h2>

    <h3>Sending a file</h3>

    <pre><code>curl -X POST <?= $host . $api ?>/files \
  -H "X-Cdn-Key: cdn_..." \
  -H "X-Cdn-Secret: ..." \
  -F bucket=photos \
  -F path=2026/hero.jpg \
  -F file=@hero.jpg</code></pre>

    <pre><code>{
  "ok": true,
  "files": [{
    "id": 41,
    "bucket": "photos",
    "path": "2026/hero.jpg",
    "mime": "image/jpeg",
    "size": 384022,
    "width": 3000, "height": 2000,
    "url": "<?= $host . $prefix ?>/<?= $project ?>/photos/2026/hero.jpg"
  }],
  "errors": []
}</code></pre>

    <p>
        <code>path</code> is optional — without it the file is filed under <code>YYYY/MM/</code> with a
        cleaned-up name. Send several <code>file</code> fields to upload several at once.
        <code>overwrite=0</code> refuses to replace something already at that path.
    </p>

    <h3>Fetching a URL</h3>
    <p>The server downloads it. https only, no private addresses, and the size cap is enforced while the body arrives.</p>

    <pre><code>curl -X POST <?= $host . $api ?>/files \
  -H "X-Cdn-Key: cdn_..." -H "X-Cdn-Secret: ..." \
  -H "Content-Type: application/json" \
  -d '{"bucket":"photos","url":"https://example.com/photo.jpg"}'</code></pre>

    <h3>Large files, in chunks</h3>
    <p>A dropped connection costs one chunk rather than the whole upload.</p>

    <pre><code># 1. Open a session
curl -X POST <?= $host . $api ?>/uploads \
  -H "Content-Type: application/json" \
  -d '{"bucket":"video","path":"intro.mp4","name":"intro.mp4","size":734003200}'
# → {"ok":true,"upload":{"id":"5c33...","chunk_size":8388608}}

# 2. Send each chunk as a raw body
curl -X PUT "<?= $host . $api ?>/uploads/5c33.../?index=0" --data-binary @chunk0

# 3. Finish
curl -X POST <?= $host . $api ?>/uploads/5c33.../complete</code></pre>

    <p>A browser client for the same flow ships with the service:</p>

    <pre><code>&lt;script src="/assets/js/cdn-upload.js"&gt;&lt;/script&gt;
&lt;script&gt;
const cdn = new CdnUploader({
    endpoint: '<?= $api ?>', key: 'cdn_...', secret: '...', bucket: 'photos'
});

cdn.upload(input.files[0], {
    onProgress: (sent, total) =&gt; bar.value = sent / total,
}).then(response =&gt; console.log(response.file.url));
&lt;/script&gt;</code></pre>

    <div class="note">
        A key in a web page is readable by whoever loads the page. For browser uploads, issue a key with
        only <code>upload</code> scope, locked to one bucket — or better, have your own backend open the
        session and hand the browser nothing but the upload id.
    </div>

    <h3>From PHP</h3>

    <pre><code>&lt;?php
$curl = curl_init('<?= $host . $api ?>/files');

curl_setopt_array($curl, [
    CURLOPT_POST           =&gt; true,
    CURLOPT_RETURNTRANSFER =&gt; true,
    CURLOPT_HTTPHEADER     =&gt; ['X-Cdn-Key: cdn_...', 'X-Cdn-Secret: ...'],
    CURLOPT_POSTFIELDS     =&gt; [
        'bucket' =&gt; 'photos',
        'path'   =&gt; 'products/' . $id . '.jpg',
        'file'   =&gt; new CURLFile($_FILES['photo']['tmp_name']),
    ],
]);

$response = json_decode(curl_exec($curl), true);
$url      = $response['files'][0]['url'];</code></pre>

    <h3>What gets refused</h3>
    <ul>
        <li>Extensions that can execute on a server — <code>.php</code>, <code>.exe</code> and the like.
            Double extensions count: <code>photo.php.jpg</code> is refused.</li>
        <li>Files whose contents disagree with their extension; the bytes are checked, not the claim.</li>
        <li>Anything outside the bucket's allowed types.</li>
        <li>Anything over the size limit or your storage quota.</li>
    </ul>
    <p>SVG files are stripped of scripts, event handlers and external references before they are stored.</p>
</section>

<section id="purge">
    <h2>Clearing cache</h2>

    <p>
        Clearing removes the image sizes generated on this side and makes the next request build fresh
        ones.
    </p>

    <pre><code>curl -X POST <?= $host . $api ?>/purge \
  -H "Content-Type: application/json" \
  -d '{"bucket":"photos","type":"prefix","target":"2026/"}'</code></pre>

    <p><code>type</code> is one of <code>bucket</code>, <code>prefix</code>, <code>path</code>, <code>tag</code>.</p>

    <div class="note">
        <b>What it cannot reach:</b> copies already in a visitor's browser, or in any cache in front of
        this server. Nothing you do here touches those — only the URL changing, or a short cache
        duration, does. If you overwrite files in place, keep the bucket's cache duration short.
    </div>
</section>

<section id="quotas">
    <h2>Quotas and limits</h2>

    <p>Two of them, both belonging to <b>your account</b> and shared by its projects:</p>

    <table class="table">
        <thead>
            <tr><th>Quota</th><th>Enforced at</th><th>What happens when it is spent</th></tr>
        </thead>
        <tbody>
            <tr><td>Storage</td><td>upload</td><td>the upload is refused</td></tr>
            <tr><td>Monthly transfer</td><td>delivery</td><td>URLs answer <code>509</code> until the month rolls over</td></tr>
        </tbody>
    </table>

    <p>
        <b>Creating a project does not add storage.</b> The transfer counter belongs to a month and starts
        again when the month does, with no cron run to wait for. You can see where you are in the sidebar
        and on the <em>Settings</em> page. An operator can give one project a quota of its own, and the
        project's page then says "this project only".
    </p>

    <h3>Suspension</h3>

    <p>The operator of an installation can suspend a project or an account. While suspended:</p>

    <ul>
        <li>its URLs answer <code>403</code>,</li>
        <li>nothing can be uploaded to it or deleted from it,</li>
        <li>its files are untouched — everything comes back the moment it is restored.</li>
    </ul>

    <p>
        The reason is on the project's page in the panel. If the account itself is suspended, you see it at
        sign-in.
    </p>
</section>

<section id="errors">
    <h2>Errors</h2>

    <p>A failed request answers with the right status and a machine-readable reason:</p>

    <pre><code>{ "ok": false, "error": "extension-blocked" }</code></pre>

    <table class="table">
        <thead><tr><th>Status</th><th>Means</th></tr></thead>
        <tbody>
            <tr><td>401</td><td>Key missing, unknown, revoked or expired</td></tr>
            <tr><td>403</td><td>The key lacks the scope, or the bucket is not its bucket</td></tr>
            <tr><td>404</td><td>No such bucket or file</td></tr>
            <tr><td>409 / 422</td><td>Understood and refused — see the <code>error</code> field</td></tr>
            <tr><td>429</td><td>Rate limited; <code>Retry-After</code> says when to come back</td></tr>
            <tr><td>509</td><td>Your monthly transfer quota is spent</td></tr>
        </tbody>
    </table>

    <p>
        Refusals on the delivery side appear on the panel's <em>Activity</em> page with their reason
        attached — an expired signature, a blocked referer, a rate limit.
    </p>
</section>

<section id="cli">
    <h2>Command line</h2>

    <p>If you have access to the server:</p>

    <pre><code>php cdn                                  # list the commands
php cdn setup                            # directories, first project and bucket
php cdn serve host=0.0.0.0 port=8080     # development server
php cdn import bucket=... from=...       # bulk import a directory
php cdn key create name=deploy scopes=read,upload
php cdn sign bucket=... path=... ttl=3600
php cdn purge bucket=... prefix=...
php cdn gc                               # unused files, expired uploads
php cdn rollup                           # yesterday's traffic into the charts
php cdn verify --fix                     # records against the disk, recompute counters
php cdn stats days=7
php cdn translate lang=de|all             # machine-translate the interface</code></pre>

    <p>Housekeeping runs hourly from cron:</p>
    <pre><code>0 * * * * php /path/to/cron/cdn.php</code></pre>

    <div class="note">
        Without it: the charts stay empty, deleted files keep taking up disk, and the request log grows
        forever.
    </div>
</section>
