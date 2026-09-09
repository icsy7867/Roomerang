<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $db = App\Database::connection();
    $db->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    $db->prepare('DELETE FROM rooms WHERE expires_at <= ?')->execute([time()]);
    $check = $db->query('PRAGMA integrity_check')->fetchColumn();
    if ($check !== 'ok') {
        throw new RuntimeException('SQLite integrity check failed: ' . $check);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Database initialization failed: {$e->getMessage()}\n");
    exit(1);
}
