<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

// ===============================
// Helper
// ===============================
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
// Check optional related columns
// ===============================
$hasRelatedModule = columnExists($conn, "tasks", "related_module");

// ===============================
// Main dashboard counts
// ===============================
$countStmt = $conn->query("
    SELECT
        COUNT(*) AS total_tasks,

        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_tasks,

        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_tasks,

        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed_tasks,

        SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_tasks,

        SUM(CASE WHEN priority = 'High' THEN 1 ELSE 0 END) AS high_priority_tasks,

        SUM(
            CASE 
                WHEN due_date IS NOT NULL
                 AND due_date < CURDATE()
                 AND status NOT IN ('Completed', 'Cancelled')
                THEN 1 ELSE 0
            END
        ) AS overdue_tasks
    FROM tasks
");

$counts = $countStmt->fetch(PDO::FETCH_ASSOC);

$totalTasks = (int)($counts["total_tasks"] ?? 0);
$pendingTasks = (int)($counts["pending_tasks"] ?? 0);
$inProgressTasks = (int)($counts["in_progress_tasks"] ?? 0);
$completedTasks = (int)($counts["completed_tasks"] ?? 0);
$cancelledTasks = (int)($counts["cancelled_tasks"] ?? 0);
$highPriorityTasks = (int)($counts["high_priority_tasks"] ?? 0);
$overdueTasks = (int)($counts["overdue_tasks"] ?? 0);

// ===============================
// Course Report follow-up count
// ===============================
$courseReportFollowupTasks = 0;

if ($hasRelatedModule) {
    $crStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tasks
        WHERE related_module = :related_module
    ");
    $crStmt->execute([":related_module" => "Course Reports"]);
    $courseReportFollowupTasks = (int)$crStmt->fetchColumn();
}

// ===============================
// Status distribution
// ===============================
$statusStmt = $conn->query("
    SELECT 
        status,
        COUNT(*) AS total
    FROM tasks
    GROUP BY status
    ORDER BY total DESC
");

$statusRows = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Priority distribution
// ===============================
$priorityStmt = $conn->query("
    SELECT 
        priority,
        COUNT(*) AS total
    FROM tasks
    GROUP BY priority
    ORDER BY 
        CASE 
            WHEN priority = 'High' THEN 1
            WHEN priority = 'Medium' THEN 2
            WHEN priority = 'Low' THEN 3
            ELSE 4
        END
");

$priorityRows = $priorityStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Overdue / high-priority task details
// ===============================
$attentionStmt = $conn->query("
    SELECT
        t.id,
        t.task_title,
        t.responsible_person,
        t.due_date,
        t.priority,
        t.status,
        s.standard_code,
        ss.substandard_code,
        e.title AS evidence_title
    FROM tasks t
    LEFT JOIN substandards ss ON t.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
    LEFT JOIN evidence_items e ON t.evidence_id = e.id
    WHERE 
        (
            t.priority = 'High'
            OR (
                t.due_date IS NOT NULL
                AND t.due_date < CURDATE()
                AND t.status NOT IN ('Completed', 'Cancelled')
            )
        )
    ORDER BY
        CASE 
            WHEN t.due_date IS NOT NULL
             AND t.due_date < CURDATE()
             AND t.status NOT IN ('Completed', 'Cancelled')
            THEN 1 ELSE 2
        END,
        CASE 
            WHEN t.priority = 'High' THEN 1
            WHEN t.priority = 'Medium' THEN 2
            WHEN t.priority = 'Low' THEN 3
            ELSE 4
        END,
        t.due_date ASC,
        t.id DESC
");

// ===============================
// CSV output
// ===============================
$filename = "task_dashboard_export_" . date("Y-m-d_H-i-s") . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// UTF-8 BOM for Excel
echo "\xEF\xBB\xBF";

$output = fopen("php://output", "w");

// ===============================
// Section 1: Summary
// ===============================
fputcsv($output, ["Task Dashboard Summary"]);
fputcsv($output, ["Generated At", date("Y-m-d H:i:s")]);
fputcsv($output, []);

fputcsv($output, ["Metric", "Value"]);
fputcsv($output, ["Total Tasks", $totalTasks]);
fputcsv($output, ["Pending Tasks", $pendingTasks]);
fputcsv($output, ["In Progress Tasks", $inProgressTasks]);
fputcsv($output, ["Completed Tasks", $completedTasks]);
fputcsv($output, ["Cancelled Tasks", $cancelledTasks]);
fputcsv($output, ["Overdue Tasks", $overdueTasks]);
fputcsv($output, ["High Priority Tasks", $highPriorityTasks]);
fputcsv($output, ["Course Report Follow-up Tasks", $courseReportFollowupTasks]);

fputcsv($output, []);
fputcsv($output, []);

// ===============================
// Section 2: Status distribution
// ===============================
fputcsv($output, ["Status Distribution"]);
fputcsv($output, ["Status", "Total"]);

foreach ($statusRows as $row) {
    fputcsv($output, [
        $row["status"],
        $row["total"]
    ]);
}

fputcsv($output, []);
fputcsv($output, []);

// ===============================
// Section 3: Priority distribution
// ===============================
fputcsv($output, ["Priority Distribution"]);
fputcsv($output, ["Priority", "Total"]);

foreach ($priorityRows as $row) {
    fputcsv($output, [
        $row["priority"],
        $row["total"]
    ]);
}

fputcsv($output, []);
fputcsv($output, []);

// ===============================
// Section 4: Attention tasks
// ===============================
fputcsv($output, ["Tasks Needing Attention"]);
fputcsv($output, [
    "Task ID",
    "Task Title",
    "Responsible Person",
    "Due Date",
    "Priority",
    "Status",
    "Standard Code",
    "Substandard Code",
    "Linked Evidence"
]);

while ($task = $attentionStmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $task["id"],
        $task["task_title"],
        $task["responsible_person"],
        $task["due_date"],
        $task["priority"],
        $task["status"],
        $task["standard_code"],
        $task["substandard_code"],
        $task["evidence_title"]
    ]);
}

fclose($output);
exit;