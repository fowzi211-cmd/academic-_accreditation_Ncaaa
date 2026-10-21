<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        identity_hash CHAR(64) NOT NULL,
        ip_hash CHAR(64) NOT NULL,
        succeeded TINYINT(1) NOT NULL DEFAULT 0,
        attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_login_attempt_identity_time (identity_hash, attempted_at),
        KEY idx_login_attempt_ip_time (ip_hash, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS security_audit_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(100) NOT NULL,
        outcome VARCHAR(30) NOT NULL,
        user_id INT NULL,
        actor_role VARCHAR(50) NULL,
        module_name VARCHAR(100) NOT NULL,
        record_type VARCHAR(100) NULL,
        record_id VARCHAR(100) NULL,
        request_id CHAR(32) NOT NULL,
        ip_hash CHAR(64) NULL,
        metadata_json LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_security_audit_event_time (event_type, created_at),
        KEY idx_security_audit_user_time (user_id, created_at),
        KEY idx_security_audit_request (request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('failed_login_count', $columns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER status');
    }
    if (!in_array('locked_until', $columns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN locked_until DATETIME NULL AFTER failed_login_count');
    }
    if (!in_array('last_login_at', $columns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER locked_until');
    }
};
