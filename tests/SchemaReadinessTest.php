<?php

declare(strict_types=1);

namespace QualitySystem\Tests;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class SchemaReadinessTest extends TestCase
{
    #[Group('database')]
    public function testRequiredModuleTablesAndMigrationsExist(): void
    {
        $config = require 'C:\\quality_system_config\\database.php';
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['name']),
            $config['user'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $requiredTables = [
            'committees', 'committee_meetings', 'committee_meeting_attendance', 'committee_members',
            'committee_recommendations', 'committee_tasks', 'course_reports', 'course_report_student_results',
            'course_specifications', 'course_specification_files', 'program_specifications',
            'program_specification_courses', 'program_specification_plos', 'program_specification_kpis',
            'program_specification_plo_course_mapping', 'program_specification_curriculum_structure',
            'program_specification_evaluation_matrix', 'program_specification_staffing_needs',
            'program_specification_teaching_strategies', 'program_specification_assessment_methods',
            'program_specification_mark_distributions', 'program_specification_tracks',
            'program_specification_exit_points', 'program_staff', 'study_plans', 'study_plan_course_matrix',
            'program_manuals', 'program_manual_sections', 'program_manual_reconciliations',
            'program_manual_plos', 'program_manual_courses',
            'program_manual_entities', 'program_manual_mappings', 'program_manual_notifications',
            'program_course_clos', 'program_plo_clo_matrix',
            'committee_meeting_agenda_items', 'committee_recommendation_approvals',
        ];

        $statement = $pdo->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()"
        );
        $actualTables = $statement->fetchAll(PDO::FETCH_COLUMN);

        foreach ($requiredTables as $table) {
            self::assertContains($table, $actualTables, "Required table is missing: {$table}");
        }

        $migrations = $pdo->query('SELECT migration FROM schema_migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([
            '20260826_001_course_specification_files',
            '20260826_002_legacy_module_base_tables',
            '20260826_003_program_specification_dependents',
            '20260826_004_runtime_schema_delta',
            '20260827_005_security_operations',
            '20260827_006_task_authorization',
            '20260827_007_course_report_authorization',
            '20260827_008_committee_recommendation_task_link',
            '20260828_009_program_manuals',
            '20260828_010_program_manual_reconciliation',
            '20260828_011_program_manual_details',
            '20260828_012_program_manual_automation',
            '20260828_013_program_plo_clo_matrix',
            '20260828_014_plo_clo_alignment_levels',
            '20260829_015_consultancy_committee_workflow',
            '20260829_016_committee_attendance_certification',
        ], $migrations);

        $recommendationColumns = $pdo->query('SHOW COLUMNS FROM committee_recommendations')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('linked_task_id', $recommendationColumns);
    }
}
