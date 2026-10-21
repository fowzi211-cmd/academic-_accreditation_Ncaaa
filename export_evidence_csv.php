<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty"]);

// Read same filters used in index.php
$filter_standard_id = $_GET["standard_id"] ?? "";
$filter_substandard_id = $_GET["substandard_id"] ?? "";
$filter_status = $_GET["status"] ?? "";
$filter_academic_year = trim($_GET["academic_year"] ?? "");
$filter_semester = $_GET["semester"] ?? "";
$filter_responsible_person = trim($_GET["responsible_person"] ?? "");
$filter_search = trim($_GET["search"] ?? "");

$sql = "
    SELECT 
        e.id,
        e.title,
        s.standard_code,
        s.standard_title,
        ss.substandard_code,
        ss.substandard_text,
        e.evidence_type,
        e.responsible_person,
        e.status,
        e.academic_year,
        e.semester,
        e.review_status,
        e.notes,
        COUNT(ef.id) AS file_count
    FROM evidence_items e
    LEFT JOIN substandards ss ON e.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
    LEFT JOIN evidence_files ef ON e.id = ef.evidence_id
    WHERE 1 = 1
";

$params = [];

if ($filter_standard_id !== "") {
    $sql .= " AND s.id = :standard_id";
    $params[":standard_id"] = $filter_standard_id;
}

if ($filter_substandard_id !== "") {
    $sql .= " AND ss.id = :substandard_id";
    $params[":substandard_id"] = $filter_substandard_id;
}

if ($filter_status !== "") {
    $sql .= " AND e.status = :status";
    $params[":status"] = $filter_status;
}

if ($filter_academic_year !== "") {
    $sql .= " AND e.academic_year LIKE :academic_year";
    $params[":academic_year"] = "%" . $filter_academic_year . "%";
}

if ($filter_semester !== "") {
    $sql .= " AND e.semester = :semester";
    $params[":semester"] = $filter_semester;
}

if ($filter_responsible_person !== "") {
    $sql .= " AND e.responsible_person LIKE :responsible_person";
    $params[":responsible_person"] = "%" . $filter_responsible_person . "%";
}

if ($filter_search !== "") {
    $sql .= " AND (
        e.title LIKE :search
        OR e.notes LIKE :search
        OR e.evidence_type LIKE :search
        OR e.responsible_person LIKE :search
        OR ss.substandard_code LIKE :search
        OR ss.substandard_text LIKE :search
        OR s.standard_code LIKE :search
        OR s.standard_title LIKE :search
    )";
    $params[":search"] = "%" . $filter_search . "%";
}

$sql .= "
    GROUP BY 
        e.id,
        e.title,
        s.standard_code,
        s.standard_title,
        ss.substandard_code,
        ss.substandard_text,
        e.evidence_type,
        e.responsible_person,
        e.status,
        e.academic_year,
        e.semester,
        e.review_status,
        e.notes
    ORDER BY e.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CSV headers
$filename = "evidence_export_" . date("Y-m-d_H-i-s") . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Add UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

$output = fopen("php://output", "w");

// Header row
fputcsv($output, [
    "ID",
    "Evidence Title",
    "Standard Code",
    "Standard Title",
    "Substandard Code",
    "Substandard Requirement",
    "Evidence Type",
    "Responsible Person",
    "Status",
    "Academic Year",
    "Semester",
    "Review Status",
    "Notes",
    "File Count"
]);

// Data rows
foreach ($rows as $row) {
    fputcsv($output, [
        $row["id"],
        $row["title"],
        $row["standard_code"],
        $row["standard_title"],
        $row["substandard_code"],
        $row["substandard_text"],
        $row["evidence_type"],
        $row["responsible_person"],
        $row["status"],
        $row["academic_year"],
        $row["semester"],
        $row["review_status"],
        $row["notes"],
        $row["file_count"]
    ]);
}

fclose($output);
exit;