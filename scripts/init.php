<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $db = App\Database::connection();
    $db->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    // Keep persistent databases from older encrypted schema versions compatible.
    $columns = static function (PDO $db, string $table): array {
        return array_column($db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    };
    if (!in_array('master_session_hash', $columns($db, 'encrypted_rooms'), true)) {
        $db->exec('ALTER TABLE encrypted_rooms ADD COLUMN master_session_hash TEXT');
    }
    if (!in_array('read_only', $columns($db, 'encrypted_rooms'), true)) {
        $db->exec('ALTER TABLE encrypted_rooms ADD COLUMN read_only INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('blocked', $columns($db, 'encrypted_members'), true)) {
        $db->exec('ALTER TABLE encrypted_members ADD COLUMN blocked INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('last_seen_at', $columns($db, 'encrypted_members'), true)) {
        $db->exec('ALTER TABLE encrypted_members ADD COLUMN last_seen_at INTEGER NOT NULL DEFAULT 0');
    }
    $db->exec('UPDATE encrypted_rooms SET master_session_hash=(SELECT session_hash FROM encrypted_members WHERE encrypted_members.room_id=encrypted_rooms.room_id ORDER BY rowid LIMIT 1) WHERE master_session_hash IS NULL');
    $db->prepare('DELETE FROM rooms WHERE expires_at <= ?')->execute([time()]);
    $check = $db->query('PRAGMA integrity_check')->fetchColumn();
    if ($check !== 'ok') {
        throw new RuntimeException('SQLite integrity check failed: ' . $check);
    }
    App\Database::clearIntegrityFailure();
} catch (Throwable $e) {
    App\Database::markIntegrityFailure($e->getMessage());
    fwrite(STDERR, "Database initialization entered maintenance mode: {$e->getMessage()}\n");
}
