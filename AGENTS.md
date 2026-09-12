# Roomerang contributor guide

This repository is the complete source for the `paste-chat` single-container web application. The repository root is copied to `/app` in the container. The public web root is `/app/public`; persistent runtime data belongs in `/app/data`.

## Project purpose

Roomerang is a temporary, link-only chat application. A visitor creates a room with an expiry and a history visibility mode, then shares the generated URL. Visitors identify themselves through a secure browser session cookie and choose a display name for each room. Rooms expire after at most one month.

The application is intentionally self-contained: PHP, Apache, browser JavaScript, CSS, and SQLite are all in this repository. Do not add a required external service or runtime CDN dependency without an explicit product decision.

## Repository structure

```text
Dockerfile                 PHP/Apache image definition
compose.yaml               Local Docker Compose deployment
docker-entrypoint.sh       Database initialization and ownership setup
docker/uploads.ini         PHP upload limits for encrypted file sharing
database/schema.sql        Idempotent SQLite schema template
images/roomerang.svg       Source vector logo; Dockerfile copies it into the public asset directory
images/roomerang.png       Raster source logo retained for design/export use
scripts/init.php           Startup schema application and integrity check
scripts/healthcheck.php    SQLite PRAGMA integrity_check health command
src/bootstrap.php          App autoloader and shared bootstrap
src/Database.php           PDO SQLite connection configuration
src/Http.php               JSON responses, input parsing, session cookie handling
src/ChatService.php        Legacy plaintext room/member/message/image operations retained for cleanup/schema compatibility
src/EncryptedChatService.php Active encrypted room/member/message storage, size limits, expiry, and history checks
public/assets/crypto.js    Versioned binary envelope, AES-GCM, and encrypted transport
public/index.php           Front controller, API routes, and HTML shell
public/.htaccess            Apache rewrite to the front controller
public/assets/app.js       Chat behavior, polling, Markdown rendering, uploads
public/assets/app.css      Main application styling
public/assets/messages.css Message alignment, upload/drop, and form overrides
public/assets/easymde.min.js  Vendored EasyMDE Markdown composer
public/assets/easymde.min.css Vendored EasyMDE composer styling
public/assets/unavailable.css Expired/invalid room page styling
README.md                  Operator setup and deployment notes
```

There are currently no Composer or npm build dependencies; the browser-side EasyMDE release is vendored in `public/assets`. Keep the browser-side Markdown renderer, composer, and syntax highlighting safe when changing them: message text must be escaped before being inserted into HTML, and user-provided links must be protocol-validated. Do not add a CDN dependency for cryptography; Web Crypto API support is required.

## Runtime and deployment

Build and run locally with:

```sh
docker compose up --build
```

For direct Podman builds, use `podman build --format docker -t paste-chat:local .` or `make build`. Podman’s default OCI image format does not support Dockerfile `HEALTHCHECK` metadata and emits a warning while dropping it; this is a build-format limitation, not a failed health check.

The image uses PHP 8.3 with Apache, `pdo_sqlite`, and `mbstring`. All runtime processes use `paste` (UID/GID 1000), including startup and health checks. Apache listens on port 8080. Ownership of application/configuration and Apache runtime directories is set during the image build. The entrypoint initializes SQLite and starts Apache without runtime chown or root privileges. Mounted data must already be writable by UID/GID 1000; older www-data-owned volumes need an operator-managed ownership migration.

If deployment uses a reverse proxy, its request-body size must exceed the 8 MiB original-file limit because encrypted uploads add a small envelope and AES-GCM tag. NGINX should use at least `client_max_body_size 9m;`. Proxies can return HTML for HTTP 413; `crypto.js` must never call `response.json()` blindly for upload failures.

The database path is controlled by `PASTE_CHAT_DB` and defaults to `/app/data/database.sql`. `MAXIMUM_DURATION` controls the longest duration a new room can request; it accepts `10m`, `30m`, `1h`, `12h`, `1d`, `1w`, and `1m`/`1mo` (month). `DEFAULT_DURATION` chooses the initially selected landing-page duration and must be one of the durations still allowed by that maximum; it defaults to `1h`, then falls back to the longest allowed choice when needed. The data directory must be writable by the Apache worker. The database file is deliberately not checked into source control; the schema template is the source of truth for fresh installations.

