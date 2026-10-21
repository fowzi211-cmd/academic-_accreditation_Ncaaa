<?php

declare(strict_types=1);

namespace QualitySystem\Security;

use PDO;
use QualitySystem\Support\AppLogger;
use Throwable;

final class AuditLogger
{
    public static function record(PDO $pdo, string $event, string $outcome, string $module, array $metadata = [], ?string $recordType = null, string|int|null $recordId = null): void
    {
        try {
            $statement = $pdo->prepare('INSERT INTO security_audit_log (event_type, outcome, user_id, actor_role, module_name, record_type, record_id, request_id, ip_hash, metadata_json) VALUES (:event, :outcome, :user, :role, :module, :record_type, :record_id, :request, :ip, :metadata)');
            $statement->execute([
                ':event' => $event,
                ':outcome' => $outcome,
                ':user' => $_SESSION['user_id'] ?? null,
                ':role' => $_SESSION['role'] ?? null,
                ':module' => $module,
                ':record_type' => $recordType,
                ':record_id' => $recordId,
                ':request' => AppLogger::requestId(),
                ':ip' => self::hash((string)($_SERVER['REMOTE_ADDR'] ?? 'cli')),
                ':metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        } catch (Throwable $error) {
            AppLogger::write('audit_write_failed', ['event_type' => $event, 'error' => $error->getMessage()]);
        }
    }

    public static function hash(string $value): string
    {
        $key = getenv('APP_AUDIT_KEY');
        if ($key === false || $key === '') {
            $applicationConfigFile = 'C:\\quality_system_config\\application.php';
            $applicationConfig = is_file($applicationConfigFile) ? require $applicationConfigFile : [];
            $key = $applicationConfig['audit_key'] ?? '';
        }
        if ($key === false || $key === '') {
            $configFile = 'C:\\quality_system_config\\database.php';
            $config = is_file($configFile) ? require $configFile : [];
            $key = hash('sha256', 'audit:' . ($config['password'] ?? 'unconfigured'));
        }
        return hash_hmac('sha256', strtolower(trim($value)), $key);
    }
}
