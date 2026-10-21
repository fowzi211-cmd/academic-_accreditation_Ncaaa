<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

// ===============================
// Validate link ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid link ID.");
}

$link_id = (int)$_GET["id"];

// ===============================
// Validate report ID
// ===============================
$report_id = isset($_GET["report_id"]) && is_numeric($_GET["report_id"])
    ? (int)$_GET["report_id"]
    : 0;

if ($report_id <= 0) {
    die("Invalid report ID.");
}

// ===============================
// Check link exists
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM semester_report_evidence
    WHERE id = :id
      AND report_id = :report_id
");
$stmt->execute([
    ":id" => $link_id,
    ":report_id" => $report_id
]);

$link = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$link) {
    die("Evidence link not found.");
}

// ===============================
// Delete link
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM semester_report_evidence
    WHERE id = :id
      AND report_id = :report_id
");
$deleteStmt->execute([
    ":id" => $link_id,
    ":report_id" => $report_id
]);

// ===============================
// Redirect
// ===============================
header("Location: link_semester_report_items.php?id=" . $report_id);
exit;