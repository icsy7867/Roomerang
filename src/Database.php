<?php
declare(strict_types=1);
namespace App;

use PDO;

final class Database
{
    private const JOURNAL_MODES = ['DELETE', 'WAL'];

    public static function integrityFailure(): ?string
    {
        $status = self::path() . '.integrity-failed';
        if (!is_file($status)) return null;
        $message = file_get_contents($status);
        return $message === false ? 'The database integrity check failed.' : trim($message);
    }

    public static function markIntegrityFailure(string $message): void
    {
        file_put_contents(self::path() . '.integrity-failed', $message . "\n", LOCK_EX);
    }

    public static function clearIntegrityFailure(): void
    {
        $status = self::path() . '.integrity-failed';
        if (is_file($status) && !unlink($status)) throw new \RuntimeException('Could not clear the database maintenance marker.');
    }
    public static function path(): string
    {
        return getenv('PASTE_CHAT_DB') ?: '/app/data/database.sql';
    }

    public static function journalMode(): string
    {
        $mode = strtoupper(trim((string)(getenv('SQLITE_JOURNAL_MODE') ?: 'DELETE')));
        if (!in_array($mode, self::JOURNAL_MODES, true)) {
            throw new \RuntimeException('SQLITE_JOURNAL_MODE must be DELETE or WAL.');
        }
        return $mode;
    }

    public static function connection(): PDO
    {
        $path = self::path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create data directory.');
        }
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA busy_timeout = 10000;');
        $desiredMode = self::journalMode();
        $journalMode = strtoupper((string)$db->query('PRAGMA journal_mode')->fetchColumn());
        if ($journalMode !== $desiredMode) {
            $journalMode = strtoupper((string)$db->query('PRAGMA journal_mode = ' . $desiredMode)->fetchColumn());
        }
        if ($journalMode !== $desiredMode) {
            throw new \RuntimeException('Could not enable SQLite ' . $desiredMode . ' journaling.');
        }
        $db->exec('PRAGMA foreign_keys = ON; PRAGMA synchronous = NORMAL;');
        return $db;
    }

    public static function reset(): void
    {
        $path = self::path();
        EncryptedAttachmentStore::reset();
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (file_exists($file) && !unlink($file)) {
                throw new \RuntimeException('Could not remove the existing database file.');
            }
        }

        $db = self::connection();
        try {
            $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
            if ($schema === false) throw new \RuntimeException('Could not load the database schema.');
            $db->exec($schema);
            $check = $db->query('PRAGMA integrity_check')->fetchColumn();
            if ($check !== 'ok') throw new \RuntimeException('Fresh database integrity check failed: ' . $check);
            self::clearIntegrityFailure();
        } finally {
            $db = null;
        }
    }
}
