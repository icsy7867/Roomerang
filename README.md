# Roomerang

A disposable, link-only chat room for sharing Markdown and code snippets. It runs as one PHP/Apache container and uses SQLite for persistent room/message state.

## Run

```sh
docker compose up --build
```

Open `http://localhost:8080`. To retain data with plain Docker or Podman, mount a writable volume at `/app/data`:

```sh
docker run --rm -p 8080:8080 -v roomerang-data:/app/data roomerang
```

When building with Podman, use the Docker image format so the Dockerfile health check is retained. Podman’s default OCI format emits a warning and drops `HEALTHCHECK` metadata:

```sh
podman build --format docker -t roomerang:local .
# or: make build
```

Use `make build IMAGE=roomerang:dev` to choose a different local tag.

The database is `/app/data/database.sql`; encrypted attachment payloads are stored separately under `/app/data/files`. On startup the container creates the database when missing, applies the bundled schema in `database/schema.sql`, and refuses to start if SQLite's integrity check fails. The image uses no external runtime services.

## Environment variables

Set these when starting the container or under `environment:` in Compose:

| Variable | Default | Purpose |
| --- | --- | --- |
| `PASTE_CHAT_DB` | `/app/data/database.sql` | SQLite database path. Its parent directory must be writable by UID/GID 1000. |
| `PASTE_CHAT_MAX_MESSAGE_LENGTH` | `12000` | Maximum character count for a text message. |
| `SQLITE_JOURNAL_MODE` | `DELETE` | SQLite journal mode: use `DELETE` for EFS/NFS, or explicitly set `WAL` for local filesystems. |
| `MAXIMUM_DURATION` | `1mo` | Longest duration offered and accepted for new rooms: `10m`, `30m`, `1h`, `12h`, `1d`, `1w`, `1m`, or `1mo`. |
| `DEFAULT_DURATION` | `1h` | Initially selected duration on the landing page. It accepts the same values as `MAXIMUM_DURATION` and must not exceed that maximum. Invalid or disallowed values fall back to one hour when available, otherwise the longest allowed duration. |
| `ADMIN_PASSWORD` | unset | Enables the password-protected `/admin` operator console. Use a long random value and set it only through deployment secrets. |
| `ADMIN_PASSWORD_HASH` | unset | Preferred alternative to `ADMIN_PASSWORD`: a PHP `password_hash()` value. When both are set, this value is used. |

For example, to default new rooms to one day while allowing rooms up to a week:

```sh
docker run --rm -p 8080:8080 \
  -e MAXIMUM_DURATION=1w \
  -e DEFAULT_DURATION=1d \
  -v roomerang-data:/app/data roomerang
```

## Administration and backups

Set `ADMIN_PASSWORD` or `ADMIN_PASSWORD_HASH` to enable `/admin`; without either, the route returns 404. The console reports total, active, expired, and empty chat counts plus maximum and average message counts for active chats. It also exposes integrity status, expiry pruning, a global maintenance mode, and a consistent backup archive download.

Maintenance mode leaves existing joined members able to read rooms but prevents room creation, new joins, messages, and room moderation. Enable it before downloading a backup. Room expiry remains enforced while maintenance mode is on.

The Admin backup is a `.tar` archive containing `database.sqlite` and every referenced encrypted attachment under `files/`. It verifies each attachment's size and SHA-256 before including it, and refuses to create a partial backup. Store the archive outside EFS.

Restore is deliberately an operator procedure rather than a browser upload. Enable maintenance, stop every application container using the data volume, unpack a verified archive into a temporary directory, then replace both `/app/data/database.sql` with `database.sqlite` and `/app/data/files` with the archive's `files` directory. Remove any stale `database.sql-wal` and `database.sql-shm` files, then start the application. The startup integrity check will refuse a corrupt database, and startup removes orphaned attachment files left by an interrupted upload.

For a development/beta recovery where preserving chats is not required, the Admin console provides a destructive **Reset database** control. It requires typing `RESET ALL CHATS`, permanently removes all chat and admin data plus SQLite sidecars, and recreates the database with the bundled schema. Use it only while one application task has access to the database volume; it is not a repair mechanism for production data.

If startup detects SQLite corruption, the container remains running in database-integrity maintenance mode. Chat APIs return `503`, the landing page disables new-chat creation, and `/admin` remains available with an emergency password-authenticated session so an operator can reset the beta database or attempt a backup. The container health check stays runnable in this state to prevent an orchestrator restart loop.

