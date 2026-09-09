<?php
declare(strict_types=1);
namespace App;

final class Http
{
    public static function json(array $value, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($value, JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function input(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) self::json(['error' => 'A JSON request body is required.'], 400);
        return $input;
    }

    public static function sessionId(): string
    {
        $id = $_COOKIE['paste_chat_session'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/', $id)) {
            $id = bin2hex(random_bytes(32));
            setcookie('paste_chat_session', $id, ['expires' => time() + 31536000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
        }
        return hash('sha256', $id);
    }
}
