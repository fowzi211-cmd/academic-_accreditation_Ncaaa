<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

$stmt = $conn->query("
    SELECT
        id,
        kpi_code,
        kpi_name,
        kpi_description,
        measurement_method,
        target_value,
        unit,
        responsible_person,
        status,
        created_at,
        updated_at
    FROM kpis
    ORDER BY kpi_code
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = "kpis_export_" . date("Y-m-d_H-i-s") . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

$output = fopen("php://output", "w");

fputcsv($output, [
    "ID",
    "KPI Code",
    "KPI Name",
    "Description",
    "Measurement Method",
    "Target Value",
    "Unit",
    "Responsible Person",
    "Status",
    "Created At",
    "Updated At"
]);

foreach ($rows as $row) {
    fputcsv($output, [
        $row["id"],
        $row["kpi_code"],
        $row["kpi_name"],
        $row["kpi_description"],
        $row["measurement_method"],
        $row["target_value"],
        $row["unit"],
        $row["responsible_person"],
        $row["status"],
        $row["created_at"],
        $row["updated_at"]
    ]);
}

fclose($output);
exit;