## Notes

## Client-side encryption

Joining a room loads the latest 25 messages first. The browser downloads and decrypts up to four messages concurrently, preserving chronological order with loading placeholders. Scroll upward to retrieve older history in pages of 25. Live polling runs independently of those downloads, and confirmed outgoing messages render from their local content without a second download. History pages enforce the same membership and join boundaries as individual message downloads.

All new rooms use AES-256-GCM in the browser. The 32-byte key is carried in the URL fragment (`#...`) and never included in API requests. Share the **complete** link. HTTPS is required except on localhost; missing or incorrect keys cannot be recovered by the server.

Text, images, and files share encrypted binary envelopes. An attachment is staged in the composer and is sent only when Send is pressed; a message can contain text, an attachment, or both. Filenames and MIME types are inside the encrypted envelope. The combined text-and-file payload is limited to 8 MiB; uploads are currently whole-file, with a versioned envelope and separate transport functions for future chunking. Attachment ciphertext is streamed to opaque, random-keyed files under `/app/data/files`, while SQLite retains only the message metadata and the file reference. The files are outside the public web root and are served only after the same room, member, expiry, and history checks as database-backed messages. Downloaded files are not executed or embedded; only PNG/JPEG/GIF/WebP are rendered as images.

The server sees room/session identifiers, display names, timestamps, ciphertext lengths, and traffic patterns. This shared-room-key model does not authenticate individual senders cryptographically. History visibility is server-enforced; anyone possessing the key can decrypt older ciphertext if they obtain it. The served JavaScript must be trusted: a compromised server can change the client. There is no external analytics or CDN dependency.

The message composer uses a vendored EasyMDE build for Markdown-aware editing and syntax highlighting; it does not load editor code from a runtime CDN.

Each message can be shared as a separate read-only link. The server stores only a random share token and the message association; the room AES key stays in the URL fragment. A shared link reveals only that message's encrypted bytes, remains valid only while its parent chat is active, and renders Markdown/code safely in a read-only view.

Pre-release plaintext rooms are unavailable through the new API, including their old media URLs. Their existing rows are not converted or erased automatically; they are pruned through the existing expiry cleanup. Only encrypted rooms can be created by the web application.

Validation: `podman run --rm --entrypoint php paste-chat:local /app/tests/encrypted-storage.php`.

### Encryption wire format

Room creation generates the server-visible room token and a separate 32-byte AES key in the browser. The application redirects to `/chat/{token}#{key}`; the origin comes from the current request, so a deployment at `https://paste-chat.io` produces links on that host rather than hard-coding localhost. The fragment is never sent in an HTTP request.

`public/assets/crypto.js` uses a versioned binary envelope: a four-byte big-endian metadata length, UTF-8 JSON metadata (`v`, `kind`, `mime`, and `name`), then the content bytes encrypted with AES-256-GCM. Version 1 stores text-only or file-only content. Version 2 stores an attachment's encrypted text-byte boundary (`text_bytes`) followed by text and file bytes, so a single message can render text above its attachment. The 12-byte nonce is prepended to the encrypted envelope for the message POST. AES-GCM additional authenticated data binds the room token to the ciphertext. Text messages remain nonce/ciphertext BLOBs in SQLite; attachment ciphertext remains the same opaque wire format in files, while SQLite records its random object key, byte count, backup-verification hash, and clear operational metadata such as sender display name and timestamp.

The current 8 MiB limit applies to the full plaintext payload before encryption: for an attachment message, that is the UTF-8 text plus the raw file bytes. Filename and MIME metadata are encrypted inside the envelope. Whole-file encryption is intentional for now; the envelope and transport helpers are versioned so a future chunked format can be added without treating ciphertext as text. Shared images and PDFs open a larger local preview using the browser `FileReader` API. PDFs use a temporary local `blob:` URL so the browser’s built-in PDF viewer can render them; the URL is revoked when the preview closes.

The entire container runs as `paste` (UID/GID `1000:1000`), including Apache, PHP, initialization, and health checks. Apache listens on container port **8080**. Kubernetes Services should target port 8080 and may set `runAsNonRoot: true`, `runAsUser: 1000`, and `runAsGroup: 1000`.

