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
// Validate standard ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid standard ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Check standard exists
// ===============================
$standardStmt = $conn->prepare("
    SELECT *
    FROM standards
    WHERE id = :id
");
$standardStmt->execute([":id" => $id]);
$standard = $standardStmt->fetch(PDO::FETCH_ASSOC);

if (!$standard) {
    die("Standard not found.");
}

$standardLabel = ($standard["standard_code"] ?? "") . " - " . ($standard["standard_title"] ?? "");

// ===============================
// Prevent deletion if standard has substandards
// ===============================
$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM substandards
    WHERE standard_id = :id
");
$stmt->execute([":id" => $id]);
$substandardCount = (int)$stmt->fetchColumn();

if ($substandardCount > 0) {
    die("Cannot delete this standard because it has substandards linked to it. Delete or move the substandards first.");
}

// ===============================
// Delete standard
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM standards
    WHERE id = :id
");
$deleteStmt->execute([":id" => $id]);

// ===============================
// Log activity
// ===============================
logActivity(
    $conn,
    "Delete",
    "Standards",
    $id,
    "Standard deleted: " . $standardLabel
);

// ===============================
// Redirect
// ===============================
header("Location: standards.php");
exit;