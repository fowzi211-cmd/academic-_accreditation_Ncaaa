<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

$applicationConfig = require 'C:\\quality_system_config\\database.php';
$adminConfig = require 'C:\\quality_system_config\\database-admin.php';

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $adminConfig['host'],
        $adminConfig['port'],
        $applicationConfig['name']
    ),
    $adminConfig['user'],
    $adminConfig['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations ('
    . 'migration VARCHAR(190) PRIMARY KEY, executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$executed = array_flip($pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
$migrationFiles = glob(__DIR__ . '/migrations/*.php') ?: [];
sort($migrationFiles, SORT_STRING);

foreach ($migrationFiles as $migrationFile) {
    $migrationName = basename($migrationFile, '.php');
    if (isset($executed[$migrationName])) {
        continue;
    }

    $migration = require $migrationFile;
    if (!is_callable($migration)) {
        throw new RuntimeException("Migration {$migrationName} must return a callable.");
    }

    try {
        $migration($pdo);
        $statement = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
        $statement->execute([':migration' => $migrationName]);
        echo "Applied {$migrationName}" . PHP_EOL;
    } catch (Throwable $error) {
        throw $error;
    }
}

echo 'Migrations are current.' . PHP_EOL;
