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
// Validate KPI ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid KPI ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Check KPI exists
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM kpis
    WHERE id = :id
");
$stmt->execute([":id" => $id]);
$kpi = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$kpi) {
    die("KPI not found.");
}

$kpiLabel = ($kpi["kpi_code"] ?? "") . " - " . ($kpi["kpi_name"] ?? "");

// ===============================
// Prevent deletion if KPI has results
// ===============================
$resultStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM kpi_results
    WHERE kpi_id = :id
");
$resultStmt->execute([":id" => $id]);
$resultCount = (int)$resultStmt->fetchColumn();

if ($resultCount > 0) {
    die("Cannot delete this KPI because it has KPI results linked to it. Delete the KPI results first, or set this KPI to Inactive instead.");
}

// ===============================
// Delete KPI
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM kpis
    WHERE id = :id
");
$deleteStmt->execute([":id" => $id]);

// ===============================
// Log activity
// ===============================
logActivity(
    $conn,
    "Delete",
    "KPIs",
    $id,
    "KPI deleted: " . $kpiLabel
);

// ===============================
// Redirect
// ===============================
header("Location: kpis.php");
exit;