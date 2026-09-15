<?php
declare(strict_types=1);
namespace App;

/** Durations only: never include room identifiers, keys, or payloads. */
final class ServerTiming
{
    public static function measure(string $name, callable $operation): mixed
    {
        $started = hrtime(true);
        try {
            return $operation();
        } finally {
            self::report($name, (hrtime(true) - $started) / 1e6);
        }
    }

    public static function report(string $name, float $milliseconds): void
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('Server-Timing: ' . $name . ';dur=' . number_format($milliseconds, 2, '.', ''), false);
        }
    }
}
