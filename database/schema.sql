PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS rooms (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token TEXT NOT NULL UNIQUE,
  expires_at INTEGER NOT NULL,
  history_mode TEXT NOT NULL CHECK (history_mode IN ('all', 'since_join')),
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS members (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  room_id INTEGER NOT NULL,
  session_hash TEXT NOT NULL,
  display_name TEXT NOT NULL,
  joined_at INTEGER NOT NULL,
  UNIQUE(room_id, session_hash),
  FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  room_id INTEGER NOT NULL,
  member_id INTEGER NOT NULL,
  body TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE,
  FOREIGN KEY(member_id) REFERENCES members(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS images (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  message_id INTEGER NOT NULL UNIQUE,
  token TEXT NOT NULL UNIQUE,
  mime_type TEXT NOT NULL,
  contents BLOB NOT NULL,
  size_bytes INTEGER NOT NULL,
  created_at INTEGER NOT NULL,
  FOREIGN KEY(message_id) REFERENCES messages(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_messages_room_id ON messages(room_id, id);
CREATE INDEX IF NOT EXISTS idx_rooms_expires_at ON rooms(expires_at);

-- Versioned encrypted rooms are separate from any pre-release plaintext rooms.
CREATE TABLE IF NOT EXISTS encrypted_rooms (
  room_id INTEGER PRIMARY KEY REFERENCES rooms(id) ON DELETE CASCADE,
  master_session_hash TEXT,
  read_only INTEGER NOT NULL DEFAULT 0 CHECK (read_only IN (0,1))
);
CREATE TABLE IF NOT EXISTS encrypted_members (
  room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
  session_hash TEXT NOT NULL,
  display_name TEXT NOT NULL,
  after_id INTEGER NOT NULL,
  blocked INTEGER NOT NULL DEFAULT 0 CHECK (blocked IN (0,1)),
  last_seen_at INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY(room_id, session_hash)
);
CREATE TABLE IF NOT EXISTS encrypted_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
  session_hash TEXT NOT NULL,
  nonce BLOB NOT NULL,
  ciphertext BLOB NOT NULL,
  created_at INTEGER NOT NULL,
  UNIQUE(room_id, nonce)
);
CREATE INDEX IF NOT EXISTS idx_encrypted_messages_room ON encrypted_messages(room_id, id);

-- An opaque bearer token can expose one encrypted message while its room remains active.
-- The AES key stays in the URL fragment and is never stored here.
CREATE TABLE IF NOT EXISTS encrypted_message_shares (
  token TEXT PRIMARY KEY,
  message_id INTEGER NOT NULL UNIQUE REFERENCES encrypted_messages(id) ON DELETE CASCADE,
  display_name TEXT NOT NULL,
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS system_settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
INSERT OR IGNORE INTO system_settings(key, value) VALUES ('maintenance_mode', '0');

CREATE TABLE IF NOT EXISTS admin_sessions (
  session_hash TEXT PRIMARY KEY,
  csrf_token TEXT NOT NULL,
  expires_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_admin_sessions_expires_at ON admin_sessions(expires_at);
