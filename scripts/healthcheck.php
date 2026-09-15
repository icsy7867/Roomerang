<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (App\Database::integrityFailure() !== null) {
    fwrite(STDERR, "Database integrity maintenance mode is active.\n");
    exit(0);
}

try {
    // Exercise a real application table without scanning every stored message.
    App\Database::connection()->query('SELECT id FROM encrypted_messages LIMIT 1')->fetchColumn();
    exit(0);
} catch (Throwable) {
    exit(1);
}
