<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"]);

// ===============================
// Read filters from task_status_logs.php
// ===============================
$filter_task_id = trim($_GET["task_id"] ?? "");
$filter_new_status = trim($_GET["new_status"] ?? "");
$filter_changed_by = trim($_GET["changed_by"] ?? "");
$filter_source = trim($_GET["change_source"] ?? "");
$filter_search = trim($_GET["search"] ?? "");

// ===============================
// Build WHERE
// ===============================
$whereSql = " WHERE 1 = 1 ";
$params = [];

if ($filter_task_id !== "" && is_numeric($filter_task_id)) {
    $whereSql .= " AND l.task_id = :task_id";
    $params[":task_id"] = (int)$filter_task_id;
}

if ($filter_new_status !== "") {
    $whereSql .= " AND l.new_status = :new_status";
    $params[":new_status"] = $filter_new_status;
}

if ($filter_changed_by !== "") {
    $whereSql .= " AND l.changed_by LIKE :changed_by";
    $params[":changed_by"] = "%" . $filter_changed_by . "%";
}

if ($filter_source !== "") {
    $whereSql .= " AND l.change_source = :change_source";
    $params[":change_source"] = $filter_source;
}

if ($filter_search !== "") {
    $whereSql .= " AND (
        t.task_title LIKE :search
        OR t.task_description LIKE :search
        OR t.responsible_person LIKE :search
        OR l.old_status LIKE :search
        OR l.new_status LIKE :search
        OR l.changed_by LIKE :search
        OR l.change_source LIKE :search
        OR l.change_comment LIKE :search
    )";
    $params[":search"] = "%" . $filter_search . "%";
}

// ===============================
// Load logs
// ===============================
$sql = "
    SELECT
        l.id,
        l.task_id,

        t.task_title,
        t.task_description,
        t.responsible_person,
        t.priority,
        t.status AS current_status,
        t.related_module,
        t.related_record_id,

        cr.course_code AS related_course_code,
        cr.course_title AS related_course_title,
        cr.academic_year AS related_academic_year,
        cr.semester AS related_semester,

        l.old_status,
        l.new_status,
        l.changed_by,
        l.change_source,
        l.change_comment,
        l.created_at

    FROM task_status_logs l

    LEFT JOIN tasks t
        ON l.task_id = t.id

    LEFT JOIN course_reports cr
        ON t.related_module = 'Course Reports'
       AND t.related_record_id = cr.id

    $whereSql

    ORDER BY l.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// CSV output
// ===============================
$filename = "task_status_logs_export_" . date("Y-m-d_H-i-s") . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// UTF-8 BOM for Excel
echo "\xEF\xBB\xBF";

$output = fopen("php://output", "w");

// Header row
fputcsv($output, [
    "Log ID",
    "Task ID",
    "Task Title",
    "Task Description",
    "Task Owner / Responsible Person",
    "Task Priority",
    "Current Task Status",

    "Old Status",
    "New Status",
    "Changed By",
    "Change Source",
    "Change Comment",
    "Change Date/Time",

    "Related Module",
    "Related Record ID",
    "Related Course Code",
    "Related Course Title",
    "Related Academic Year",
    "Related Semester",
    "Related Course Report Link"
]);

// Data rows
foreach ($rows as $row) {
    $courseReportLink = "";

    if (($row["related_module"] ?? "") === "Course Reports" && !empty($row["related_record_id"])) {
        $courseReportLink = "http://localhost:8000/view_course_report.php?id=" . $row["related_record_id"];
    }

    fputcsv($output, [
        $row["id"],
        $row["task_id"],
        $row["task_title"],
        $row["task_description"],
        $row["responsible_person"],
        $row["priority"],
        $row["current_status"],

        $row["old_status"],
        $row["new_status"],
        $row["changed_by"],
        $row["change_source"],
        $row["change_comment"],
        $row["created_at"],

        $row["related_module"],
        $row["related_record_id"],
        $row["related_course_code"],
        $row["related_course_title"],
        $row["related_academic_year"],
        $row["related_semester"],
        $courseReportLink
    ]);
}

fclose($output);
exit;