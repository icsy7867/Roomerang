<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $check = App\Database::connection()->query('PRAGMA integrity_check')->fetchColumn();
    exit($check === 'ok' ? 0 : 1);
} catch (Throwable) {
    exit(1);
}
