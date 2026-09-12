<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (App\Database::integrityFailure() !== null) {
    fwrite(STDERR, "Database integrity maintenance mode is active.\n");
    exit(0);
}

try {
    $check = App\Database::connection()->query('PRAGMA integrity_check')->fetchColumn();
    exit($check === 'ok' ? 0 : 1);
} catch (Throwable) {
    exit(1);
}
