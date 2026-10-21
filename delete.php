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
// Validate evidence ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid evidence ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Load evidence before deleting
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM evidence_items
    WHERE id = :id
");
$stmt->execute([":id" => $id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    die("Evidence item not found.");
}

$title = $item["title"] ?? "Unknown evidence";

// ===============================
// Load linked uploaded files
// ===============================
$fileStmt = $conn->prepare("
    SELECT *
    FROM evidence_files
    WHERE evidence_id = :evidence_id
");
$fileStmt->execute([":evidence_id" => $id]);
$files = $fileStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Delete physical uploaded files
// ===============================
foreach ($files as $file) {
    $filePath = $file["file_path"] ?? "";

    if ($filePath !== "") {
        $fullPath = storagePath($filePath);

        if (file_exists($fullPath) && is_file($fullPath)) {
            unlink($fullPath);
        }
    }
}

// ===============================
// Delete uploaded file records
// ===============================
$deleteFilesStmt = $conn->prepare("
    DELETE FROM evidence_files
    WHERE evidence_id = :evidence_id
");
$deleteFilesStmt->execute([":evidence_id" => $id]);

// ===============================
// Delete semester report evidence links
// ===============================
$deleteLinksStmt = $conn->prepare("
    DELETE FROM semester_report_evidence
    WHERE evidence_id = :evidence_id
");
$deleteLinksStmt->execute([":evidence_id" => $id]);

// ===============================
// Delete evidence item
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM evidence_items
    WHERE id = :id
");
$deleteStmt->execute([":id" => $id]);

// ===============================
// Log activity
// ===============================
logActivity(
    $conn,
    "Delete",
    "Evidence",
    $id,
    "Evidence item deleted: " . $title
);

// ===============================
// Redirect
// ===============================
header("Location: index.php");
exit;