Docker's image health check runs `scripts/healthcheck.php`. Podman may ignore OCI health-check metadata depending on the image format, but startup initialization still validates SQLite before Apache starts.

## Request flow and API

`public/index.php` is the only PHP web entry point. Apache rewrites non-file paths to it. The active web API is encrypted-only; the older plaintext service and media routes are not a supported way to create or read new messages.

Important routes:

- `POST /api/chats` creates a room. JSON fields are `duration` (`10m`, `30m`, `1h`, `12h`, `1d`, `1w`, or `1mo`) and `history_mode` (`all` or `since_join`).
- `POST /api/chats/{token}/join` creates or reuses the current session's membership and returns the latest visible 25-message page.
- `GET /api/chats/{token}/messages` returns the latest visible 25-message page; `?after={id}` returns newer messages for polling; `?before={id}` returns the preceding visible 25-message page. Each result is chronological even when selected backward.
- `POST /api/chats/{token}/messages` accepts `application/octet-stream`: 12-byte nonce followed by ciphertext (including the GCM tag). The request body is opaque to PHP; content type and filename are not trusted from request metadata.
- `GET /api/chats/{token}/messages/{id}` serves those bytes after membership, expiry, and history checks. Polling returns metadata only.
- `POST /api/chats/{token}/messages/{id}` creates or reuses an opaque share token for a message visible to the current member.
- `GET /api/shares/{share_token}` returns metadata for one active shared message; `GET /api/shares/{share_token}/content` serves only its encrypted bytes. The public `/share/{share_token}#{room_key}` view is read-only.
- Legacy plaintext message/image routes are disabled. Encrypted rooms are identified by the `encrypted_rooms` table; old rows are retained until expiry cleanup.

Moderation routes are available to the Master member only:

- `GET /api/chats/{token}/moderation` lists sessions seen in the last five minutes. The API exposes a short opaque session label, never the browser cookie or its stored hash.
- `POST /api/chats/{token}/moderation/read-only` accepts `{ "enabled": true|false }`.
- `POST /api/chats/{token}/moderation/members/{session_label}/kick` removes a member and permits a later rejoin.
- `POST /api/chats/{token}/moderation/members/{session_label}/block` marks a member blocked until room expiry.

The first successful `join` inserts `encrypted_rooms.master_session_hash`; subsequent joins cannot claim Master status. `read_only`, `blocked`, and `last_seen_at` are server-side control metadata and are not part of the encrypted message payload.

Room and message-share tokens are URL-safe encodings of 32 random bytes. Do not replace them with sequential IDs, short slugs, timestamps, or user-controlled values. The browser-generated AES key is also 32 random bytes and is carried only in the URL fragment. Never log, persist, or include that fragment in an API request.

## Data model and schema changes

SQLite contains legacy `rooms`, `members`, `messages`, and `images` tables plus active `encrypted_rooms`, `encrypted_members`, `encrypted_messages`, and `encrypted_message_shares` tables. Encrypted messages store opaque nonce/ciphertext BLOBs; display names, timestamps, and room membership boundaries remain operational metadata. Message shares hold only an opaque token and a message reference. Foreign keys are enabled and room deletion cascades through related rows. New code should use `EncryptedChatService`, not the legacy plaintext methods.

`database/schema.sql` uses `CREATE TABLE IF NOT EXISTS`, so it is safe to apply at startup for new databases. Existing database changes need an explicit migration in `scripts/init.php`; adding a new table is usually enough with the current idempotent schema, but adding or changing columns requires checking `PRAGMA table_info(...)` and applying a guarded `ALTER TABLE`. Never assume that a persistent `/app/data/database.sql` is empty: upgrades run against persistent deployments. The Admin reset control is an explicitly confirmed beta recovery action, not a substitute for migrations or a repair path for production data. If initialization finds corruption, it must mark database-integrity maintenance mode rather than terminating the container: chat traffic is disabled, while `/admin` uses its emergency authenticated path so an operator can recover or reset the database.

Expired rooms are inaccessible immediately and are pruned during startup and room creation. Image serving also checks the parent room expiry.

## Frontend behavior

At widths up to 1000px, header actions live in the `#chat-actions` dropdown, controlled by `#chat-menu-toggle` and `aria-expanded`. Keep the Master-only hidden attribute effective despite layout display rules. Use the real button labels; do not append alternative labels with CSS pseudo-elements. The username is an ellipsized badge with the full name/role in its title. Keep background painting non-repeating and retain browser zoom accessibility.

