<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// Helpers
// ===============================
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

// ===============================
// Required tables
// ===============================
if (!tableExists($conn, "committee_recommendations")) {
    die("committee_recommendations table does not exist.");
}

if (!tableExists($conn, "committees")) {
    die("committees table does not exist.");
}

if (!tableExists($conn, "committee_meetings")) {
    die("committee_meetings table does not exist.");
}

$hasTasks = tableExists($conn, "tasks");
$hasEvidenceItems = tableExists($conn, "evidence_items");
$hasProgramStaff = tableExists($conn, "program_staff");

$hasLinkedTaskId = columnExists($conn, "committee_recommendations", "linked_task_id");
$hasLinkedEvidenceId = columnExists($conn, "committee_recommendations", "linked_evidence_id");

// ===============================
// Filters from dashboard
// ===============================
$filter_search = trim($_GET["search"] ?? "");
$filter_committee_id = trim($_GET["committee_id"] ?? "");
$filter_status = trim($_GET["status"] ?? "");
$filter_priority = trim($_GET["priority"] ?? "");
$filter_responsible_staff_id = trim($_GET["responsible_staff_id"] ?? "");
$filter_overdue = trim($_GET["overdue"] ?? "");
$filter_linked_task = trim($_GET["linked_task"] ?? "");

// ===============================
// Dynamic joins
// ===============================
$taskSelect = "
    NULL AS task_title,
    NULL AS task_status,
    NULL AS task_priority,
    NULL AS task_due_date
";

$taskJoin = "";

if ($hasTasks && $hasLinkedTaskId) {
    $taskSelect = "
        t.task_title,
        t.status AS task_status,
        t.priority AS task_priority,
        t.due_date AS task_due_date
    ";

    $taskJoin = "
        LEFT JOIN tasks t ON r.linked_task_id = t.id
    ";
}

$evidenceSelect = "
    NULL AS evidence_title,
    NULL AS evidence_status,
    NULL AS evidence_review_status
";

$evidenceJoin = "";

if ($hasEvidenceItems && $hasLinkedEvidenceId) {
    $evidenceSelect = "
        e.title AS evidence_title,
        e.status AS evidence_status,
        e.review_status AS evidence_review_status
    ";

    $evidenceJoin = "
        LEFT JOIN evidence_items e ON r.linked_evidence_id = e.id
    ";
}

// ===============================
// WHERE
// ===============================
$whereSql = " WHERE 1 = 1 ";
$params = [];

if ($filter_search !== "") {
    $whereSql .= " AND (
        r.recommendation_text LIKE :search
        OR r.followup_notes LIKE :search
        OR c.committee_name LIKE :search
        OR m.meeting_title LIKE :search
        OR ps.full_name LIKE :search
    )";
    $params[":search"] = "%" . $filter_search . "%";
}

if ($filter_committee_id !== "") {
    $whereSql .= " AND r.committee_id = :committee_id";
    $params[":committee_id"] = $filter_committee_id;
}

if ($filter_status !== "") {
    $whereSql .= " AND r.status = :status";
    $params[":status"] = $filter_status;
}

if ($filter_priority !== "") {
    $whereSql .= " AND r.priority = :priority";
    $params[":priority"] = $filter_priority;
}

if ($filter_responsible_staff_id !== "") {
    $whereSql .= " AND r.responsible_staff_id = :responsible_staff_id";
    $params[":responsible_staff_id"] = $filter_responsible_staff_id;
}

if ($filter_overdue === "1") {
    $whereSql .= "
        AND r.target_date IS NOT NULL
        AND r.target_date < CURDATE()
        AND r.status NOT IN ('Completed', 'Closed', 'Cancelled')
    ";
}

if ($filter_linked_task === "linked" && $hasLinkedTaskId) {
    $whereSql .= " AND r.linked_task_id IS NOT NULL";
}

if ($filter_linked_task === "unlinked" && $hasLinkedTaskId) {
    $whereSql .= " AND r.linked_task_id IS NULL";
}

// ===============================
// Query
// ===============================
$sql = "
    SELECT
        r.id,
        r.recommendation_text,
        r.responsible_staff_id,
        r.target_date,
        r.priority,
        r.status,
        r.followup_notes,
        r.created_at,
        r.updated_at,

        " . ($hasLinkedTaskId ? "r.linked_task_id" : "NULL AS linked_task_id") . ",
        " . ($hasLinkedEvidenceId ? "r.linked_evidence_id" : "NULL AS linked_evidence_id") . ",

        c.committee_name,
        c.committee_type,
        c.academic_year,

        m.meeting_title,
        m.meeting_date,

        ps.full_name AS responsible_name,
        ps.email AS responsible_email,

        $taskSelect,
        $evidenceSelect

    FROM committee_recommendations r
    LEFT JOIN committees c ON r.committee_id = c.id
    LEFT JOIN committee_meetings m ON r.meeting_id = m.id
    LEFT JOIN program_staff ps ON r.responsible_staff_id = ps.id
    $taskJoin
    $evidenceJoin
    $whereSql

    ORDER BY
        CASE
            WHEN r.status = 'Open' THEN 1
            WHEN r.status = 'In Progress' THEN 2
            WHEN r.status = 'Completed' THEN 3
            WHEN r.status = 'Closed' THEN 4
            WHEN r.status = 'Cancelled' THEN 5
            ELSE 6
        END,
        CASE
            WHEN r.priority = 'High' THEN 1
            WHEN r.priority = 'Medium' THEN 2
            WHEN r.priority = 'Low' THEN 3
            ELSE 4
        END,
        r.target_date ASC,
        r.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// CSV output
// ===============================
$filename = "committee_recommendations_export_" . date("Y-m-d_H-i-s") . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";

$output = fopen("php://output", "w");

fputcsv($output, [
    "ID",
    "Committee Name",
    "Committee Type",
    "Academic Year",
    "Meeting Title",
    "Meeting Date",
    "Recommendation",
    "Responsible Staff",
    "Responsible Email",
    "Target Date",
    "Priority",
    "Status",
    "Overdue",
    "Linked Task ID",
    "Linked Task Title",
    "Linked Task Status",
    "Linked Task Priority",
    "Linked Task Due Date",
    "Linked Evidence ID",
    "Evidence Title",
    "Evidence Status",
    "Evidence Review Status",
    "Follow-up Notes",
    "Created At",
    "Updated At"
]);

foreach ($rows as $row) {
    $isOverdue = "No";

    if (!empty($row["target_date"]) &&
        $row["target_date"] < date("Y-m-d") &&
        !in_array($row["status"], ["Completed", "Closed", "Cancelled"], true)) {
        $isOverdue = "Yes";
    }

    fputcsv($output, [
        $row["id"],
        $row["committee_name"],
        $row["committee_type"],
        $row["academic_year"],
        $row["meeting_title"],
        $row["meeting_date"],
        $row["recommendation_text"],
        $row["responsible_name"],
        $row["responsible_email"],
        $row["target_date"],
        $row["priority"],
        $row["status"],
        $isOverdue,
        $row["linked_task_id"],
        $row["task_title"],
        $row["task_status"],
        $row["task_priority"],
        $row["task_due_date"],
        $row["linked_evidence_id"],
        $row["evidence_title"],
        $row["evidence_status"],
        $row["evidence_review_status"],
        $row["followup_notes"],
        $row["created_at"],
        $row["updated_at"]
    ]);
}

fclose($output);
exit;