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
// Validate task ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid task ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Helper functions
// ===============================
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
// Check optional related columns
// ===============================
$hasRelatedModule = columnExists($conn, "tasks", "related_module");
$hasRelatedRecordId = columnExists($conn, "tasks", "related_record_id");

// ===============================
// Build SELECT safely
// ===============================
$relatedModuleSelect = $hasRelatedModule ? "related_module" : "NULL AS related_module";
$relatedRecordIdSelect = $hasRelatedRecordId ? "related_record_id" : "NULL AS related_record_id";

// ===============================
// Check task exists before deleting
// ===============================
$stmt = $conn->prepare("
    SELECT 
        *,
        $relatedModuleSelect,
        $relatedRecordIdSelect
    FROM tasks
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $id]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    die("Task not found.");
}

$taskTitle = $task["task_title"] ?? "Unknown task";
$responsiblePerson = $task["responsible_person"] ?? "";
$status = $task["status"] ?? "";
$priority = $task["priority"] ?? "";
$relatedModule = $task["related_module"] ?? "";
$relatedRecordId = $task["related_record_id"] ?? "";

// ===============================
// Delete task
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM tasks
    WHERE id = :id
");
$deleteStmt->execute([":id" => $id]);

// ===============================
// Log activity
// ===============================
$logDescription =
    "Task deleted: " . $taskTitle .
    " | Responsible: " . $responsiblePerson .
    " | Priority: " . $priority .
    " | Status: " . $status;

if (!empty($relatedModule) && !empty($relatedRecordId)) {
    $logDescription .=
        " | Related Module: " . $relatedModule .
        " | Related Record ID: " . $relatedRecordId;
}

logActivitySafe(
    $conn,
    "Delete",
    "Tasks",
    $id,
    $logDescription
);

// ===============================
// Redirect
// ===============================
header("Location: tasks.php");
exit;