The room-share control (`#copy-link`) opens `#share-dialog`; its decorative boomerang has the `Throw this room` tooltip and accessible name. Populate the dialog from `location.href` and copy that complete value, including the URL fragment. The fragment contains the room key, so never reconstruct a share URL from server-visible path data or send the fragment to an API.

`app.js` opens a room by checking the message API before showing the join dialog. Invalid or expired URLs show the unavailable-room panel. A join first fetches the newest 25 messages; up to four encrypted message bodies are downloaded and decrypted concurrently while placeholders retain chronological order. Scrolling to the top requests older pages of 25 without moving the reader's viewport. Active rooms poll every 2.5 seconds independently of history downloads. The current member's messages receive `is_mine` from the server and are styled slightly right of other messages. Confirmed outgoing messages render from their local encrypted content without a second download.

The message pane is the scrollable grid row. Preserve `height: 100dvh`, `min-height: 0` on `#chat`, and `min-height: 0`/overflow behavior on `#messages` when changing layout. The composer sends on Enter; Shift+Enter creates a newline. Choosing, pasting, or dropping a file stages one pending attachment; it is sent only after Send is pressed and can be combined with text in the same message. The pending-attachment row must be hidden when no attachment is staged.

Master controls are shown only when the current member is the Master. Read-only state is reflected in the header and disables the composer/file picker for other members; the server still enforces it on every encrypted POST. If a session is kicked, polling returns to the join flow. If it is blocked, the room is shown as unavailable for that session.

Encrypted uploads allow 8 MiB content plus bounded envelope overhead. The server cannot inspect MIME types, filenames, or text: those are encrypted. The client renders only PNG/JPEG/GIF/WebP as images and uses download links for other files. Image cards remain contained; clicking an image opens a larger local preview through `FileReader` on its decrypted Blob. PDFs and text attachments receive a Preview button; PDF bytes are read with `FileReader` and placed behind a temporary local `blob:` URL for the browser’s native PDF viewer. Revoke the preview URL on dialog close and page exit. Room keys are only in the fragment and browser memory; never send them to the server. `crypto.js` uses a 4-byte big-endian JSON header length, at most 4096 metadata bytes, then content bytes. Version 1 envelopes hold text-only or file-only content; version 2 attachment envelopes hold the encrypted text-byte boundary followed by text and file bytes. AAD binds AES-GCM to `paste-chat:v1:` plus room token. Chunking is deferred; version any future wire-format change rather than silently changing the existing envelope. Display names and session identity remain unencrypted metadata.

When testing encryption, test both browser-side round trips and server-side opacity. `tests/encrypted-storage.php` covers history boundaries, backward pagination, live cursors, binary storage, expiry, size limits, and the absence of plaintext message rows. Browser tests should verify the complete URL fragment, wrong-key/tamper rejection, text, attachment-only and combined text-and-file messages, exact 8 MiB files, initial latest-page loading, and older-history scrolling. A URL without a valid fragment is unavailable; it must not fall back to a plaintext chat.

## Coding and security conventions

- Keep active encrypted domain/database logic in `src/EncryptedChatService.php`; keep legacy compatibility/cleanup logic in `src/ChatService.php`; keep routing and response formatting in `public/index.php`.
- Use prepared PDO statements for all values. Keep associative fetch mode enabled in `src/Database.php`.
- Escape user text before rendering HTML. Never interpolate raw message text into `innerHTML`.
- Validate and constrain names, message lengths, room settings, upload size, and upload MIME type server-side even if the browser validates them.
- Preserve `Http::sessionId()` behavior: the cookie contains the random session identifier and the database stores only its SHA-256 hash.
- Keep expiry enforcement in every room/message/image access path.
- Avoid logging message bodies, session identifiers, image contents, or room tokens.
- Do not use destructive git operations to resolve unrelated worktree changes.

## Validation workflow

When changing PHP, build the image and lint the entry point inside the image:

```sh
podman build -t paste-chat:local .
podman run --rm --entrypoint php paste-chat:local -l /app/public/index.php
```

For behavior changes, run the container temporarily and exercise the relevant API with `curl`. At minimum, verify room creation, joining, message retrieval, and any changed upload or expiry path. Remove temporary containers and test cookies afterward. There is no local PHP runtime guaranteed outside the container.
