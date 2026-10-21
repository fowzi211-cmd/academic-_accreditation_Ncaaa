<?php

declare(strict_types=1);

namespace QualitySystem\Tests;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class DatabasePrivilegesTest extends TestCase
{
    #[Group('database')]
    public function testApplicationAccountCannotAlterSchema(): void
    {
        $config = require 'C:\\quality_system_config\\database.php';
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['name']),
            $config['user'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $grants = implode("\n", $pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN));
        self::assertStringContainsString('SELECT, INSERT, UPDATE, DELETE', $grants);
        self::assertStringNotContainsString('ALL PRIVILEGES', $grants);
        self::assertStringNotContainsString('ALTER', $grants);
        self::assertStringNotContainsString('DROP', $grants);
    }
}
