<?php

declare(strict_types=1);

function schemaTableExists(PDO $connection, string $table): bool
{
    $statement = $connection->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table'
    );
    $statement->execute([':table' => $table]);
    return (int)$statement->fetchColumn() > 0;
}

function schemaColumnExists(PDO $connection, string $table, string $column): bool
{
    $statement = $connection->prepare(
        'SELECT COUNT(*) FROM information_schema.columns '
        . 'WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
    );
    $statement->execute([':table' => $table, ':column' => $column]);
    return (int)$statement->fetchColumn() > 0;
}

function requireSchemaTable(PDO $connection, string $table): void
{
    if (!schemaTableExists($connection, $table)) {
        throw new RuntimeException(
            "Database schema is missing table '{$table}'. Run: php database/migrate.php"
        );
    }
}

function requireSchemaColumn(PDO $connection, string $table, string $column): void
{
    if (!schemaColumnExists($connection, $table, $column)) {
        throw new RuntimeException(
            "Database schema is missing '{$table}.{$column}'. Run: php database/migrate.php"
        );
    }
}
