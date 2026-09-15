<?php
declare(strict_types=1);
namespace App;

use PDO;

/** Stores client-encrypted attachment wires outside SQLite. */
final class EncryptedAttachmentStore
{
    public const MAX_WIRE_BYTES = EncryptedChatService::MAX_CIPHERTEXT + 12;

    public static function directory(): string
    {
        return dirname(Database::path()) . '/files';
    }

    /** @return array{path:string,bytes:int,sha256:string,nonce:string} */
    public static function stageInput(): array
    {
        $length = $_SERVER['CONTENT_LENGTH'] ?? '';
        if (is_string($length) && ctype_digit($length) && (int)$length > self::MAX_WIRE_BYTES) {
            throw new \InvalidArgumentException('Invalid encrypted payload or file exceeds 8 MB.');
        }

        self::ensureDirectory();
        $path = self::directory() . '/.' . bin2hex(random_bytes(16)) . '.upload';
        $input = fopen('php://input', 'rb');
        $output = fopen($path, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            if (is_file($path)) @unlink($path);
            throw new \RuntimeException('Could not stage the encrypted attachment.');
        }

        $bytes = 0;
        $hash = hash_init('sha256');
        $nonce = '';
        try {
            while (!feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false) throw new \RuntimeException('Could not read the encrypted attachment.');
                if ($chunk === '') continue;
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_WIRE_BYTES) throw new \InvalidArgumentException('Invalid encrypted payload or file exceeds 8 MB.');
                if (strlen($nonce) < 12) $nonce .= substr($chunk, 0, 12 - strlen($nonce));
                hash_update($hash, $chunk);
                self::writeAll($output, $chunk);
            }
            if ($bytes < 32 || strlen($nonce) !== 12) throw new \InvalidArgumentException('Invalid encrypted payload.');
            if (!fflush($output) || (function_exists('fsync') && !fsync($output))) {
                throw new \RuntimeException('Could not save the encrypted attachment.');
            }
            fclose($output);
            fclose($input);
            @chmod($path, 0600);
            return ['path' => $path, 'bytes' => $bytes, 'sha256' => hash_final($hash), 'nonce' => $nonce];
        } catch (\Throwable $error) {
            fclose($output);
            fclose($input);
            @unlink($path);
            throw $error;
        }
    }

    /** @return string A new opaque, URL-safe object identifier. */
    public static function newKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function promote(string $stagedPath, string $key): void
    {
        self::assertKey($key);
        $destination = self::path($key);
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create encrypted attachment storage.');
        }
        if (file_exists($destination) || !rename($stagedPath, $destination)) {
            throw new \RuntimeException('Could not save the encrypted attachment.');
        }
        @chmod($destination, 0600);
    }

    public static function discard(string $path): void
    {
        if (is_file($path) && !@unlink($path)) error_log('Could not remove staged encrypted attachment.');
    }

    public static function stream(string $key, int $bytes): void
    {
        $path = self::path($key);
        $file = @fopen($path, 'rb');
        if ($file === false) throw new \RuntimeException('Attachment unavailable.');
        try {
            $stat = fstat($file);
            if ($stat === false || (int)$stat['size'] !== $bytes) throw new \RuntimeException('Attachment unavailable.');
            while (!feof($file)) {
                $chunk = fread($file, 65536);
                if ($chunk === false) throw new \RuntimeException('Attachment unavailable.');
                if ($chunk !== '') echo $chunk;
            }
        } finally {
            fclose($file);
        }
    }

    public static function read(string $key, int $bytes): string
    {
        $path = self::path($key);
        $contents = @file_get_contents($path);
        if ($contents === false || strlen($contents) !== $bytes) throw new \RuntimeException('Attachment unavailable.');
        return $contents;
    }

    public static function remove(string $key): void
    {
        $path = self::path($key);
        if (is_file($path) && !@unlink($path)) error_log('Could not remove encrypted attachment object.');
        self::removeEmptyDirectories(dirname($path));
    }

    public static function reset(): void
    {
        $directory = self::directory();
        if (!is_dir($directory)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink() || $entry->isFile()) {
                if (!@unlink($path)) throw new \RuntimeException('Could not remove encrypted attachment storage.');
            } elseif ($entry->isDir() && !@rmdir($path)) {
                throw new \RuntimeException('Could not remove encrypted attachment storage.');
            }
        }
        if (!@rmdir($directory)) throw new \RuntimeException('Could not remove encrypted attachment storage.');
    }

    public static function pruneExpired(PDO $db): int
    {
        $now = time();
        $keys = $db->prepare('SELECT f.storage_key FROM encrypted_message_files f JOIN encrypted_messages m ON m.id=f.message_id JOIN rooms r ON r.id=m.room_id WHERE r.expires_at <= ?');
        $keys->execute([$now]);
        $storageKeys = $keys->fetchAll(PDO::FETCH_COLUMN);
        $delete = $db->prepare('DELETE FROM rooms WHERE expires_at <= ?');
        $delete->execute([$now]);
        foreach ($storageKeys as $key) self::remove((string)$key);
        return $delete->rowCount();
    }

    /** Remove abandoned staging files and attachment files no longer referenced by SQLite. */
    public static function cleanupOrphans(PDO $db): void
    {
        $directory = self::directory();
        if (!is_dir($directory)) return;
        $referenced = array_fill_keys(array_column(self::referenced($db), 'storage_key'), true);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink()) {
                if (!@unlink($path)) throw new \RuntimeException('Could not reconcile encrypted attachment storage.');
                continue;
            }
            if ($entry->isFile()) {
                $name = $entry->getFilename();
                $key = str_ends_with($name, '.blob') ? substr($name, 0, -5) : '';
                if (str_ends_with($name, '.upload') || !isset($referenced[$key])) {
                    if (!@unlink($path)) throw new \RuntimeException('Could not reconcile encrypted attachment storage.');
                }
            } elseif ($entry->isDir()) {
                @rmdir($path);
            }
        }
    }

    /** @return list<array{storage_key:string,wire_bytes:int,wire_sha256:string}> */
    public static function referenced(PDO $db): array
    {
        return $db->query('SELECT storage_key, wire_bytes, wire_sha256 FROM encrypted_message_files ORDER BY storage_key')->fetchAll();
    }

    public static function path(string $key): string
    {
        self::assertKey($key);
        return self::directory() . '/' . substr($key, 0, 2) . '/' . substr($key, 2, 2) . '/' . $key . '.blob';
    }

    private static function ensureDirectory(): void
    {
        $directory = self::directory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create encrypted attachment storage.');
        }
    }

    private static function removeEmptyDirectories(string $directory): void
    {
        $root = self::directory();
        while ($directory !== $root && str_starts_with($directory, $root . '/') && is_dir($directory)) {
            if (!@rmdir($directory)) return;
            $directory = dirname($directory);
        }
    }

    /** @param resource $output */
    private static function writeAll($output, string $chunk): void
    {
        $offset = 0;
        $length = strlen($chunk);
        while ($offset < $length) {
            $written = fwrite($output, substr($chunk, $offset));
            if ($written === false || $written === 0) throw new \RuntimeException('Could not stage the encrypted attachment.');
            $offset += $written;
        }
    }

    private static function assertKey(string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $key)) throw new \RuntimeException('Invalid encrypted attachment reference.');
    }
}
