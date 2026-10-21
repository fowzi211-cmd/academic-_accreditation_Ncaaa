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
// Validate semester report ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid semester report ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Check report exists before deleting
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM semester_reports
    WHERE id = :id
");
$stmt->execute([":id" => $id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die("Semester report not found.");
}

$reportTitle = $report["report_title"] ?? "Unknown semester report";
$academicYear = $report["academic_year"] ?? "";
$semester = $report["semester"] ?? "";

// ===============================
// Delete linked records first
// ===============================
$linkTables = [
    "semester_report_evidence",
    "semester_report_tasks",
    "semester_report_course_reports",
    "semester_report_kpi_results"
];

foreach ($linkTables as $table) {
    $deleteLinksStmt = $conn->prepare("
        DELETE FROM $table
        WHERE report_id = :report_id
    ");
    $deleteLinksStmt->execute([":report_id" => $id]);
}

// ===============================
// Delete semester report
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM semester_reports
    WHERE id = :id
");
$deleteStmt->execute([":id" => $id]);

// ===============================
// Log activity
// ===============================
logActivity(
    $conn,
    "Delete",
    "Semester Reports",
    $id,
    "Semester report deleted: " . $reportTitle . " | " . $academicYear . " | " . $semester
);

// ===============================
// Redirect
// ===============================
header("Location: semester_reports.php");
exit;