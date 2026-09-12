<?php
declare(strict_types=1);
namespace App;

use PDO;

final class AdminService
{
    private const COOKIE = 'paste_chat_admin';
    private const SESSION_LIFETIME = 28800;

    public function __construct(private ?PDO $db) {}

    private function db(): PDO
    {
        if ($this->db === null) throw new \RuntimeException('This admin session is no longer active.');
        return $this->db;
    }

    public static function configured(): bool
    {
        return (string)getenv('ADMIN_PASSWORD_HASH') !== '' || (string)getenv('ADMIN_PASSWORD') !== '';
    }

    public function login(string $password): bool
    {
        $hash = (string)getenv('ADMIN_PASSWORD_HASH');
        $plain = (string)getenv('ADMIN_PASSWORD');
        $valid = $hash !== '' ? password_verify($password, $hash) : ($plain !== '' && hash_equals($plain, $password));
        if (!$valid) return false;

        $this->db()->prepare('DELETE FROM admin_sessions WHERE expires_at <= ?')->execute([time()]);
        $session = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $this->db()->prepare('INSERT INTO admin_sessions(session_hash, csrf_token, expires_at) VALUES (?, ?, ?)')
            ->execute([hash('sha256', $session), $csrf, time() + self::SESSION_LIFETIME]);
        self::setCookie($session, time() + self::SESSION_LIFETIME);
        return true;
    }

    /** @return array{session_hash:string,csrf_token:string,expires_at:int}|null */
    public function session(): ?array
    {
        $session = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($session) || !preg_match('/^[a-f0-9]{64}$/', $session)) return null;
        $statement = $this->db()->prepare('SELECT session_hash, csrf_token, expires_at FROM admin_sessions WHERE session_hash = ? AND expires_at > ?');
        $statement->execute([hash('sha256', $session), time()]);
        return $statement->fetch() ?: null;
    }

    public function logout(): void
    {
        $session = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($session) && preg_match('/^[a-f0-9]{64}$/', $session)) {
            $this->db()->prepare('DELETE FROM admin_sessions WHERE session_hash = ?')->execute([hash('sha256', $session)]);
        }
        self::setCookie('', time() - 3600);
    }

    public function assertCsrf(array $session, mixed $token): void
    {
        if (!is_string($token) || !hash_equals($session['csrf_token'], $token)) {
            throw new \RuntimeException('Your admin session could not be verified. Reload the page and try again.');
        }
    }

    /** @return array<string,int|float|bool|string> */
    public function statistics(): array
    {
        $now = time();
        $counts = $this->db()->prepare("SELECT
            COUNT(*) AS total_chats,
            SUM(CASE WHEN expires_at > ? THEN 1 ELSE 0 END) AS active_chats,
            SUM(CASE WHEN expires_at <= ? THEN 1 ELSE 0 END) AS expired_chats
            FROM rooms");
        $counts->execute([$now, $now]);
        $result = $counts->fetch();
        $messages = $this->db()->prepare("WITH room_counts AS (
              SELECT rooms.id, rooms.expires_at,
                (SELECT COUNT(*) FROM messages WHERE room_id = rooms.id) +
                (SELECT COUNT(*) FROM encrypted_messages WHERE room_id = rooms.id) AS message_count
              FROM rooms
            ) SELECT
              SUM(CASE WHEN message_count = 0 THEN 1 ELSE 0 END) AS empty_chats,
              COALESCE(MAX(CASE WHEN expires_at > ? THEN message_count END), 0) AS maximum_messages,
              COALESCE(AVG(CASE WHEN expires_at > ? THEN message_count END), 0) AS average_messages
            FROM room_counts");
        $messages->execute([$now, $now]);
        $messageResult = $messages->fetch();
        return [
            'total_chats' => (int)$result['total_chats'],
            'active_chats' => (int)$result['active_chats'],
            'expired_chats' => (int)$result['expired_chats'],
            'empty_chats' => (int)$messageResult['empty_chats'],
            'maximum_messages' => (int)$messageResult['maximum_messages'],
            'average_messages' => round((float)$messageResult['average_messages'], 2),
            'maintenance_mode' => SystemState::maintenanceEnabled($this->db()),
            'integrity' => (string)$this->db()->query('PRAGMA integrity_check')->fetchColumn(),
        ];
    }

    public function pruneExpired(): int
    {
        $statement = $this->db()->prepare('DELETE FROM rooms WHERE expires_at <= ?');
        $statement->execute([time()]);
        $this->db()->exec('PRAGMA optimize');
        return $statement->rowCount();
    }

    public function backupPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'roomerang-backup-');
        if ($path === false) throw new \RuntimeException('Could not create a temporary backup file.');
        @unlink($path);
        try {
            $this->db()->exec('VACUUM INTO ' . $this->db()->quote($path));
            return $path;
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
    }

    public function resetDatabase(): void
    {
        $this->db = null;
        Database::reset();
        self::setCookie('', time() - 3600);
    }

    private static function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => '/admin',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
    }
}
