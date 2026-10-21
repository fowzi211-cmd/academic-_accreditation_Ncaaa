<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $tables = [];

    $tables[] = "CREATE TABLE IF NOT EXISTS study_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_title VARCHAR(255) NOT NULL,
        program_name VARCHAR(255) NULL,
        department_name VARCHAR(255) NULL,
        college_name VARCHAR(255) NULL,
        institution_name VARCHAR(255) NULL,
        plan_version VARCHAR(100) NULL,
        academic_year VARCHAR(100) NULL,
        total_credit_hours INT NULL,
        approval_status VARCHAR(100) NOT NULL DEFAULT 'Draft',
        approval_date DATE NULL,
        notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables[] = "CREATE TABLE IF NOT EXISTS study_plan_course_matrix (
        id INT AUTO_INCREMENT PRIMARY KEY, study_plan_id INT NOT NULL, course_sequence INT NULL, semester_no INT NULL,
        course_code_ar VARCHAR(100) NULL, course_name_ar VARCHAR(255) NULL, course_code_en VARCHAR(100) NULL, course_name_en VARCHAR(255) NULL,
        credit_hours DECIMAL(6,2) NOT NULL DEFAULT 0, course_requirement_type VARCHAR(150) NULL, teaching_mode VARCHAR(150) NULL, course_activity VARCHAR(150) NULL,
        credit_theory DECIMAL(6,2) DEFAULT 0, credit_practical DECIMAL(6,2) DEFAULT 0, credit_studio DECIMAL(6,2) DEFAULT 0,
        credit_training DECIMAL(6,2) DEFAULT 0, credit_graduation_project DECIMAL(6,2) DEFAULT 0, credit_seminar DECIMAL(6,2) DEFAULT 0,
        credit_clinical_training DECIMAL(6,2) DEFAULT 0, credit_educational_applications DECIMAL(6,2) DEFAULT 0,
        credit_clinical_simulation DECIMAL(6,2) DEFAULT 0, credit_other DECIMAL(6,2) DEFAULT 0,
        contact_theory DECIMAL(6,2) DEFAULT 0, contact_practical DECIMAL(6,2) DEFAULT 0, contact_studio DECIMAL(6,2) DEFAULT 0,
        contact_training DECIMAL(6,2) DEFAULT 0, contact_graduation_project DECIMAL(6,2) DEFAULT 0, contact_seminar DECIMAL(6,2) DEFAULT 0,
        contact_clinical_training DECIMAL(6,2) DEFAULT 0, contact_educational_applications DECIMAL(6,2) DEFAULT 0,
        contact_clinical_simulation DECIMAL(6,2) DEFAULT 0, contact_other DECIMAL(6,2) DEFAULT 0,
        prerequisite_1_name VARCHAR(255) NULL, prerequisite_2_name VARCHAR(255) NULL, prerequisite_1_code VARCHAR(100) NULL,
        prerequisite_2_code VARCHAR(100) NULL, prerequisite_other TEXT NULL, responsible_department VARCHAR(255) NULL,
        course_description TEXT NULL, track_name VARCHAR(255) NULL, exit_point VARCHAR(255) NULL, notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_study_plan_course_matrix_plan (study_plan_id), INDEX idx_study_plan_course_matrix_code (course_code_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables[] = "CREATE TABLE IF NOT EXISTS program_specifications (
        id INT AUTO_INCREMENT PRIMARY KEY, study_plan_id INT NULL, specification_title VARCHAR(255) NOT NULL, program_name VARCHAR(255) NOT NULL,
        program_code VARCHAR(100) NULL, qualification_level VARCHAR(150) NULL, department_name VARCHAR(255) NULL, college_name VARCHAR(255) NULL,
        institution_name VARCHAR(255) NULL, specification_version VARCHAR(100) NULL, academic_year VARCHAR(100) NULL, specification_type VARCHAR(100) NULL,
        last_review_date DATE NULL, previous_version_file VARCHAR(500) NULL, main_location TEXT NULL, branches TEXT NULL, partnerships TEXT NULL,
        qualified_jobs TEXT NULL, occupational_sectors TEXT NULL, total_credit_hours INT NULL, program_mission TEXT NULL, program_goals TEXT NULL,
        graduate_attributes TEXT NULL, admission_requirements TEXT NULL, graduation_requirements TEXT NULL, guidance_orientation TEXT NULL,
        counseling_services TEXT NULL, special_support TEXT NULL, teaching_learning_strategy TEXT NULL, assessment_strategy TEXT NULL,
        learning_resources TEXT NULL, facilities_equipment TEXT NULL, healthy_safe_environment TEXT NULL, quality_assurance_arrangements TEXT NULL,
        quality_assurance_system TEXT NULL, quality_manual_reference VARCHAR(500) NULL, quality_courses_other_departments TEXT NULL,
        consistency_between_sections TEXT NULL, plo_assessment_plan TEXT NULL, plo_assessment_plan_reference VARCHAR(500) NULL,
        approval_council_committee VARCHAR(255) NULL, approval_reference_no VARCHAR(255) NULL, approval_date_text VARCHAR(255) NULL,
        approval_date DATE NULL, approval_status VARCHAR(100) DEFAULT 'Draft', status VARCHAR(100) DEFAULT 'Draft', notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_program_specifications_study_plan (study_plan_id), INDEX idx_program_specifications_code (program_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables[] = "CREATE TABLE IF NOT EXISTS program_staff (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, staff_code VARCHAR(100) NULL, full_name VARCHAR(255) NOT NULL,
        academic_rank VARCHAR(150) NULL, qualification VARCHAR(255) NULL, specialty VARCHAR(255) NULL, department VARCHAR(255) NULL,
        college VARCHAR(255) NULL, institution VARCHAR(255) NULL, email VARCHAR(255) NULL, phone VARCHAR(100) NULL,
        employment_status VARCHAR(100) NOT NULL DEFAULT 'Active', role_in_program VARCHAR(150) NULL, notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_program_staff_user (user_id), INDEX idx_program_staff_name (full_name), INDEX idx_program_staff_status (employment_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables[] = "CREATE TABLE IF NOT EXISTS committees (
        id INT AUTO_INCREMENT PRIMARY KEY, committee_name VARCHAR(255) NOT NULL, committee_type VARCHAR(150) NULL, academic_year VARCHAR(100) NULL,
        formation_reference VARCHAR(255) NULL, formation_date DATE NULL, chair_staff_id INT NULL, secretary_staff_id INT NULL,
        mandate TEXT NULL, status VARCHAR(100) NOT NULL DEFAULT 'Active', notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_committees_status (status), INDEX idx_committees_year (academic_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables[] = "CREATE TABLE IF NOT EXISTS committee_members (
        id INT AUTO_INCREMENT PRIMARY KEY, committee_id INT NOT NULL, staff_id INT NOT NULL, member_role VARCHAR(100) DEFAULT 'Member',
        start_date DATE NULL, end_date DATE NULL, status VARCHAR(100) DEFAULT 'Active', notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_committee_member (committee_id, staff_id),
        INDEX idx_committee_members_staff (staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables[] = "CREATE TABLE IF NOT EXISTS committee_meetings (
        id INT AUTO_INCREMENT PRIMARY KEY, committee_id INT NOT NULL, meeting_title VARCHAR(255) NOT NULL, meeting_date DATE NULL,
        meeting_time TIME NULL, location VARCHAR(255) NULL, agenda TEXT NULL, minutes_summary TEXT NULL,
        status VARCHAR(100) DEFAULT 'Draft', notes TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_committee_meetings_committee (committee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables[] = "CREATE TABLE IF NOT EXISTS committee_meeting_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY, meeting_id INT NOT NULL, staff_id INT NOT NULL, attendance_status VARCHAR(100) DEFAULT 'Present',
        notes TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_meeting_staff (meeting_id, staff_id),
        INDEX idx_attendance_staff (staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables[] = "CREATE TABLE IF NOT EXISTS committee_recommendations (
        id INT AUTO_INCREMENT PRIMARY KEY, meeting_id INT NULL, committee_id INT NOT NULL, recommendation_text TEXT NOT NULL,
        responsible_staff_id INT NULL, target_date DATE NULL, priority VARCHAR(50) DEFAULT 'Medium', status VARCHAR(100) DEFAULT 'Open',
        linked_evidence_id INT NULL, followup_notes TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_recommendations_committee (committee_id), INDEX idx_recommendations_meeting (meeting_id), INDEX idx_recommendations_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables[] = "CREATE TABLE IF NOT EXISTS committee_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY, committee_id INT NOT NULL, recommendation_id INT NULL, assigned_to_staff_id INT NULL,
        linked_task_id INT NULL, task_title VARCHAR(255) NOT NULL, task_description TEXT NULL, due_date DATE NULL,
        priority VARCHAR(50) DEFAULT 'Medium', status VARCHAR(100) DEFAULT 'Pending', notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_committee_tasks_committee (committee_id), INDEX idx_committee_tasks_staff (assigned_to_staff_id), INDEX idx_committee_tasks_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
};
