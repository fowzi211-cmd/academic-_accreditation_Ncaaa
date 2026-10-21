<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS course_specification_files ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,'
        . 'course_specification_id INT NULL,'
        . 'program_specification_id INT NULL,'
        . 'course_code VARCHAR(100) NULL,'
        . 'original_filename VARCHAR(255) NULL,'
        . 'stored_filename VARCHAR(255) NULL,'
        . 'file_path VARCHAR(500) NOT NULL,'
        . 'file_type VARCHAR(50) NULL,'
        . 'mime_type VARCHAR(150) NULL,'
        . 'file_size BIGINT NOT NULL DEFAULT 0,'
        . 'uploaded_by INT NULL,'
        . 'notes TEXT NULL,'
        . 'upload_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'INDEX idx_course_specification_files_course (course_specification_id),'
        . 'INDEX idx_course_specification_files_program (program_specification_id),'
        . 'INDEX idx_course_specification_files_code (course_code)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
};
