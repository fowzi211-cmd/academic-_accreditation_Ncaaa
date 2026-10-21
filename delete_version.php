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
// Validate version ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid version ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Load version before deleting
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM system_versions
    WHERE id = :id
");
$stmt->execute([":id" => $id]);
$version = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$version) {
    die("System version not found.");
}

$versionName = $version["version_name"] ?? "Unknown version";

// ===============================
// Delete version record
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM system_versions
    WHERE id = :id
");
$deleteStmt->execute([":id" => $id]);

// ===============================
// Log activity
// ===============================
logActivity(
    $conn,
    "Delete",
    "System Versions",
    $id,
    "System version deleted: " . $versionName
);

// ===============================
// Redirect
// ===============================
header("Location: versions.php");
exit;