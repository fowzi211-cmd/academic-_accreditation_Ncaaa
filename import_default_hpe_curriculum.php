<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid program specification ID.");
}

$spec_id = (int)$_GET["id"];

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
    }
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "program_specification_curriculum_structure")) {
    die("program_specification_curriculum_structure table does not exist.");
}

addColumnIfMissing($conn, "program_specification_curriculum_structure", "structure_category", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_curriculum_structure", "requirement_type", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "program_specification_curriculum_structure", "number_of_courses", "INT DEFAULT 0");
addColumnIfMissing($conn, "program_specification_curriculum_structure", "credit_hours", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "program_specification_curriculum_structure", "percentage", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "program_specification_curriculum_structure", "notes", "TEXT NULL");

$specStmt = $conn->prepare("
    SELECT *
    FROM program_specifications
    WHERE id = :id
    LIMIT 1
");
$specStmt->execute([":id" => $spec_id]);
$spec = $specStmt->fetch(PDO::FETCH_ASSOC);

if (!$spec) {
    die("Program specification not found.");
}

/*
    HPE curriculum structure based on the program structure:
    Total credits = 134

    Institution / University Requirements:
    Required: 10 courses, 18 credits
    Elective: 1 course, 2 credits

    College Requirements:
    Required: 8 courses, 24 credits

    Program Requirements:
    Required: 28 courses, 84 credits

    Capstone / Graduation Project:
    Required: 2 courses, 6 credits

    Internship:
    Required: 1 course, 0 credits
*/

$defaultRows = [
    [
        "structure_category" => "Institution / University Requirements",
        "requirement_type" => "Required",
        "number_of_courses" => 10,
        "credit_hours" => 18,
        "percentage" => 13.43,
        "notes" => "University required courses."
    ],
    [
        "structure_category" => "Institution / University Requirements",
        "requirement_type" => "Elective",
        "number_of_courses" => 1,
        "credit_hours" => 2,
        "percentage" => 1.49,
        "notes" => "University elective course."
    ],
    [
        "structure_category" => "College Requirements",
        "requirement_type" => "Required",
        "number_of_courses" => 8,
        "credit_hours" => 24,
        "percentage" => 17.91,
        "notes" => "College required courses."
    ],
    [
        "structure_category" => "College Requirements",
        "requirement_type" => "Elective",
        "number_of_courses" => 0,
        "credit_hours" => 0,
        "percentage" => 0,
        "notes" => "No college elective courses."
    ],
    [
        "structure_category" => "Program Requirements",
        "requirement_type" => "Required",
        "number_of_courses" => 28,
        "credit_hours" => 84,
        "percentage" => 62.68,
        "notes" => "Program required specialized courses."
    ],
    [
        "structure_category" => "Program Requirements",
        "requirement_type" => "Elective",
        "number_of_courses" => 0,
        "credit_hours" => 0,
        "percentage" => 0,
        "notes" => "No program elective courses."
    ],
    [
        "structure_category" => "Capstone / Graduation Project",
        "requirement_type" => "Required",
        "number_of_courses" => 2,
        "credit_hours" => 6,
        "percentage" => 4.48,
        "notes" => "Graduation Project 1 and Graduation Project 2."
    ],
    [
        "structure_category" => "Field Training / Internship",
        "requirement_type" => "Required",
        "number_of_courses" => 1,
        "credit_hours" => 0,
        "percentage" => 0,
        "notes" => "Internship is recorded as a required zero-credit component."
    ],
    [
        "structure_category" => "Total",
        "requirement_type" => "N/A",
        "number_of_courses" => 50,
        "credit_hours" => 134,
        "percentage" => 100,
        "notes" => "Total program curriculum structure."
    ]
];

try {
    $conn->beginTransaction();

    $deleteStmt = $conn->prepare("
        DELETE FROM program_specification_curriculum_structure
        WHERE program_specification_id = :program_specification_id
    ");
    $deleteStmt->execute([
        ":program_specification_id" => $spec_id
    ]);

    $insertStmt = $conn->prepare("
        INSERT INTO program_specification_curriculum_structure
        (
            program_specification_id,
            structure_category,
            requirement_type,
            number_of_courses,
            credit_hours,
            percentage,
            notes
        )
        VALUES
        (
            :program_specification_id,
            :structure_category,
            :requirement_type,
            :number_of_courses,
            :credit_hours,
            :percentage,
            :notes
        )
    ");

    $inserted = 0;

    foreach ($defaultRows as $row) {
        $insertStmt->execute([
            ":program_specification_id" => $spec_id,
            ":structure_category" => $row["structure_category"],
            ":requirement_type" => $row["requirement_type"],
            ":number_of_courses" => $row["number_of_courses"],
            ":credit_hours" => $row["credit_hours"],
            ":percentage" => $row["percentage"],
            ":notes" => $row["notes"]
        ]);

        $inserted++;
    }

    $conn->commit();

    logActivitySafe(
        $conn,
        "Import",
        "Program Specification Curriculum Structure",
        $spec_id,
        "Default HPE curriculum structure imported. Rows inserted: " . $inserted
    );

    header("Location: manage_program_specification_curriculum.php?id=" . $spec_id);
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    die("Import failed: " . safeText($e->getMessage()));
}
