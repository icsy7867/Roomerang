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

The database is `/app/data/database.sql`. On startup the container creates it when missing, applies the bundled schema in `database/schema.sql`, and refuses to start if SQLite's integrity check fails. The image uses no external runtime services.

## Notes

The entire container runs as `paste` (UID/GID `1000:1000`), including Apache, PHP, initialization, and health checks. Apache listens on container port **8080**. Kubernetes Services should target port 8080 and may set `runAsNonRoot: true`, `runAsUser: 1000`, and `runAsGroup: 1000`.

Mounted data directories and existing database files (including SQLite WAL/SHM files) must be writable by UID/GID 1000. Existing volumes from older images may be owned by `www-data` (33); update their ownership to `1000:1000` once before upgrading. Startup no longer performs privileged ownership changes. For rootless Podman, use its user-namespace mapping when preparing volume ownership. Fresh named volumes inherit the image's data-directory ownership.

- Room URLs contain 256 bits of cryptographically random entropy.
- Rooms expire at their selected duration (ten minutes through one month) and expired rooms are inaccessible.
- Set `MAXIMUM_DURATION` to `10m`, `30m`, `1h`, `12h`, `1d`, `1w`, or `1m`/`1mo` to limit which expiry choices are offered and accepted. For example, `MAXIMUM_DURATION=1w` prevents rooms longer than one week.
- Identity is stored in a secure, HTTP-only browser session cookie. A person selects a display name once per room.
- “From joining” rooms hide messages written before a member joined.
