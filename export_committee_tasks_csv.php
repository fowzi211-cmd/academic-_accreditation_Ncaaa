<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
        ");
        $stmt->execute([":table_name" => $tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function columnExists($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND column_name = :column_name
        ");
        $stmt->execute([
            ":table_name" => $tableName,
            ":column_name" => $columnName
        ]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

if (!tableExists($conn, "tasks")) {
    die("tasks table does not exist.");
}

if (!tableExists($conn, "committee_recommendations")) {
    die("committee_recommendations table does not exist.");
}

if (!tableExists($conn, "committees")) {
    die("committees table does not exist.");
}

if (!tableExists($conn, "committee_meetings")) {
    die("committee_meetings table does not exist.");
}

if (!columnExists($conn, "committee_recommendations", "linked_task_id")) {
    die("linked_task_id column is missing from committee_recommendations.");
}

// ===============================
// Read filters from dashboard
// ===============================
$filter_search = trim($_GET["search"] ?? "");
$filter_committee_id = trim($_GET["committee_id"] ?? "");
$filter_status = trim($_GET["status"] ?? "");
$filter_priority = trim($_GET["priority"] ?? "");
$filter_responsible = trim($_GET["responsible_person"] ?? "");
$filter_overdue = trim($_GET["overdue"] ?? "");

// ===============================
// Build WHERE
// ===============================
$whereSql = "
    WHERE r.linked_task_id IS NOT NULL
";

$params = [];

if ($filter_search !== "") {
    $whereSql .= " AND (
        t.task_title LIKE :search
        OR t.task_description LIKE :search
        OR t.notes LIKE :search
        OR t.responsible_person LIKE :search
        OR r.recommendation_text LIKE :search
        OR r.followup_notes LIKE :search
        OR c.committee_name LIKE :search
        OR m.meeting_title LIKE :search
    )";
    $params[":search"] = "%" . $filter_search . "%";
}

if ($filter_committee_id !== "") {
    $whereSql .= " AND r.committee_id = :committee_id";
    $params[":committee_id"] = $filter_committee_id;
}

if ($filter_status !== "") {
    $whereSql .= " AND t.status = :status";
    $params[":status"] = $filter_status;
}

if ($filter_priority !== "") {
    $whereSql .= " AND t.priority = :priority";
    $params[":priority"] = $filter_priority;
}

if ($filter_responsible !== "") {
    $whereSql .= " AND t.responsible_person LIKE :responsible_person";
    $params[":responsible_person"] = "%" . $filter_responsible . "%";
}

if ($filter_overdue === "1") {
    $whereSql .= "
        AND t.due_date IS NOT NULL
        AND t.due_date < CURDATE()
        AND t.status NOT IN ('Completed', 'Cancelled')
    ";
}

// ===============================
// Query
// ===============================
$sql = "
    SELECT
        t.id AS task_id,
        t.task_title,
        t.task_description,
        t.responsible_person,
        t.due_date,
        t.priority AS task_priority,
        t.status AS task_status,
        t.notes AS task_notes,
        t.created_at AS task_created_at,
        t.updated_at AS task_updated_at,

        r.id AS recommendation_id,
        r.recommendation_text,
        r.target_date AS recommendation_target_date,
        r.priority AS recommendation_priority,
        r.status AS recommendation_status,
        r.followup_notes,

        c.id AS committee_id,
        c.committee_name,
        c.committee_type,
        c.academic_year,

        m.id AS meeting_id,
        m.meeting_title,
        m.meeting_date

    FROM committee_recommendations r
    INNER JOIN tasks t ON r.linked_task_id = t.id
    LEFT JOIN committees c ON r.committee_id = c.id
    LEFT JOIN committee_meetings m ON r.meeting_id = m.id

    $whereSql

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
// CSV output
// ===============================
$filename = "committee_tasks_export_" . date("Y-m-d_H-i-s") . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";

$output = fopen("php://output", "w");

fputcsv($output, [
    "Task ID",
    "Task Title",
    "Task Description",
    "Responsible Person",
    "Due Date",
    "Task Priority",
    "Task Status",
    "Overdue",
    "Task Notes",
    "Task Created At",
    "Task Updated At",

    "Recommendation ID",
    "Recommendation Text",
    "Recommendation Target Date",
    "Recommendation Priority",
    "Recommendation Status",
    "Recommendation Follow-up Notes",

    "Committee ID",
    "Committee Name",
    "Committee Type",
    "Academic Year",

    "Meeting ID",
    "Meeting Title",
    "Meeting Date"
]);

foreach ($rows as $row) {
    $isOverdue = "No";

    if (!empty($row["due_date"]) &&
        $row["due_date"] < date("Y-m-d") &&
        !in_array($row["task_status"], ["Completed", "Cancelled"], true)) {
        $isOverdue = "Yes";
    }

    fputcsv($output, [
        $row["task_id"],
        $row["task_title"],
        $row["task_description"],
        $row["responsible_person"],
        $row["due_date"],
        $row["task_priority"],
        $row["task_status"],
        $isOverdue,
        $row["task_notes"],
        $row["task_created_at"],
        $row["task_updated_at"],

        $row["recommendation_id"],
        $row["recommendation_text"],
        $row["recommendation_target_date"],
        $row["recommendation_priority"],
        $row["recommendation_status"],
        $row["followup_notes"],

        $row["committee_id"],
        $row["committee_name"],
        $row["committee_type"],
        $row["academic_year"],

        $row["meeting_id"],
        $row["meeting_title"],
        $row["meeting_date"]
    ]);
}

fclose($output);
exit;