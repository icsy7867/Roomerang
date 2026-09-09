# paste-chat contributor guide

This repository is the complete source for the `paste-chat` single-container web application. The repository root is copied to `/app` in the container. The public web root is `/app/public`; persistent runtime data belongs in `/app/data`.

## Project purpose

paste-chat is a temporary, link-only chat application. A visitor creates a room with an expiry and a history visibility mode, then shares the generated URL. Visitors identify themselves through a secure browser session cookie and choose a display name for each room. Rooms expire after at most one month.

The application is intentionally self-contained: PHP, Apache, browser JavaScript, CSS, and SQLite are all in this repository. Do not add a required external service or runtime CDN dependency without an explicit product decision.

## Repository structure

```text
Dockerfile                 PHP/Apache image definition
compose.yaml               Local Docker Compose deployment
docker-entrypoint.sh       Database initialization and ownership setup
docker/uploads.ini         PHP upload limits for image sharing
database/schema.sql        Idempotent SQLite schema template
scripts/init.php           Startup schema application and integrity check
scripts/healthcheck.php    SQLite PRAGMA integrity_check health command
src/bootstrap.php          App autoloader and shared bootstrap
src/Database.php           PDO SQLite connection configuration
src/Http.php               JSON responses, input parsing, session cookie handling
src/ChatService.php        Room, member, message, and image domain operations
public/index.php           Front controller, API routes, and HTML shell
public/.htaccess            Apache rewrite to the front controller
public/assets/app.js       Chat behavior, polling, Markdown rendering, uploads
public/assets/app.css      Main application styling
public/assets/messages.css Message alignment, upload/drop, and form overrides
public/assets/unavailable.css Expired/invalid room page styling
README.md                  Operator setup and deployment notes
```

There are currently no Composer or npm dependencies. Keep the browser-side Markdown renderer and syntax highlighting safe when changing them: message text must be escaped before being inserted into HTML, and user-provided links must be protocol-validated.

## Runtime and deployment

Build and run locally with:

```sh
docker compose up --build
```

The image uses PHP 8.3 with Apache, `pdo_sqlite`, and `mbstring`. All runtime processes use `paste` (UID/GID 1000), including startup and health checks. Apache listens on port 8080. Ownership of application/configuration and Apache runtime directories is set during the image build. The entrypoint initializes SQLite and starts Apache without runtime chown or root privileges. Mounted data must already be writable by UID/GID 1000; older www-data-owned volumes need an operator-managed ownership migration.

The database path is controlled by `PASTE_CHAT_DB` and defaults to `/app/data/database.sql`. `MAXIMUM_DURATION` controls the longest duration a new room can request; it accepts `10m`, `30m`, `1h`, `12h`, `1d`, `1w`, and `1m`/`1mo` (month). The data directory must be writable by the Apache worker. The database file is deliberately not checked into source control; the schema template is the source of truth for fresh installations.

Docker's image health check runs `scripts/healthcheck.php`. Podman may ignore OCI health-check metadata depending on the image format, but startup initialization still validates SQLite before Apache starts.

## Request flow and API

`public/index.php` is the only PHP web entry point. Apache rewrites non-file paths to it.

Important routes:

- `POST /api/chats` creates a room. JSON fields are `duration` (`10m`, `30m`, `1h`, `12h`, `1d`, `1w`, or `1mo`) and `history_mode` (`all` or `since_join`).
- `POST /api/chats/{token}/join` creates or reuses the current session's membership and returns visible messages.
- `GET /api/chats/{token}/messages?after={id}` returns messages visible to the current member for polling.
- `POST /api/chats/{token}/messages` posts Markdown/plain text JSON.
- `POST /api/chats/{token}/images` accepts multipart field `image` and posts an image message.
- `GET /media/{image-token}` serves an image only while its parent room is active.

Room tokens and image tokens are URL-safe encodings of 32 random bytes. Do not replace them with sequential IDs, short slugs, timestamps, or user-controlled values.

## Data model and schema changes

SQLite tables are `rooms`, `members`, `messages`, and `images`. Foreign keys are enabled and room deletion cascades through members, messages, and images. Images are stored as BLOBs in SQLite so deployment remains single-container.

`database/schema.sql` uses `CREATE TABLE IF NOT EXISTS`, so it is safe to apply at startup for new databases. Existing database changes need an explicit migration in `scripts/init.php`; adding a new table is usually enough with the current idempotent schema, but adding or changing columns requires checking `PRAGMA table_info(...)` and applying a guarded `ALTER TABLE`. Never assume that a persistent `/app/data/database.sql` is empty.

Expired rooms are inaccessible immediately and are pruned during startup and room creation. Image serving also checks the parent room expiry.

## Frontend behavior

`app.js` opens a room by checking the message API before showing the join dialog. Invalid or expired URLs show the unavailable-room panel. Active rooms poll every 2.5 seconds. The current member's messages receive `is_mine` from the server and are styled slightly right of other messages.

The message pane is the scrollable grid row. Preserve `height: 100dvh`, `min-height: 0` on `#chat`, and `min-height: 0`/overflow behavior on `#messages` when changing layout. The composer sends on Enter; Shift+Enter creates a newline. Pasting or dropping an image uploads it immediately and adds an image message.

Image uploads are limited by both browser checks and server checks to 8 MB. The server validates the actual MIME type and accepts PNG, JPEG, GIF, and WebP only. Do not trust a filename or browser-provided MIME type for validation.

## Coding and security conventions

- Keep domain/database logic in `src/ChatService.php`; keep routing and response formatting in `public/index.php`.
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
