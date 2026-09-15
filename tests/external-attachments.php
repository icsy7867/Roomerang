<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function check(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo "PASS {$label}\n";
}

$root = sys_get_temp_dir() . '/roomerang-external-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
putenv('PASTE_CHAT_DB=' . $root . '/database.sqlite');

try {
    $db = new PDO('sqlite:' . App\Database::path(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    $service = new App\EncryptedChatService($db);
    $room = $service->room($service->create('10m', 'all')['token']);
    $member = $service->join($room, 'attachment-session', 'Attachment sender');

    $wire = random_bytes(12) . random_bytes(4096);
    mkdir(App\EncryptedAttachmentStore::directory(), 0700, true);
    $stagedPath = App\EncryptedAttachmentStore::directory() . '/.test.upload';
    file_put_contents($stagedPath, $wire);
    $message = $service->postAttachment($room, $member, [
        'path' => $stagedPath,
        'bytes' => strlen($wire),
        'sha256' => hash('sha256', $wire),
        'nonce' => substr($wire, 0, 12),
    ]);
    $file = $db->query('SELECT storage_key,wire_bytes,wire_sha256 FROM encrypted_message_files WHERE message_id=' . (int)$message['id'])->fetch();
    check($file !== false && $file['wire_bytes'] === strlen($wire), 'attachment metadata is stored separately');
    check((int)$db->query('SELECT length(ciphertext) FROM encrypted_messages WHERE id=' . (int)$message['id'])->fetchColumn() === 0, 'attachment ciphertext is absent from SQLite');
    check(is_file(App\EncryptedAttachmentStore::path($file['storage_key'])), 'attachment ciphertext is promoted to opaque file storage');
    check(substr_count(App\EncryptedAttachmentStore::relativePath($file['storage_key']), '/') === 1, 'new attachments use one EFS shard directory');

    $source = $service->contentSource($room, $member, $message['id']);
    check(!isset($source['bytes']) && $source['wire_bytes'] === strlen($wire), 'member content uses attachment source metadata');
    ob_start();
    App\EncryptedAttachmentStore::stream($source['storage_key'], $source['wire_bytes']);
    check(ob_get_clean() === $wire, 'member content streams exact encrypted attachment bytes');

    $currentPath = App\EncryptedAttachmentStore::path($file['storage_key']);
    $legacyDirectory = App\EncryptedAttachmentStore::directory() . '/' . substr($file['storage_key'], 0, 2) . '/' . substr($file['storage_key'], 2, 2);
    mkdir($legacyDirectory, 0700, true);
    $legacyPath = $legacyDirectory . '/' . $file['storage_key'] . '.blob';
    rename($currentPath, $legacyPath);
    check(App\EncryptedAttachmentStore::path($file['storage_key']) === $legacyPath, 'existing two-level attachment paths remain readable');

    $share = $service->share($room, $member, $message['id']);
    $shared = $service->shared($share['token']);
    $sharedSource = $service->sharedContentSource($shared);
    check($sharedSource['storage_key'] === $file['storage_key'], 'shared content resolves the same encrypted attachment');

    $orphan = App\EncryptedAttachmentStore::directory() . '/orphan.blob';
    file_put_contents($orphan, 'orphan');
    App\EncryptedAttachmentStore::cleanupOrphans($db);
    check(!is_file($orphan), 'startup reconciliation removes unreferenced attachment files');

    $archive = (new App\AdminService($db))->backupPath();
    $tar = new PharData($archive);
    $archivePath = 'files/' . App\EncryptedAttachmentStore::relativePath($file['storage_key']);
    check($tar->offsetExists('database.sqlite') && $tar->offsetExists($archivePath), 'backup archive includes database and encrypted attachment');
    unset($tar);
    unlink($archive);

    $db->exec('UPDATE rooms SET expires_at = 0');
    check(App\EncryptedAttachmentStore::pruneExpired($db) === 1, 'expired room is pruned');
    check(!is_file(App\EncryptedAttachmentStore::path($file['storage_key'])), 'expiry pruning removes encrypted attachment file');
    App\EncryptedAttachmentStore::reset();
    check(!is_dir(App\EncryptedAttachmentStore::directory()), 'attachment reset removes encrypted attachment storage');
    echo "All external attachment checks passed.\n";
} finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($root);
    }
}
