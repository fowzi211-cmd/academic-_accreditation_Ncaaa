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
// Validate recommendation ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid recommendation ID.");
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

// ===============================
// Required table check
// ===============================
if (!tableExists($conn, "committee_recommendations")) {
    die("committee_recommendations table does not exist.");
}

// ===============================
// Load recommendation before deleting
// ===============================
$stmt = $conn->prepare("
    SELECT
        r.*,
        c.committee_name,
        m.meeting_title
    FROM committee_recommendations r
    LEFT JOIN committees c ON r.committee_id = c.id
    LEFT JOIN committee_meetings m ON r.meeting_id = m.id
    WHERE r.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $id]);
$recommendation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recommendation) {
    die("Committee recommendation not found.");
}

$meetingId = (int)($recommendation["meeting_id"] ?? 0);
$committeeId = (int)($recommendation["committee_id"] ?? 0);
$recommendationText = $recommendation["recommendation_text"] ?? "";

// ===============================
// Safety check: do not delete if linked task exists
// ===============================
if (columnExists($conn, "committee_recommendations", "linked_task_id")) {
    if (!empty($recommendation["linked_task_id"])) {
        die(
            "Cannot delete this recommendation because it is linked to Task ID: " .
            htmlspecialchars((string)$recommendation["linked_task_id"], ENT_QUOTES, "UTF-8") .
            ". Remove the linked task first or edit the recommendation instead."
        );
    }
}

// ===============================
// Delete recommendation
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM committee_recommendations
    WHERE id = :id
");
$deleteStmt->execute([":id" => $id]);

// ===============================
// Log activity
// ===============================
logActivitySafe(
    $conn,
    "Delete",
    "Committee Recommendations",
    $id,
    "Committee recommendation deleted. Committee: " .
    ($recommendation["committee_name"] ?? "") .
    " | Meeting: " .
    ($recommendation["meeting_title"] ?? "") .
    " | Recommendation: " .
    mb_substr($recommendationText, 0, 150)
);

// ===============================
// Redirect
// ===============================
if ($meetingId > 0) {
    header("Location: committee_meeting_profile.php?id=" . $meetingId);
    exit;
}

if ($committeeId > 0) {
    header("Location: committee_profile.php?id=" . $committeeId);
    exit;
}

header("Location: committees.php");
exit;