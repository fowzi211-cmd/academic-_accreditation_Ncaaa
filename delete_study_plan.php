<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator"]);

// ===============================
// Validate study plan ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid study plan ID.");
}

$study_plan_id = (int)$_GET["id"];

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

function countLinkedRecords($conn, $tableName, $columnName, $id) {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    if (!columnExists($conn, $tableName, $columnName)) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM $tableName
        WHERE $columnName = :id
    ");
    $stmt->execute([":id" => $id]);

    return (int)$stmt->fetchColumn();
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

// ===============================
// Required table checks
// ===============================
if (!tableExists($conn, "study_plans")) {
    die("study_plans table does not exist.");
}

if (!tableExists($conn, "study_plan_course_matrix")) {
    die("study_plan_course_matrix table does not exist.");
}

// ===============================
// Load study plan before deletion
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM study_plans
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $study_plan_id]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    die("Study plan not found.");
}

$planTitle = $plan["plan_title"] ?? "Unknown study plan";

// ===============================
// Safety checks
// ===============================
$linkedMessages = [];

$courseCount = countLinkedRecords($conn, "study_plan_course_matrix", "study_plan_id", $study_plan_id);
if ($courseCount > 0) {
    $linkedMessages[] = "study plan courses: " . $courseCount;
}

// Future-proof check: if program specifications later link to study plans
$programSpecCount = countLinkedRecords($conn, "program_specifications", "study_plan_id", $study_plan_id);
if ($programSpecCount > 0) {
    $linkedMessages[] = "program specifications: " . $programSpecCount;
}

// Future-proof check: if course specifications later link to study plans
$courseSpecCount = countLinkedRecords($conn, "course_specifications", "study_plan_id", $study_plan_id);
if ($courseSpecCount > 0) {
    $linkedMessages[] = "course specifications: " . $courseSpecCount;
}

// Future-proof check: if course reports later link to study plans
$courseReportCount = countLinkedRecords($conn, "course_reports", "study_plan_id", $study_plan_id);
if ($courseReportCount > 0) {
    $linkedMessages[] = "course reports: " . $courseReportCount;
}

// ===============================
// Stop deletion if linked records exist
// ===============================
if (count($linkedMessages) > 0) {
    die(
        "Cannot delete this study plan because it is linked to: " .
        implode(", ", $linkedMessages) .
        ". Delete the linked records first, or set the study plan status to Archived instead."
    );
}

// ===============================
// Delete study plan
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM study_plans
    WHERE id = :id
");
$deleteStmt->execute([":id" => $study_plan_id]);

// ===============================
// Log activity
// ===============================
logActivitySafe(
    $conn,
    "Delete",
    "Study Plans",
    $study_plan_id,
    "Study plan deleted: " . $planTitle
);

// ===============================
// Redirect
// ===============================
header("Location: study_plans.php");
exit;