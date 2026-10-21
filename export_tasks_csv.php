<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty"]);

// ===============================
// Read same filters used in tasks.php
// ===============================
$filter_status = $_GET["status"] ?? "";
$filter_priority = $_GET["priority"] ?? "";
$filter_responsible_person = trim($_GET["responsible_person"] ?? "");
$filter_substandard_id = $_GET["substandard_id"] ?? "";
$filter_evidence_id = $_GET["evidence_id"] ?? "";
$filter_related_module = trim($_GET["related_module"] ?? "");
$filter_overdue = $_GET["overdue"] ?? "";
$filter_search = trim($_GET["search"] ?? "");

// ===============================
// Build SQL
// ===============================
$sql = "
    SELECT 
        t.id,
        t.task_title,
        t.task_description,

        s.standard_code,
        s.standard_title,

        ss.substandard_code,
        ss.substandard_text,

        e.title AS evidence_title,

        t.related_module,
        t.related_record_id,

        cr.course_code AS related_course_code,
        cr.course_title AS related_course_title,
        cr.academic_year AS related_academic_year,
        cr.semester AS related_semester,

        t.responsible_person,
        t.due_date,
        t.priority,
        t.status,
        t.notes,
        t.created_at,
        t.updated_at

    FROM tasks t

    LEFT JOIN evidence_items e 
        ON t.evidence_id = e.id

    LEFT JOIN substandards ss 
        ON t.substandard_id = ss.id

    LEFT JOIN standards s 
        ON ss.standard_id = s.id

    LEFT JOIN course_reports cr
        ON t.related_module = 'Course Reports'
       AND t.related_record_id = cr.id

    WHERE 1 = 1
";

$params = [];

if ($filter_status !== "") {
    $sql .= " AND t.status = :status";
    $params[":status"] = $filter_status;
}

if ($filter_priority !== "") {
    $sql .= " AND t.priority = :priority";
    $params[":priority"] = $filter_priority;
}

if ($filter_responsible_person !== "") {
    $sql .= " AND t.responsible_person LIKE :responsible_person";
    $params[":responsible_person"] = "%" . $filter_responsible_person . "%";
}

if ($filter_substandard_id !== "") {
    $sql .= " AND t.substandard_id = :substandard_id";
    $params[":substandard_id"] = $filter_substandard_id;
}

if ($filter_evidence_id !== "") {
    $sql .= " AND t.evidence_id = :evidence_id";
    $params[":evidence_id"] = $filter_evidence_id;
}

if ($filter_related_module !== "") {
    $sql .= " AND t.related_module = :related_module";
    $params[":related_module"] = $filter_related_module;
}

if ($filter_overdue === "1") {
    $sql .= "
        AND t.due_date IS NOT NULL
        AND t.due_date < CURDATE()
        AND t.status NOT IN ('Completed', 'Cancelled')
    ";
}

if ($filter_search !== "") {
    $sql .= " AND (
        t.task_title LIKE :search
        OR t.task_description LIKE :search
        OR t.notes LIKE :search
        OR t.responsible_person LIKE :search
        OR t.status LIKE :search
        OR t.priority LIKE :search
        OR e.title LIKE :search
        OR ss.substandard_code LIKE :search
        OR ss.substandard_text LIKE :search
        OR s.standard_code LIKE :search
        OR s.standard_title LIKE :search
        OR t.related_module LIKE :search
        OR t.related_record_id LIKE :search
        OR cr.course_code LIKE :search
        OR cr.course_title LIKE :search
    )";

    $params[":search"] = "%" . $filter_search . "%";
}

$sql .= "
    ORDER BY 
        CASE 
            WHEN t.status = 'Pending' THEN 1
            WHEN t.status = 'In Progress' THEN 2
            WHEN t.status = 'Completed' THEN 3
            WHEN t.status = 'Cancelled' THEN 4
            ELSE 5
        END,
        CASE 
            WHEN t.priority = 'High' THEN 1
            WHEN t.priority = 'Medium' THEN 2
            WHEN t.priority = 'Low' THEN 3
            ELSE 4
        END,
        t.due_date ASC,
        t.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// CSV headers
// ===============================
$filename = "tasks_export_" . date("Y-m-d_H-i-s") . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

$output = fopen("php://output", "w");

// ===============================
// Header row
// ===============================
fputcsv($output, [
    "ID",
    "Task Title",
    "Task Description",

    "Standard Code",
    "Standard Title",
    "Substandard Code",
    "Substandard Requirement",

    "Linked Evidence",

    "Related Module",
    "Related Record ID",
    "Related Course Code",
    "Related Course Title",
    "Related Academic Year",
    "Related Semester",
    "Related Course Report Link",

    "Responsible Person",
    "Due Date",
    "Priority",
    "Status",
    "Notes",
    "Created At",
    "Updated At"
]);

// ===============================
// Data rows
// ===============================
foreach ($rows as $row) {
    $courseReportLink = "";

    if (($row["related_module"] ?? "") === "Course Reports" && !empty($row["related_record_id"])) {
        $courseReportLink = "http://localhost:8000/view_course_report.php?id=" . $row["related_record_id"];
    }

    fputcsv($output, [
        $row["id"],
        $row["task_title"],
        $row["task_description"],

        $row["standard_code"],
        $row["standard_title"],
        $row["substandard_code"],
        $row["substandard_text"],

        $row["evidence_title"],

        $row["related_module"],
        $row["related_record_id"],
        $row["related_course_code"],
        $row["related_course_title"],
        $row["related_academic_year"],
        $row["related_semester"],
        $courseReportLink,

        $row["responsible_person"],
        $row["due_date"],
        $row["priority"],
        $row["status"],
        $row["notes"],
        $row["created_at"],
        $row["updated_at"]
    ]);
}

fclose($output);
exit;