Mounted data directories and existing database files (including SQLite WAL/SHM files) must be writable by UID/GID 1000. Existing volumes from older images may be owned by `www-data` (33); update their ownership to `1000:1000` once before upgrading. Startup no longer performs privileged ownership changes. For rootless Podman, use its user-namespace mapping when preparing volume ownership. Fresh named volumes inherit the image's data-directory ownership.

### SQLite on network storage

Chat presence timestamps are refreshed at most once every 30 seconds per member during normal polling. The recurring health check reads one application-table row; full integrity scans remain at startup and in Admin Database Care.

Attachment POST responses expose `Server-Timing` durations in milliseconds for `db-connect`, `room-lookup`, `member-lookup`, `presence`, `post-authorize`, `attachment-read`, `attachment-write`, `attachment-close`, `attachment-promote`, `attachment-db-write`, and `attachment-db-commit`. `attachment-stage` and `attachment-finalize` are aggregate durations; do not add them to their component timings. PHP input reads can include waiting for incoming bytes; proxy buffering and browser encryption occur outside these measurements. Inspect the POST response headers alongside the browser's total request timing. Message downloads expose database and content-lookup timings before the file stream begins. Timings contain no keys, room identifiers, or payloads.

Roomerang defaults to SQLite rollback journaling (`SQLITE_JOURNAL_MODE=DELETE`). This avoids WAL's shared-memory sidecar, which is incompatible with network filesystems such as NFS/EFS. Rollback journaling reduces concurrent read/write capacity, so the application waits up to ten seconds for a short-lived database lock and uses `synchronous=NORMAL` to reduce network-storage sync latency. This accepts a small risk to the most recent transaction during an infrastructure crash; retain verified backups outside EFS.

For a deployment whose `/app/data` directory is on a real local filesystem, set `SQLITE_JOURNAL_MODE=WAL` to restore WAL's higher read/write concurrency. Never enable WAL when the database or its sidecars reside on EFS, NFS, or another network filesystem. Roomerang checks the persistent journal mode on each connection but performs a mode transition only when the configured value differs, so normal polling does not repeatedly renegotiate it.

Network storage remains a temporary compatibility measure rather than an ideal SQLite production backend. If deploying a single ECS/Fargate task with EFS, keep the service at a desired count of one and use an ECS rolling deployment with `minimumHealthyPercent: 0` and `maximumPercent: 100`. This intentionally permits a short outage so ECS stops the previous task before starting the replacement; no two tasks may mount and access the same SQLite database. Do not use this configuration with a blue/green deployment controller. Take a verified Admin backup before each deployment and retain it outside EFS.

When placing the container behind a reverse proxy, configure its request-body limit above the application’s 8 MiB file limit. For NGINX, `client_max_body_size 9m;` accommodates the encrypted envelope and AES-GCM overhead. If the proxy rejects an upload with HTTP 413, the browser reports the proxy limit clearly instead of attempting to parse its HTML error page.

If an AWS WAF web ACL is attached to the Application Load Balancer, scope body-inspection rules away from `POST /api/chats/*/messages`. The body is authenticated ciphertext and cannot be meaningfully inspected for application threats; random ciphertext can also match content signatures by chance. Keep URI, header, rate-limit, and other applicable protections enabled. ALB access logs identify WAF rejections with a `403` load-balancer status and `waf` in `actions_executed`; Roomerang marks responses that reached the application with `X-Roomerang-Response: app`.

- Room URLs contain 256 bits of cryptographically random entropy.
- Rooms expire at their selected duration (ten minutes through one month) and expired rooms are inaccessible.
- Set `MAXIMUM_DURATION` to `10m`, `30m`, `1h`, `12h`, `1d`, `1w`, or `1m`/`1mo` to limit which expiry choices are offered and accepted. For example, `MAXIMUM_DURATION=1w` prevents rooms longer than one week.
- Identity is stored in a secure, HTTP-only browser session cookie. A person selects a display name once per room.
- “From joining” rooms hide messages written before a member joined.
- A room key is a shared capability: anyone with the complete URL can decrypt the room, and the server cannot recover it. This protects stored/network content from the application server but does not provide cryptographic sender authentication.
- The first session that joins becomes the room Master. The Master can enable read-only mode (Master messages still work), inspect sessions active within the last five minutes, kick a session so it may rejoin, or block a session until the room expires.
