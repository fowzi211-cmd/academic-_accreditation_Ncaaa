<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $definitions = [
        'program_specification_plos' => 'plo_code VARCHAR(50) NOT NULL, plo_domain VARCHAR(255) NULL, plo_text TEXT NOT NULL, nqf_level VARCHAR(100) NULL, display_order INT NULL, notes TEXT NULL',
        'program_specification_kpis' => 'kpi_no INT NULL, kpi_code VARCHAR(100) NULL, kpi_name VARCHAR(255) NOT NULL, target_value VARCHAR(255) NULL, measurement_methods TEXT NULL, measurement_time VARCHAR(255) NULL, kpi_type VARCHAR(100) DEFAULT \'Main KPI\', notes TEXT NULL',
        'program_specification_courses' => 'study_plan_course_id INT NULL, level_no INT NULL, course_code VARCHAR(100) NULL, course_title VARCHAR(255) NULL, required_or_elective VARCHAR(100) NULL, prerequisite_courses TEXT NULL, credit_hours DECIMAL(6,2) DEFAULT 0, requirement_type VARCHAR(150) NULL, notes TEXT NULL',
        'program_specification_plo_course_mapping' => 'course_id INT NULL, course_code VARCHAR(100) NOT NULL, plo_code VARCHAR(50) NOT NULL, performance_level VARCHAR(50) NULL, notes TEXT NULL',
        'program_specification_curriculum_structure' => 'structure_category VARCHAR(255) NULL, requirement_type VARCHAR(100) NULL, number_of_courses INT DEFAULT 0, credit_hours DECIMAL(6,2) DEFAULT 0, percentage DECIMAL(6,2) DEFAULT 0, notes TEXT NULL',
        'program_specification_evaluation_matrix' => 'evaluation_area VARCHAR(255) NULL, evaluation_sources TEXT NULL, evaluation_methods TEXT NULL, evaluation_time VARCHAR(255) NULL, notes TEXT NULL',
        'program_specification_staffing_needs' => 'academic_rank VARCHAR(255) NULL, general_specialty TEXT NULL, specific_specialty TEXT NULL, special_requirements TEXT NULL, required_male INT DEFAULT 0, required_female INT DEFAULT 0, required_total INT DEFAULT 0, notes TEXT NULL',
        'program_specification_teaching_strategies' => 'strategy_name VARCHAR(255) NULL, strategy_description TEXT NULL, related_plo_codes TEXT NULL, curricular_or_extra VARCHAR(100) NULL, notes TEXT NULL',
        'program_specification_assessment_methods' => 'assessment_method VARCHAR(255) NULL, assessment_type VARCHAR(100) NULL, assessment_description TEXT NULL, related_plo_codes TEXT NULL, notes TEXT NULL',
        'program_specification_mark_distributions' => 'course_type VARCHAR(150) NULL, assessment_method VARCHAR(255) NULL, marks DECIMAL(6,2) DEFAULT 0, percentage DECIMAL(6,2) DEFAULT 0, display_order INT NULL, notes TEXT NULL',
        'program_specification_tracks' => 'track_name VARCHAR(255) NULL, credit_hours DECIMAL(6,2) DEFAULT 0, professions_jobs TEXT NULL, notes TEXT NULL',
        'program_specification_exit_points' => 'exit_point_name VARCHAR(255) NULL, credit_hours DECIMAL(6,2) DEFAULT 0, notes TEXT NULL',
    ];

    foreach ($definitions as $table => $columns) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `{$table}` ("
            . 'id INT AUTO_INCREMENT PRIMARY KEY,'
            . 'program_specification_id INT NOT NULL,'
            . $columns . ','
            . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . "INDEX `idx_{$table}_spec` (program_specification_id)"
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
