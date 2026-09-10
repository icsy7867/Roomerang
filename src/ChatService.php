<?php
declare(strict_types=1);
namespace App;

use PDO;

final class ChatService
{
    private const DURATIONS = ['10m' => 600, '30m' => 1800, '1h' => 3600, '12h' => 43200, '1d' => 86400, '1w' => 604800, '1mo' => 2592000];
    private const DURATION_LABELS = ['10m' => '10 minutes', '30m' => '30 minutes', '1h' => '1 hour', '12h' => '12 hours', '1d' => '1 day', '1w' => '1 week', '1mo' => '1 month'];

    public function __construct(private PDO $db) {}

    /** @return array<string, string> duration key => human-readable label */
    public static function durationChoices(): array
    {
        $configuredMaximum = strtolower(trim((string)(getenv('MAXIMUM_DURATION') ?: '1mo')));
        $configuredMaximum = $configuredMaximum === '1m' ? '1mo' : $configuredMaximum;
        $maximumSeconds = self::DURATIONS[$configuredMaximum] ?? self::DURATIONS['1mo'];
        return array_filter(
            self::DURATION_LABELS,
            static fn (string $key): bool => self::DURATIONS[$key] <= $maximumSeconds,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** Return a configured, currently permitted default duration for the room form. */
    public static function defaultDuration(): string
    {
        $choices = self::durationChoices();
        $configuredDefault = strtolower(trim((string)(getenv('DEFAULT_DURATION') ?: '1h')));
        $configuredDefault = $configuredDefault === '1m' ? '1mo' : $configuredDefault;

        if (array_key_exists($configuredDefault, $choices)) {
            return $configuredDefault;
        }

        return array_key_exists('1h', $choices) ? '1h' : (string)array_key_last($choices);
    }

    public function create(string $duration, string $history): array
    {
        if (!isset(self::durationChoices()[$duration]) || !in_array($history, ['all', 'since_join'], true)) throw new \InvalidArgumentException('Invalid room settings.');
        $now = time();
        $this->db->prepare('DELETE FROM rooms WHERE expires_at <= ?')->execute([$now]);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->prepare('INSERT INTO rooms (token, expires_at, history_mode, created_at) VALUES (?, ?, ?, ?)')->execute([$token, $now + self::DURATIONS[$duration], $history, $now]);
        return ['token' => $token, 'expires_at' => $now + self::DURATIONS[$duration], 'history_mode' => $history];
    }

    public function room(string $token): array
    {
        $stmt = $this->db->prepare('SELECT * FROM rooms WHERE token = ? AND expires_at > ?');
        $stmt->execute([$token, time()]);
        $room = $stmt->fetch();
        if (!$room) throw new \RuntimeException('This chat does not exist or has expired.');
        return $room;
    }

    public function member(array $room, string $sessionHash): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM members WHERE room_id = ? AND session_hash = ?');
        $stmt->execute([$room['id'], $sessionHash]);
        return $stmt->fetch() ?: null;
    }

    public function join(array $room, string $sessionHash, string $name): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 40) throw new \InvalidArgumentException('Name must be between 1 and 40 characters.');
        $member = $this->member($room, $sessionHash);
        if ($member) return $member;
        $now = time();
        $this->db->prepare('INSERT INTO members (room_id, session_hash, display_name, joined_at) VALUES (?, ?, ?, ?)')->execute([$room['id'], $sessionHash, $name, $now]);
        return $this->member($room, $sessionHash);
    }

    public function messages(array $room, array $member, int $after = 0): array
    {
        $sql = 'SELECT messages.id, messages.member_id, messages.body, messages.created_at, members.display_name, images.token AS image_token FROM messages JOIN members ON members.id = messages.member_id LEFT JOIN images ON images.message_id = messages.id WHERE messages.room_id = :room AND messages.id > :after';
        if ($room['history_mode'] === 'since_join') $sql .= ' AND messages.created_at >= :joined';
        $sql .= ' ORDER BY messages.id ASC LIMIT 200';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':room', $room['id'], PDO::PARAM_INT);
        $stmt->bindValue(':after', max(0, $after), PDO::PARAM_INT);
        if ($room['history_mode'] === 'since_join') $stmt->bindValue(':joined', $member['joined_at'], PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static function (array $message) use ($member): array {
            $message['is_mine'] = (int)$message['member_id'] === (int)$member['id'];
            unset($message['member_id']);
            $message['image_url'] = $message['image_token'] ? '/media/' . $message['image_token'] : null;
            unset($message['image_token']);
            return $message;
        }, $stmt->fetchAll());
    }

    public function post(array $room, array $member, string $body): array
    {
        $body = trim($body);
        $limit = (int)(getenv('PASTE_CHAT_MAX_MESSAGE_LENGTH') ?: 12000);
        if ($body === '' || mb_strlen($body) > $limit) throw new \InvalidArgumentException("Message must be between 1 and {$limit} characters.");
        $now = time();
        $this->db->prepare('INSERT INTO messages (room_id, member_id, body, created_at) VALUES (?, ?, ?, ?)')->execute([$room['id'], $member['id'], $body, $now]);
        return ['id' => (int)$this->db->lastInsertId(), 'body' => $body, 'created_at' => $now, 'display_name' => $member['display_name'], 'is_mine' => true];
    }

    public function postImage(array $room, array $member, array $upload): array
    {
        $maximumSize = 8 * 1024 * 1024;
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
            throw new \InvalidArgumentException('Image upload failed.');
        }
        if ((int)$upload['size'] < 1 || (int)$upload['size'] > $maximumSize) {
            throw new \InvalidArgumentException('Images must be no larger than 8 MB.');
        }
        $contents = file_get_contents($upload['tmp_name']);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            throw new \InvalidArgumentException('Only PNG, JPEG, GIF, and WebP images are supported.');
        }
        $now = time();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO messages (room_id, member_id, body, created_at) VALUES (?, ?, ?, ?)')->execute([$room['id'], $member['id'], '', $now]);
            $messageId = (int)$this->db->lastInsertId();
            $image = $this->db->prepare('INSERT INTO images (message_id, token, mime_type, contents, size_bytes, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $image->bindValue(1, $messageId, PDO::PARAM_INT);
            $image->bindValue(2, $token);
            $image->bindValue(3, $mime);
            $image->bindValue(4, $contents, PDO::PARAM_LOB);
            $image->bindValue(5, strlen($contents), PDO::PARAM_INT);
            $image->bindValue(6, $now, PDO::PARAM_INT);
            $image->execute();
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        return ['id' => $messageId, 'body' => '', 'created_at' => $now, 'display_name' => $member['display_name'], 'is_mine' => true, 'image_url' => '/media/' . $token];
    }

    public function image(string $token): ?array
    {
        $statement = $this->db->prepare('SELECT images.mime_type, images.contents FROM images JOIN messages ON messages.id = images.message_id JOIN rooms ON rooms.id = messages.room_id WHERE images.token = ? AND rooms.expires_at > ?');
        $statement->execute([$token, time()]);
        return $statement->fetch() ?: null;
    }
}
