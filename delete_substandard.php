<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole("Admin");

// ===============================
// Validate substandard ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid substandard ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Check substandard exists
// ===============================
$substandardStmt = $conn->prepare("
    SELECT 
        ss.*,
        s.standard_code,
        s.standard_title
    FROM substandards ss
    LEFT JOIN standards s ON ss.standard_id = s.id
    WHERE ss.id = :id
");
$substandardStmt->execute([":id" => $id]);
$substandard = $substandardStmt->fetch(PDO::FETCH_ASSOC);

if (!$substandard) {
    die("Substandard not found.");
}

$substandardLabel =
    ($substandard["standard_code"] ?? "") . " - " .
    ($substandard["substandard_code"] ?? "") . " | " .
    ($substandard["substandard_text"] ?? "");

// ===============================
// Prevent deletion if evidence is linked
// ===============================
$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM evidence_items
    WHERE substandard_id = :id
");
$stmt->execute([":id" => $id]);
$evidenceCount = (int)$stmt->fetchColumn();

if ($evidenceCount > 0) {
    die("Cannot delete this substandard because evidence items are linked to it. Remove or reassign the evidence first.");
}

// ===============================
// Prevent deletion if tasks are linked
// ===============================
$taskStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM tasks
    WHERE substandard_id = :id
");
$taskStmt->execute([":id" => $id]);
$taskCount = (int)$taskStmt->fetchColumn();

if ($taskCount > 0) {
    die("Cannot delete this substandard because tasks are linked to it. Remove or reassign the tasks first.");
}

// ===============================
// Delete substandard
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM substandards
    WHERE id = :id
");
$deleteStmt->execute([":id" => $id]);

// ===============================
// Log activity
// ===============================
logActivity(
    $conn,
    "Delete",
    "Substandards",
    $id,
    "Substandard deleted: " . $substandardLabel
);

// ===============================
// Redirect
// ===============================
header("Location: substandards.php");
exit;