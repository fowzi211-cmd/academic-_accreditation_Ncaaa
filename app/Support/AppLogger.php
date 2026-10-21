<?php

declare(strict_types=1);

namespace QualitySystem\Support;

use Throwable;

final class AppLogger
{
    public static function install(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            self::write('php_error', ['severity' => $severity, 'message' => $message, 'file' => basename($file), 'line' => $line]);
            return true;
        });
        set_exception_handler(static function (Throwable $error): void {
            self::write('uncaught_exception', [
                'type' => $error::class,
                'message' => $error->getMessage(),
                'file' => basename($error->getFile()),
                'line' => $error->getLine(),
            ]);
            if (PHP_SAPI !== 'cli') {
                http_response_code(500);
                echo 'An unexpected error occurred. Reference: ' . self::requestId();
            }
        });
    }

    public static function write(string $event, array $context = []): void
    {
        $safe = self::redact($context);
        $safe['event'] = $event;
        $safe['request_id'] = self::requestId();
        $safe['time'] = gmdate(DATE_ATOM);
        $root = getenv('QUALITY_STORAGE_ROOT') ?: 'C:\\quality_system_storage';
        $directory = $root . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }
        @error_log(json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL, 3, $directory . DIRECTORY_SEPARATOR . 'application-' . gmdate('Y-m-d') . '.jsonl');
    }

    public static function requestId(): string
    {
        static $id;
        return $id ??= bin2hex(random_bytes(16));
    }

    private static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (preg_match('/pass|secret|token|authorization|cookie/i', (string)$key)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }
        return $context;
    }
}
