<?php

require_once __DIR__ . '/schema_readiness.php';

$localConfigFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'quality_system_config' . DIRECTORY_SEPARATOR . 'database.php';
$localConfig = is_file($localConfigFile) ? require $localConfigFile : [];

$host = getenv('DB_HOST') ?: ($localConfig['host'] ?? 'localhost');
$port = (int)(getenv('DB_PORT') ?: ($localConfig['port'] ?? 3306));
$dbname = getenv('DB_NAME') ?: ($localConfig['name'] ?? 'quality_system');
$username = getenv('DB_USER') ?: ($localConfig['user'] ?? '');
$password = getenv('DB_PASSWORD');
$password = $password !== false ? $password : ($localConfig['password'] ?? '');

if ($username === '' || $password === '') {
    throw new RuntimeException('Database credentials are not configured. See .env.example.');
}

try {
    // ===============================
    // Create PDO connection
    // ===============================
    $conn = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('The database is temporarily unavailable. Please try again later.');
}
