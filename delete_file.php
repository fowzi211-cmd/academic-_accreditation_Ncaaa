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
// Validate file ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid file ID.");
}

$file_id = (int)$_GET["id"];

// ===============================
// Load file record before deleting
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM evidence_files
    WHERE id = :id
");
$stmt->execute([":id" => $file_id]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    die("File record not found.");
}

$evidence_id = (int)($file["evidence_id"] ?? 0);
$file_name = $file["file_name"] ?? "";
$file_path = $file["file_path"] ?? "";

// ===============================
// Delete physical file safely
// ===============================
$full_path = storagePath($file_path);

if ($file_path !== "" && file_exists($full_path) && is_file($full_path)) {
    unlink($full_path);
}

// ===============================
// Delete database record
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM evidence_files
    WHERE id = :id
");
$deleteStmt->execute([":id" => $file_id]);

// ===============================
// Log activity
// ===============================
logActivity(
    $conn,
    "Delete",
    "Evidence Files",
    $file_id,
    "File deleted from evidence ID " . $evidence_id . ": " . $file_name
);

// ===============================
// Redirect back to upload page
// ===============================
header("Location: upload_file.php?id=" . $evidence_id);
exit;
