<?php

declare(strict_types=1);

namespace QualitySystem\Security;

use DateTimeImmutable;
use PDO;

final class LoginThrottle
{
    private const WINDOW_MINUTES = 15;
    private const MAX_IDENTITY_ATTEMPTS = 5;
    private const MAX_IP_ATTEMPTS = 20;

    public function __construct(private PDO $pdo) {}

    public function isBlocked(string $email, string $ip): bool
    {
        $sql = "SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL " . self::WINDOW_MINUTES . " MINUTE) AND %s = :hash";
        foreach ([['identity_hash', self::MAX_IDENTITY_ATTEMPTS, $this->hash($email)], ['ip_hash', self::MAX_IP_ATTEMPTS, $this->hash($ip)]] as [$column, $limit, $hash]) {
            $statement = $this->pdo->prepare(sprintf($sql, $column));
            $statement->execute([':hash' => $hash]);
            if ((int)$statement->fetchColumn() >= $limit) {
                return true;
            }
        }
        return false;
    }

    public function record(string $email, string $ip, bool $succeeded): void
    {
        $statement = $this->pdo->prepare('INSERT INTO login_attempts (identity_hash, ip_hash, succeeded) VALUES (:identity, :ip, :succeeded)');
        $statement->execute([':identity' => $this->hash($email), ':ip' => $this->hash($ip), ':succeeded' => (int)$succeeded]);
        if ($succeeded) {
            $cleanup = $this->pdo->prepare('DELETE FROM login_attempts WHERE identity_hash = :identity AND succeeded = 0');
            $cleanup->execute([':identity' => $this->hash($email)]);
        }
    }

    public function userIsLocked(array $user): bool
    {
        return !empty($user['locked_until']) && new DateTimeImmutable((string)$user['locked_until']) > new DateTimeImmutable();
    }

    public function failUser(int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET failed_login_count = failed_login_count + 1, locked_until = CASE WHEN failed_login_count + 1 >= 5 THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE) ELSE locked_until END WHERE id = :id');
        $statement->execute([':id' => $userId]);
    }

    public function succeedUser(int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = NOW() WHERE id = :id');
        $statement->execute([':id' => $userId]);
    }

    private function hash(string $value): string
    {
        return AuditLogger::hash($value);
    }
}
