<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

// ===============================
// Validate course ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid course ID.");
}

$course_id = (int)$_GET["id"];

// ===============================
// Helper functions
// ===============================
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

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

// ===============================
// Required table check
// ===============================
if (!tableExists($conn, "study_plan_course_matrix")) {
    die("study_plan_course_matrix table does not exist.");
}

// ===============================
// Load course before deletion
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM study_plan_course_matrix
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $course_id]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Study plan course not found.");
}

$study_plan_id = (int)$course["study_plan_id"];

$courseCode = $course["course_code_en"] ?? "";
if ($courseCode === "") {
    $courseCode = $course["course_code_ar"] ?? "";
}

$courseName = $course["course_name_en"] ?? "";
if ($courseName === "") {
    $courseName = $course["course_name_ar"] ?? "";
}

// ===============================
// Safety checks for future linked tables
// ===============================
$linkedMessages = [];

// Future-proof check: if course specifications later link by study_plan_course_id
if (tableExists($conn, "course_specifications") && columnExists($conn, "course_specifications", "study_plan_course_id")) {
    $checkStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM course_specifications
        WHERE study_plan_course_id = :id
    ");
    $checkStmt->execute([":id" => $course_id]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count > 0) {
        $linkedMessages[] = "course specifications: " . $count;
    }
}

// Future-proof check: if course reports later link by study_plan_course_id
if (tableExists($conn, "course_reports") && columnExists($conn, "course_reports", "study_plan_course_id")) {
    $checkStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM course_reports
        WHERE study_plan_course_id = :id
    ");
    $checkStmt->execute([":id" => $course_id]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count > 0) {
        $linkedMessages[] = "course reports: " . $count;
    }
}

// Stop deletion if linked records exist
if (count($linkedMessages) > 0) {
    die(
        "Cannot delete this course because it is linked to: " .
        implode(", ", $linkedMessages) .
        ". Remove the related links first or keep this course for audit history."
    );
}

// ===============================
// Delete course
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM study_plan_course_matrix
    WHERE id = :id
");
$deleteStmt->execute([":id" => $course_id]);

// ===============================
// Log activity
// ===============================
logActivitySafe(
    $conn,
    "Delete",
    "Study Plan Course Matrix",
    $course_id,
    "Study plan course deleted: " . $courseCode . " - " . $courseName
);

// ===============================
// Redirect
// ===============================
header("Location: manage_study_plan_courses.php?id=" . $study_plan_id);
exit;