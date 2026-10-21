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
// Validate committee ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid committee ID.");
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

if (!tableExists($conn, "committees")) {
    die("committees table does not exist.");
}

// ===============================
// Load committee before deleting
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM committees
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $id]);
$committee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$committee) {
    die("Committee not found.");
}

$committeeName = $committee["committee_name"] ?? "Unknown committee";

// ===============================
// Check linked records
// Safer rule: do not delete committee if it has members, meetings,
// recommendations, or tasks. Archive/inactivate instead.
// ===============================
$linkedMessages = [];

$memberCount = countLinkedRecords($conn, "committee_members", "committee_id", $id);
if ($memberCount > 0) {
    $linkedMessages[] = "committee members: " . $memberCount;
}

$meetingCount = countLinkedRecords($conn, "committee_meetings", "committee_id", $id);
if ($meetingCount > 0) {
    $linkedMessages[] = "committee meetings: " . $meetingCount;
}

$recommendationCount = countLinkedRecords($conn, "committee_recommendations", "committee_id", $id);
if ($recommendationCount > 0) {
    $linkedMessages[] = "committee recommendations: " . $recommendationCount;
}

$taskCount = countLinkedRecords($conn, "committee_tasks", "committee_id", $id);
if ($taskCount > 0) {
    $linkedMessages[] = "committee tasks: " . $taskCount;
}

// ===============================
// Stop deletion if linked records exist
// ===============================
if (count($linkedMessages) > 0) {
    die(
        "Cannot delete this committee because it is linked to: " .
        implode(", ", $linkedMessages) .
        ". Set the committee status to Inactive or Archived instead, or remove the related records first."
    );
}

// ===============================
// Delete committee
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM committees
    WHERE id = :id
");
$deleteStmt->execute([":id" => $id]);

// ===============================
// Log activity
// ===============================
logActivitySafe(
    $conn,
    "Delete",
    "Committees",
    $id,
    "Committee deleted: " . $committeeName
);

// ===============================
// Redirect
// ===============================
header("Location: committees.php");
exit;