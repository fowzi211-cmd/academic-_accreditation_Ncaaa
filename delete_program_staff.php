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
// Validate staff ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid staff ID.");
}

$id = (int)$_GET["id"];

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

if (!tableExists($conn, "program_staff")) {
    die("program_staff table does not exist.");
}

// ===============================
// Load staff record before deleting
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM program_staff
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    die("Program staff record not found.");
}

$staffName = $staff["full_name"] ?? "Unknown staff";

// ===============================
// Check linked records before deleting
// Safer approach: prevent deletion if staff is used in committees/tasks/meetings
// ===============================
$linkedMessages = [];

if (tableExists($conn, "committees")) {
    if (columnExists($conn, "committees", "chair_staff_id")) {
        $checkStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM committees
            WHERE chair_staff_id = :id
        ");
        $checkStmt->execute([":id" => $id]);
        $count = (int)$checkStmt->fetchColumn();

        if ($count > 0) {
            $linkedMessages[] = "committee chair records: " . $count;
        }
    }

    if (columnExists($conn, "committees", "secretary_staff_id")) {
        $checkStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM committees
            WHERE secretary_staff_id = :id
        ");
        $checkStmt->execute([":id" => $id]);
        $count = (int)$checkStmt->fetchColumn();

        if ($count > 0) {
            $linkedMessages[] = "committee secretary records: " . $count;
        }
    }
}

if (tableExists($conn, "committee_members")) {
    $checkStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM committee_members
        WHERE staff_id = :id
    ");
    $checkStmt->execute([":id" => $id]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count > 0) {
        $linkedMessages[] = "committee membership records: " . $count;
    }
}

if (tableExists($conn, "committee_tasks")) {
    if (columnExists($conn, "committee_tasks", "assigned_to_staff_id")) {
        $checkStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM committee_tasks
            WHERE assigned_to_staff_id = :id
        ");
        $checkStmt->execute([":id" => $id]);
        $count = (int)$checkStmt->fetchColumn();

        if ($count > 0) {
            $linkedMessages[] = "committee task records: " . $count;
        }
    }
}

if (tableExists($conn, "committee_meeting_attendance")) {
    $checkStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM committee_meeting_attendance
        WHERE staff_id = :id
    ");
    $checkStmt->execute([":id" => $id]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count > 0) {
        $linkedMessages[] = "meeting attendance records: " . $count;
    }
}

if (tableExists($conn, "committee_recommendations")) {
    if (columnExists($conn, "committee_recommendations", "responsible_staff_id")) {
        $checkStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM committee_recommendations
            WHERE responsible_staff_id = :id
        ");
        $checkStmt->execute([":id" => $id]);
        $count = (int)$checkStmt->fetchColumn();

        if ($count > 0) {
            $linkedMessages[] = "committee recommendation records: " . $count;
        }
    }
}

// ===============================
// Stop deletion if linked records exist
// ===============================
if (count($linkedMessages) > 0) {
    die(
        "Cannot delete this staff profile because it is linked to: " .
        implode(", ", $linkedMessages) .
        ". Set the staff status to Inactive instead, or remove the related committee/task/meeting links first."
    );
}

// ===============================
// Delete staff profile
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM program_staff
    WHERE id = :id
");
$deleteStmt->execute([":id" => $id]);

// ===============================
// Log activity
// ===============================
logActivitySafe(
    $conn,
    "Delete",
    "Program Staff",
    $id,
    "Program staff deleted: " . $staffName
);

// ===============================
// Redirect
// ===============================
header("Location: program_staff.php");
exit;