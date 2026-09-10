# paste-chat

A disposable, link-only chat room for sharing Markdown and code snippets. It runs as one PHP/Apache container and uses SQLite for persistent room/message state.

## Run

```sh
docker compose up --build
```

Open `http://localhost:8080`. To retain data with plain Docker or Podman, mount a writable volume at `/app/data`:

```sh
docker run --rm -p 8080:8080 -v paste-chat-data:/app/data paste-chat
```

When building with Podman, use the Docker image format so the Dockerfile health check is retained. Podman’s default OCI format emits a warning and drops `HEALTHCHECK` metadata:

```sh
podman build --format docker -t paste-chat:local .
# or: make build
```

Use `make build IMAGE=roomerang:dev` to choose a different local tag.

The database is `/app/data/database.sql`. On startup the container creates it when missing, applies the bundled schema in `database/schema.sql`, and refuses to start if SQLite's integrity check fails. The image uses no external runtime services.

## Notes

## Client-side encryption

All new rooms use AES-256-GCM in the browser. The 32-byte key is carried in the URL fragment (`#...`) and never included in API requests. Share the **complete** link. HTTPS is required except on localhost; missing or incorrect keys cannot be recovered by the server.

Text, images, and files share the same encrypted binary envelope. Filenames and MIME types are inside that envelope. Files are limited to 8 MiB; uploads are currently whole-file, with a versioned envelope and separate transport functions for future chunking. Downloaded files are not executed or embedded; only PNG/JPEG/GIF/WebP are rendered as images.

The server sees room/session identifiers, display names, timestamps, ciphertext lengths, and traffic patterns. This shared-room-key model does not authenticate individual senders cryptographically. History visibility is server-enforced; anyone possessing the key can decrypt older ciphertext if they obtain it. The served JavaScript must be trusted: a compromised server can change the client. There is no external analytics or CDN dependency.

Pre-release plaintext rooms are unavailable through the new API, including their old media URLs. Their existing rows are not converted or erased automatically; they are pruned through the existing expiry cleanup. Only encrypted rooms can be created by the web application.

Validation: `podman run --rm --entrypoint php paste-chat:local /app/tests/encrypted-storage.php`.

### Encryption wire format

Room creation generates the server-visible room token and a separate 32-byte AES key in the browser. The application redirects to `/chat/{token}#{key}`; the origin comes from the current request, so a deployment at `https://paste-chat.io` produces links on that host rather than hard-coding localhost. The fragment is never sent in an HTTP request.

`public/assets/crypto.js` uses a versioned binary envelope: a four-byte big-endian metadata length, UTF-8 JSON metadata (`v`, `kind`, `mime`, and `name`), then the content bytes encrypted with AES-256-GCM. The 12-byte nonce is prepended to the encrypted envelope for the message POST. AES-GCM additional authenticated data binds the room token to the ciphertext. The API stores only the nonce/ciphertext BLOB and clear operational metadata such as sender display name, timestamp, and length.

The current 8 MiB limit applies to the original content before encryption. Text is UTF-8 encoded; images and other files use their raw bytes. Filename and MIME metadata are encrypted inside the envelope. Whole-file encryption is intentional for now; the envelope and transport helpers are versioned so a future chunked format can be added without treating ciphertext as text. Shared images and PDFs open a larger local preview using the browser `FileReader` API. PDFs use a temporary local `blob:` URL so the browser’s built-in PDF viewer can render them; the URL is revoked when the preview closes.

The entire container runs as `paste` (UID/GID `1000:1000`), including Apache, PHP, initialization, and health checks. Apache listens on container port **8080**. Kubernetes Services should target port 8080 and may set `runAsNonRoot: true`, `runAsUser: 1000`, and `runAsGroup: 1000`.

Mounted data directories and existing database files (including SQLite WAL/SHM files) must be writable by UID/GID 1000. Existing volumes from older images may be owned by `www-data` (33); update their ownership to `1000:1000` once before upgrading. Startup no longer performs privileged ownership changes. For rootless Podman, use its user-namespace mapping when preparing volume ownership. Fresh named volumes inherit the image's data-directory ownership.

When placing the container behind a reverse proxy, configure its request-body limit above the application’s 8 MiB file limit. For NGINX, `client_max_body_size 9m;` accommodates the encrypted envelope and AES-GCM overhead. If the proxy rejects an upload with HTTP 413, the browser reports the proxy limit clearly instead of attempting to parse its HTML error page.

- Room URLs contain 256 bits of cryptographically random entropy.
- Rooms expire at their selected duration (ten minutes through one month) and expired rooms are inaccessible.
- Set `MAXIMUM_DURATION` to `10m`, `30m`, `1h`, `12h`, `1d`, `1w`, or `1m`/`1mo` to limit which expiry choices are offered and accepted. For example, `MAXIMUM_DURATION=1w` prevents rooms longer than one week.
- Identity is stored in a secure, HTTP-only browser session cookie. A person selects a display name once per room.
- “From joining” rooms hide messages written before a member joined.
- A room key is a shared capability: anyone with the complete URL can decrypt the room, and the server cannot recover it. This protects stored/network content from the application server but does not provide cryptographic sender authentication.
- The first session that joins becomes the room Master. The Master can enable read-only mode (Master messages still work), inspect sessions active within the last five minutes, kick a session so it may rejoin, or block a session until the room expires.
