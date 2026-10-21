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
// Validate KPI result ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid KPI result ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Check KPI result exists
// ===============================
$stmt = $conn->prepare("
    SELECT 
        kr.*,
        k.kpi_code,
        k.kpi_name
    FROM kpi_results kr
    INNER JOIN kpis k ON kr.kpi_id = k.id
    WHERE kr.id = :id
");
$stmt->execute([":id" => $id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$result) {
    die("KPI result not found.");
}

$kpi_id = (int)$result["kpi_id"];
$kpiLabel = ($result["kpi_code"] ?? "") . " - " . ($result["kpi_name"] ?? "");
$academicYear = $result["academic_year"] ?? "";
$semester = $result["semester"] ?? "";
$achievement = $result["achievement_percentage"] ?? "";

// ===============================
// Delete KPI result
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM kpi_results
    WHERE id = :id
");
$deleteStmt->execute([":id" => $id]);

// ===============================
// Log activity
// ===============================
logActivity(
    $conn,
    "Delete",
    "KPI Results",
    $id,
    "KPI result deleted for " . $kpiLabel . " | " . $academicYear . " | " . $semester . " | Achievement: " . $achievement . "%"
);

// ===============================
// Redirect
// ===============================
header("Location: kpi_results.php?kpi_id=" . $kpi_id);
exit;