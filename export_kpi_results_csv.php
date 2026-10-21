<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// Read filters from kpi_results.php
$filter_kpi_id = $_GET["kpi_id"] ?? "";

$sql = "
    SELECT 
        kr.id,
        kr.academic_year,
        kr.semester,
        kr.target_value,
        kr.actual_value,
        kr.achievement_percentage,
        kr.analysis,
        kr.improvement_action,
        kr.status,
        kr.created_at,
        kr.updated_at,

        k.kpi_code,
        k.kpi_name,
        k.kpi_description,
        k.measurement_method,
        k.unit,
        k.responsible_person,

        sr.report_title AS semester_report_title

    FROM kpi_results kr
    INNER JOIN kpis k ON kr.kpi_id = k.id
    LEFT JOIN semester_reports sr ON kr.linked_semester_report_id = sr.id
    WHERE 1 = 1
";

$params = [];

if ($filter_kpi_id !== "") {
    $sql .= " AND kr.kpi_id = :kpi_id";
    $params[":kpi_id"] = $filter_kpi_id;
}

$sql .= "
    ORDER BY kr.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = "kpi_results_export_" . date("Y-m-d_H-i-s") . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

$output = fopen("php://output", "w");

// Header row
fputcsv($output, [
    "ID",
    "KPI Code",
    "KPI Name",
    "KPI Description",
    "Measurement Method",
    "Responsible Person",
    "Academic Year",
    "Semester",
    "Target Value",
    "Actual Value",
    "Unit",
    "Achievement Percentage",
    "Analysis",
    "Improvement Action",
    "Linked Semester Report",
    "Status",
    "Created At",
    "Updated At"
]);

// Data rows
foreach ($rows as $row) {
    fputcsv($output, [
        $row["id"],
        $row["kpi_code"],
        $row["kpi_name"],
        $row["kpi_description"],
        $row["measurement_method"],
        $row["responsible_person"],
        $row["academic_year"],
        $row["semester"],
        $row["target_value"],
        $row["actual_value"],
        $row["unit"],
        $row["achievement_percentage"],
        $row["analysis"],
        $row["improvement_action"],
        $row["semester_report_title"],
        $row["status"],
        $row["created_at"],
        $row["updated_at"]
    ]);
}

fclose($output);
exit;