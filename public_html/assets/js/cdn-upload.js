/**
 * Browser client for the CDN's resumable upload API.
 *
 * A single POST is fine until the file is large or the connection is not - then
 * one dropped packet at 90% costs the whole transfer. This splits the file, and
 * a resume asks the server what it already has rather than starting again.
 *
 *   const cdn = new CdnUploader({
 *       endpoint: '/api/cdn/v1',
 *       key:      'cdn_xxx',
 *       secret:   'yyy',
 *       bucket:   'assets',
 *   });
 *
 *   cdn.upload(file, {
 *       path:       'videos/intro.mp4',
 *       onProgress: (sent, total) => console.log(Math.round(sent / total * 100) + '%'),
 *   }).then(response => console.log(response.file.url));
 *
 * The key and secret belong to a server, not to a page - anything in the
 * browser is readable by whoever loads it. For a browser upload, issue an
 * upload-scoped key bound to one bucket, or better, have your own backend open
 * the session and hand the client only the upload id.
 */
class CdnUploader {
    constructor(options) {
        this.endpoint = (options.endpoint || '/api/cdn/v1').replace(/\/$/, '');
        this.key = options.key;
        this.secret = options.secret;
        this.bucket = options.bucket;

        // Under this, a single POST is simply cheaper - one round trip instead
        // of an open, n chunks and a complete.
        this.threshold = options.threshold || 8 * 1024 * 1024;
        this.retries = options.retries || 3;
    }

    headers(extra = {}) {
        return Object.assign({
            'X-Cdn-Key': this.key,
            'X-Cdn-Secret': this.secret,
        }, extra);
    }

    async upload(file, options = {}) {
        return file.size > this.threshold
            ? this.resumable(file, options)
            : this.direct(file, options);
    }

    async direct(file, options) {
        const body = new FormData();

        body.append('file', file);
        body.append('bucket', options.bucket || this.bucket);
        if (options.path) body.append('path', options.path);
        if (options.tags) body.append('tags', [].concat(options.tags).join(','));

        const response = await fetch(`${this.endpoint}/files`, {
            method: 'POST',
            headers: this.headers(),
            body,
        });

        const payload = await response.json();
        if (!response.ok || !payload.ok) throw new Error(payload.error || `http-${response.status}`);

        if (options.onProgress) options.onProgress(file.size, file.size);

        return { ok: true, file: payload.files[0] };
    }

    async resumable(file, options) {
        const session = await this.request('POST', '/uploads', {
            bucket: options.bucket || this.bucket,
            path: options.path,
            name: file.name,
            size: file.size,
            mime: file.type,
        });

        if (!session.ok) throw new Error(session.error || 'begin-failed');

        const id = session.upload.id;
        const chunkSize = session.upload.chunk_size;
        const chunks = Math.ceil(file.size / chunkSize);

        let sent = 0;

        for (let index = 0; index < chunks; index++) {
            const slice = file.slice(index * chunkSize, Math.min((index + 1) * chunkSize, file.size));

            // Each chunk is retried on its own. A network blip costs one chunk,
            // not the upload - which is the entire reason for doing it this way.
            await this.withRetries(() => fetch(`${this.endpoint}/uploads/${id}?index=${index}`, {
                method: 'PUT',
                headers: this.headers({ 'Content-Type': 'application/octet-stream' }),
                body: slice,
            }).then(response => {
                if (!response.ok) throw new Error(`chunk-${index}-http-${response.status}`);
                return response.json();
            }));

            sent += slice.size;
            if (options.onProgress) options.onProgress(sent, file.size);
        }

        const completed = await this.request('POST', `/uploads/${id}/complete`, {});
        if (!completed.ok) throw new Error(completed.error || 'complete-failed');

        return completed;
    }

    async abort(id) {
        return this.request('DELETE', `/uploads/${id}`, {});
    }

    async request(method, path, body) {
        const response = await fetch(this.endpoint + path, {
            method,
            headers: this.headers({ 'Content-Type': 'application/json' }),
            body: method === 'GET' ? undefined : JSON.stringify(body),
        });

        return response.json();
    }

    async withRetries(attempt) {
        let error;

        for (let tries = 0; tries < this.retries; tries++) {
            try {
                return await attempt();
            } catch (thrown) {
                error = thrown;
                // Backing off rather than hammering: whatever failed is often
                // still failing a moment later.
                await new Promise(resolve => setTimeout(resolve, 500 * Math.pow(2, tries)));
            }
        }

        throw error;
    }
}

if (typeof module !== 'undefined' && module.exports) module.exports = CdnUploader;
