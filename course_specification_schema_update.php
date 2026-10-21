<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
        ");
        $stmt->execute([":table_name" => $tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function columnExists($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND column_name = :column_name
        ");
        $stmt->execute([
            ":table_name" => $tableName,
            ":column_name" => $columnName
        ]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function addColumnIfMissing($conn, $tableName, $columnName, $definition) {
    if (!columnExists($conn, $tableName, $columnName)) {
        requireSchemaColumn($conn, $tableName, $columnName);
        return true;
    }
    return false;
}

function createIndexIfMissing($conn, $tableName, $indexName, $columns) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND index_name = :index_name
        ");
        $stmt->execute([
            ":table_name" => $tableName,
            ":index_name" => $indexName
        ]);

        if ((int)$stmt->fetchColumn() === 0) {
            throw new RuntimeException("Database index '$indexName' is missing. Run: php database/migrate.php");
        }
    } catch (Exception $e) {
        return false;
    }

    return false;
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

$messages = [];

try {
    
    /*
        Main course_specifications table.
        This stores the core NCAAA course specification identity and narrative sections.
    */
    if (!tableExists($conn, "course_specifications")) {
        requireSchemaTable($conn, "course_specifications");
        $messages[] = "Created table: course_specifications";
    } else {
        $messages[] = "Table exists: course_specifications";
    }

    $mainColumns = [
        "program_specification_id" => "INT NULL",
        "program_course_id" => "INT NULL",
        "course_code" => "VARCHAR(100) NULL",
        "course_title" => "VARCHAR(255) NULL",
        "program_name" => "VARCHAR(255) NULL",
        "department_name" => "VARCHAR(255) NULL",
        "college_name" => "VARCHAR(255) NULL",
        "institution_name" => "VARCHAR(255) NULL",
        "version_no" => "VARCHAR(50) NULL",
        "last_revision_date" => "DATE NULL",
        "last_revision_date_text" => "VARCHAR(100) NULL",
        "credit_hours" => "DECIMAL(6,2) DEFAULT 0",
        "theoretical_hours" => "DECIMAL(6,2) DEFAULT 0",
        "practical_hours" => "DECIMAL(6,2) DEFAULT 0",
        "field_hours" => "DECIMAL(6,2) DEFAULT 0",
        "course_level_year" => "VARCHAR(100) NULL",
        "course_type_scope" => "VARCHAR(100) NULL",
        "requirement_type" => "VARCHAR(100) NULL",
        "course_general_description" => "TEXT NULL",
        "pre_requisites" => "TEXT NULL",
        "co_requisites" => "TEXT NULL",
        "course_main_objectives" => "TEXT NULL",
        "teaching_mode_traditional_hours" => "DECIMAL(6,2) DEFAULT 0",
        "teaching_mode_traditional_percentage" => "DECIMAL(6,2) DEFAULT 0",
        "teaching_mode_elearning_hours" => "DECIMAL(6,2) DEFAULT 0",
        "teaching_mode_elearning_percentage" => "DECIMAL(6,2) DEFAULT 0",
        "teaching_mode_hybrid_hours" => "DECIMAL(6,2) DEFAULT 0",
        "teaching_mode_hybrid_percentage" => "DECIMAL(6,2) DEFAULT 0",
        "teaching_mode_distance_hours" => "DECIMAL(6,2) DEFAULT 0",
        "teaching_mode_distance_percentage" => "DECIMAL(6,2) DEFAULT 0",
        "contact_lectures" => "DECIMAL(6,2) DEFAULT 0",
        "contact_laboratory" => "DECIMAL(6,2) DEFAULT 0",
        "contact_field" => "DECIMAL(6,2) DEFAULT 0",
        "contact_tutorial" => "DECIMAL(6,2) DEFAULT 0",
        "contact_others" => "DECIMAL(6,2) DEFAULT 0",
        "contact_total" => "DECIMAL(6,2) DEFAULT 0",
        "status" => "VARCHAR(100) DEFAULT 'Draft'",
        "approval_status" => "VARCHAR(100) DEFAULT 'Draft'",
        "notes" => "TEXT NULL",
        "created_at" => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        "updated_at" => "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP"
    ];

    foreach ($mainColumns as $column => $definition) {
        if (addColumnIfMissing($conn, "course_specifications", $column, $definition)) {
            $messages[] = "Added column: course_specifications.$column";
        }
    }

    createIndexIfMissing($conn, "course_specifications", "idx_course_spec_program_spec", "`program_specification_id`");
    createIndexIfMissing($conn, "course_specifications", "idx_course_spec_program_course", "`program_course_id`");
    createIndexIfMissing($conn, "course_specifications", "idx_course_spec_course_code", "`course_code`");

    /*
        CLOs table.
    */
    if (!tableExists($conn, "course_specification_clos")) {
        requireSchemaTable($conn, "course_specification_clos");
        $messages[] = "Created table: course_specification_clos";
    } else {
        $messages[] = "Table exists: course_specification_clos";
    }

    $cloColumns = [
        "course_specification_id" => "INT NOT NULL",
        "clo_code" => "VARCHAR(50) NULL",
        "clo_domain" => "VARCHAR(150) NULL",
        "clo_text" => "TEXT NULL",
        "aligned_plo_code" => "VARCHAR(100) NULL",
        "teaching_strategies" => "TEXT NULL",
        "assessment_methods" => "TEXT NULL",
        "display_order" => "INT DEFAULT 0",
        "created_at" => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];

    foreach ($cloColumns as $column => $definition) {
        if (addColumnIfMissing($conn, "course_specification_clos", $column, $definition)) {
            $messages[] = "Added column: course_specification_clos.$column";
        }
    }

    createIndexIfMissing($conn, "course_specification_clos", "idx_course_clos_spec", "`course_specification_id`");

    /*
        Course topics table.
    */
    if (!tableExists($conn, "course_specification_topics")) {
        requireSchemaTable($conn, "course_specification_topics");
        $messages[] = "Created table: course_specification_topics";
    } else {
        $messages[] = "Table exists: course_specification_topics";
    }

    $topicColumns = [
        "course_specification_id" => "INT NOT NULL",
        "topic_no" => "INT NULL",
        "topic_title" => "TEXT NULL",
        "contact_hours" => "DECIMAL(6,2) DEFAULT 0",
        "display_order" => "INT DEFAULT 0",
        "created_at" => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];

    foreach ($topicColumns as $column => $definition) {
        if (addColumnIfMissing($conn, "course_specification_topics", $column, $definition)) {
            $messages[] = "Added column: course_specification_topics.$column";
        }
    }

    createIndexIfMissing($conn, "course_specification_topics", "idx_course_topics_spec", "`course_specification_id`");

    /*
        Student assessment activities.
    */
    if (!tableExists($conn, "course_specification_assessments")) {
        requireSchemaTable($conn, "course_specification_assessments");
        $messages[] = "Created table: course_specification_assessments";
    } else {
        $messages[] = "Table exists: course_specification_assessments";
    }

    $assessmentColumns = [
        "course_specification_id" => "INT NOT NULL",
        "assessment_no" => "INT NULL",
        "assessment_activity" => "VARCHAR(255) NULL",
        "assessment_timing" => "VARCHAR(100) NULL",
        "percentage" => "DECIMAL(6,2) DEFAULT 0",
        "notes" => "TEXT NULL",
        "display_order" => "INT DEFAULT 0",
        "created_at" => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];

    foreach ($assessmentColumns as $column => $definition) {
        if (addColumnIfMissing($conn, "course_specification_assessments", $column, $definition)) {
            $messages[] = "Added column: course_specification_assessments.$column";
        }
    }

    createIndexIfMissing($conn, "course_specification_assessments", "idx_course_assessments_spec", "`course_specification_id`");

    /*
        Learning resources table.
    */
    if (!tableExists($conn, "course_specification_resources")) {
        requireSchemaTable($conn, "course_specification_resources");
        $messages[] = "Created table: course_specification_resources";
    } else {
        $messages[] = "Table exists: course_specification_resources";
    }

    $resourceColumns = [
        "course_specification_id" => "INT NOT NULL",
        "resource_category" => "VARCHAR(150) NULL",
        "resource_details" => "TEXT NULL",
        "display_order" => "INT DEFAULT 0",
        "created_at" => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];

    foreach ($resourceColumns as $column => $definition) {
        if (addColumnIfMissing($conn, "course_specification_resources", $column, $definition)) {
            $messages[] = "Added column: course_specification_resources.$column";
        }
    }

    createIndexIfMissing($conn, "course_specification_resources", "idx_course_resources_spec", "`course_specification_id`");

    /*
        Facilities and equipment table.
    */
    if (!tableExists($conn, "course_specification_facilities")) {
        requireSchemaTable($conn, "course_specification_facilities");
        $messages[] = "Created table: course_specification_facilities";
    } else {
        $messages[] = "Table exists: course_specification_facilities";
    }

    $facilityColumns = [
        "course_specification_id" => "INT NOT NULL",
        "facility_category" => "VARCHAR(150) NULL",
        "required_resources" => "TEXT NULL",
        "display_order" => "INT DEFAULT 0",
        "created_at" => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];

    foreach ($facilityColumns as $column => $definition) {
        if (addColumnIfMissing($conn, "course_specification_facilities", $column, $definition)) {
            $messages[] = "Added column: course_specification_facilities.$column";
        }
    }

    createIndexIfMissing($conn, "course_specification_facilities", "idx_course_facilities_spec", "`course_specification_id`");

    /*
        Assessment of course quality.
    */
    if (!tableExists($conn, "course_specification_quality")) {
        requireSchemaTable($conn, "course_specification_quality");
        $messages[] = "Created table: course_specification_quality";
    } else {
        $messages[] = "Table exists: course_specification_quality";
    }

    $qualityColumns = [
        "course_specification_id" => "INT NOT NULL",
        "assessment_area" => "VARCHAR(255) NULL",
        "assessor" => "VARCHAR(255) NULL",
        "assessment_method" => "VARCHAR(255) NULL",
        "notes" => "TEXT NULL",
        "display_order" => "INT DEFAULT 0",
        "created_at" => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];

    foreach ($qualityColumns as $column => $definition) {
        if (addColumnIfMissing($conn, "course_specification_quality", $column, $definition)) {
            $messages[] = "Added column: course_specification_quality.$column";
        }
    }

    createIndexIfMissing($conn, "course_specification_quality", "idx_course_quality_spec", "`course_specification_id`");

    /*
        Approval table.
    */
    if (!tableExists($conn, "course_specification_approval")) {
        requireSchemaTable($conn, "course_specification_approval");
        $messages[] = "Created table: course_specification_approval";
    } else {
        $messages[] = "Table exists: course_specification_approval";
    }

    $approvalColumns = [
        "course_specification_id" => "INT NOT NULL",
        "council_committee" => "VARCHAR(255) NULL",
        "reference_no" => "VARCHAR(255) NULL",
        "approval_date_text" => "VARCHAR(255) NULL",
        "approval_date" => "DATE NULL",
        "notes" => "TEXT NULL",
        "created_at" => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];

    foreach ($approvalColumns as $column => $definition) {
        if (addColumnIfMissing($conn, "course_specification_approval", $column, $definition)) {
            $messages[] = "Added column: course_specification_approval.$column";
        }
    }

    createIndexIfMissing($conn, "course_specification_approval", "idx_course_approval_spec", "`course_specification_id`");

    /*
        Uploaded Word/PDF files table.
    */
    if (!tableExists($conn, "course_specification_files")) {
        requireSchemaTable($conn, "course_specification_files");
        $messages[] = "Created table: course_specification_files";
    } else {
        $messages[] = "Table exists: course_specification_files";
    }

    $fileColumns = [
        "course_specification_id" => "INT NULL",
        "program_specification_id" => "INT NULL",
        "course_code" => "VARCHAR(100) NULL",
        "original_filename" => "VARCHAR(255) NULL",
        "stored_filename" => "VARCHAR(255) NULL",
        "file_path" => "VARCHAR(500) NULL",
        "file_type" => "VARCHAR(50) NULL",
        "mime_type" => "VARCHAR(150) NULL",
        "file_size" => "BIGINT DEFAULT 0",
        "uploaded_by" => "INT NULL",
        "upload_date" => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        "notes" => "TEXT NULL"
    ];

    foreach ($fileColumns as $column => $definition) {
        if (addColumnIfMissing($conn, "course_specification_files", $column, $definition)) {
            $messages[] = "Added column: course_specification_files.$column";
        }
    }

    createIndexIfMissing($conn, "course_specification_files", "idx_course_files_spec", "`course_specification_id`");
    createIndexIfMissing($conn, "course_specification_files", "idx_course_files_program_spec", "`program_specification_id`");
    createIndexIfMissing($conn, "course_specification_files", "idx_course_files_code", "`course_code`");

    
    logActivitySafe(
        $conn,
        "Schema Update",
        "Course Specification",
        0,
        "Course specification schema updated successfully."
    );

} catch (Exception $e) {
    die("Schema update failed: " . safeText($e->getMessage()));
}

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Specification Schema Update</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
        }

        .success-box {
            background: #d1e7dd;
            color: #0f5132;
            border: 1px solid #badbcc;
            padding: 14px;
            border-radius: 8px;
            font-weight: bold;
            margin-bottom: 18px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-bottom: 18px;
        }

        .actions a {
            display: inline-block;
            width: auto;
            text-align: center;
            margin-bottom: 0;
        }

        .message-list {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 12px;
            max-height: 420px;
            overflow-y: auto;
        }

        .message-list li {
            margin-bottom: 5px;
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Course Specification Schema Update</h1>
        <p>Creates and upgrades the database tables needed for NCAAA-style course specifications.</p>
    </div>

    <div class="actions">
        <a href="program_specification_dashboard.php?id=3" class="btn btn-purple">Program Spec Dashboard</a>
        <a href="program_specifications.php" class="btn btn-secondary">Program Specifications</a>
        <a href="course_specifications.php" class="btn">Course Specifications</a>
    </div>

    <div class="success-box">
        Course specification schema update completed successfully.
    </div>

    <div class="panel">
        <h2>Created / Checked Tables</h2>

        <table>
            <tr>
                <th>Table</th>
                <th>Purpose</th>
            </tr>
            <tr>
                <td>course_specifications</td>
                <td>Main course specification record: identity, description, objectives, teaching/contact hours, status.</td>
            </tr>
            <tr>
                <td>course_specification_clos</td>
                <td>CLOs, PLO alignment, teaching strategies, and assessment methods.</td>
            </tr>
            <tr>
                <td>course_specification_topics</td>
                <td>Course content topics and contact hours.</td>
            </tr>
            <tr>
                <td>course_specification_assessments</td>
                <td>Student assessment activities, timing, and percentage.</td>
            </tr>
            <tr>
                <td>course_specification_resources</td>
                <td>Essential references, supportive references, electronic materials, and other learning materials.</td>
            </tr>
            <tr>
                <td>course_specification_facilities</td>
                <td>Facilities, technology equipment, and other required equipment.</td>
            </tr>
            <tr>
                <td>course_specification_quality</td>
                <td>Assessment of course quality areas, assessors, and methods.</td>
            </tr>
            <tr>
                <td>course_specification_approval</td>
                <td>Approval council/committee, reference number, and approval date.</td>
            </tr>
            <tr>
                <td>course_specification_files</td>
                <td>Uploaded Word/PDF course specification files.</td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Schema Messages</h2>

        <ul class="message-list">
            <?php foreach ($messages as $message): ?>
                <li><?php echo safeText($message); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="panel">
        <h2>Next Step</h2>

        <p>
            Now build the file upload page for individual course specification Word/PDF files.
        </p>

        <p>
            Next file:
        </p>

        <pre>notepad C:\quality_system\upload_course_specification_file.php</pre>
    </div>

</div>

</body>
</html>
