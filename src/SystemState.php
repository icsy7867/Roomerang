<?php
declare(strict_types=1);
namespace App;

use PDO;

final class SystemState
{
    public static function maintenanceEnabled(PDO $db): bool
    {
        $statement = $db->prepare("SELECT value FROM system_settings WHERE key = 'maintenance_mode'");
        $statement->execute();
        return $statement->fetchColumn() === '1';
    }

    public static function assertWritable(PDO $db): void
    {
        if (self::maintenanceEnabled($db)) {
            throw new AccessDeniedException('Roomerang is temporarily in maintenance mode. Please try again shortly.');
        }
    }

    public static function setMaintenanceMode(PDO $db, bool $enabled): void
    {
        $statement = $db->prepare("INSERT INTO system_settings(key, value) VALUES ('maintenance_mode', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
        $statement->execute([$enabled ? '1' : '0']);
    }
}
