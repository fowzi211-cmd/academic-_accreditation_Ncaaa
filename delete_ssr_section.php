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
// Validate SSR section ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid SSR section ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Load SSR section before deleting
// ===============================
$stmt = $conn->prepare("
    SELECT 
        ssr.*,
        s.standard_code,
        s.standard_title
    FROM ssr_sections ssr
    INNER JOIN standards s ON ssr.standard_id = s.id
    WHERE ssr.id = :id
");
$stmt->execute([":id" => $id]);
$section = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$section) {
    die("SSR section not found.");
}

$sectionTitle = $section["section_title"] ?? "Unknown SSR section";
$standardLabel = ($section["standard_code"] ?? "") . " - " . ($section["standard_title"] ?? "");

// ===============================
// Delete SSR section
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM ssr_sections
    WHERE id = :id
");
$deleteStmt->execute([":id" => $id]);

// ===============================
// Log activity
// ===============================
logActivity(
    $conn,
    "Delete",
    "SSR Sections",
    $id,
    "SSR section deleted: " . $sectionTitle . " | " . $standardLabel
);

// ===============================
// Redirect
// ===============================
header("Location: ssr_builder.php");
exit;