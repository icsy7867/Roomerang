<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function check(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo "PASS {$label}\n";
}

$path = tempnam(sys_get_temp_dir(), 'roomerang-journal-');
if ($path === false) throw new RuntimeException('Could not allocate a temporary database path.');

try {
    $legacy = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $legacy->exec('PRAGMA journal_mode = WAL; CREATE TABLE probe (value TEXT NOT NULL); INSERT INTO probe VALUES (\'preserved\');');
    $legacy = null;

    putenv('PASTE_CHAT_DB=' . $path);
    $db = App\Database::connection();
    check(strtolower((string)$db->query('PRAGMA journal_mode')->fetchColumn()) === 'delete', 'startup converts WAL database to rollback journaling');
    check((int)$db->query('PRAGMA synchronous')->fetchColumn() === 1, 'normal synchronous mode is enabled');
    check((int)$db->query('PRAGMA busy_timeout')->fetchColumn() === 10000, 'busy timeout is ten seconds');
    check($db->query('SELECT value FROM probe')->fetchColumn() === 'preserved', 'journal transition preserves existing data');
    $db = null;
} finally {
    foreach ([$path, $path . '-wal', $path . '-shm', $path . '.integrity-failed'] as $file) {
        if (is_file($file)) unlink($file);
    }
}
