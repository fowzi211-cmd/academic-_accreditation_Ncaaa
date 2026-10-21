<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"]);

// ===============================
// Helper functions
// ===============================
function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
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

function getPriorityClass($priority) {
    if ($priority === "High") {
        return "priority-high";
    }

    if ($priority === "Medium") {
        return "priority-medium";
    }

    if ($priority === "Low") {
        return "priority-low";
    }

    return "";
}

function getStatusClass($status) {
    if ($status === "Completed") {
        return "status-completed";
    }

    if ($status === "In Progress") {
        return "status-progress";
    }

    if ($status === "Pending") {
        return "status-pending";
    }

    if ($status === "Cancelled") {
        return "status-cancelled";
    }

    return "";
}

// ===============================
// Related columns check
// ===============================
$hasRelatedModule = columnExists($conn, "tasks", "related_module");
$hasRelatedRecordId = columnExists($conn, "tasks", "related_record_id");

// ===============================
// Summary counts
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
// Recent high-priority / overdue tasks
// ===============================
$relatedModuleSelect = $hasRelatedModule ? "t.related_module" : "NULL AS related_module";
$relatedRecordIdSelect = $hasRelatedRecordId ? "t.related_record_id" : "NULL AS related_record_id";

$attentionStmt = $conn->query("
    SELECT
        t.id,
        t.task_title,
        t.responsible_person,
        t.due_date,
        t.priority,
        t.status,
        $relatedModuleSelect,
        $relatedRecordIdSelect,
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
    LIMIT 15
");

$attentionTasks = $attentionStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Course Report tasks list
// ===============================
$courseReportTasks = [];

if ($hasRelatedModule && $hasRelatedRecordId) {
    $crTasksStmt = $conn->prepare("
        SELECT
            t.id,
            t.task_title,
            t.responsible_person,
            t.due_date,
            t.priority,
            t.status,
            t.related_module,
            t.related_record_id,
            cr.course_code,
            cr.course_title,
            cr.academic_year,
            cr.semester
        FROM tasks t
        LEFT JOIN course_reports cr
            ON t.related_record_id = cr.id
        WHERE t.related_module = :related_module
        ORDER BY t.id DESC
        LIMIT 15
    ");
    $crTasksStmt->execute([":related_module" => "Course Reports"]);
    $courseReportTasks = $crTasksStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Status distribution
// ===============================
$statusStmt = $conn->query("
    SELECT status, COUNT(*) AS total
    FROM tasks
    GROUP BY status
    ORDER BY total DESC
");
$statusRows = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Priority distribution
// ===============================
$priorityStmt = $conn->query("
    SELECT priority, COUNT(*) AS total
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
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Task Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .dashboard-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .dashboard-number {
            font-size: 30px;
            font-weight: bold;
            color: #007bff;
        }

        .dashboard-label {
            font-size: 13px;
            color: #555;
            margin-top: 6px;
        }

        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .priority-high {
            color: #dc3545;
            font-weight: bold;
        }

        .priority-medium {
            color: #fd7e14;
            font-weight: bold;
        }

        .priority-low {
            color: #28a745;
            font-weight: bold;
        }

        .status-completed {
            color: #28a745;
            font-weight: bold;
        }

        .status-progress {
            color: #007bff;
            font-weight: bold;
        }

        .status-pending {
            color: #fd7e14;
            font-weight: bold;
        }

        .status-cancelled {
            color: #6c757d;
            font-weight: bold;
        }

        .overdue {
            color: #dc3545;
            font-weight: bold;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .actions {
            margin-bottom: 18px;
        }

        .metric-red {
            color: #dc3545;
        }

        .metric-green {
            color: #28a745;
        }

        .metric-orange {
            color: #fd7e14;
        }

        .metric-purple {
            color: #6f42c1;
        }

        @media (max-width: 1100px) {
            .dashboard-grid {
                grid-template-columns: repeat(2, minmax(160px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Task Dashboard</h1>
        <p>Overview of pending, overdue, high-priority, completed, and Course Report follow-up tasks.</p>
    </div>

    <div class="actions">
    <a href="tasks.php" class="btn btn-secondary">Back to Tasks</a>
    <a href="my_tasks.php" class="btn">My Tasks</a>
    <a href="create_task.php" class="btn">Add New Task</a>
    <a href="task_status_logs.php" class="btn">Status Logs</a>
    <a href="course_report_followup_actions.php" class="btn btn-purple">Course Report Follow-up</a>
    <a href="export_task_dashboard_csv.php" class="btn btn-purple">Export Dashboard CSV</a>
</div>

    <div class="dashboard-grid">
        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($totalTasks); ?></div>
            <div class="dashboard-label">Total Tasks</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number metric-orange"><?php echo safeText($pendingTasks); ?></div>
            <div class="dashboard-label">Pending Tasks</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($inProgressTasks); ?></div>
            <div class="dashboard-label">In Progress</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number metric-green"><?php echo safeText($completedTasks); ?></div>
            <div class="dashboard-label">Completed Tasks</div>
        </div>
    </div>

    <div class="dashboard-grid">
        <div class="dashboard-card">
            <div class="dashboard-number metric-red"><?php echo safeText($overdueTasks); ?></div>
            <div class="dashboard-label">Overdue Tasks</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number metric-red"><?php echo safeText($highPriorityTasks); ?></div>
            <div class="dashboard-label">High Priority Tasks</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number metric-purple"><?php echo safeText($courseReportFollowupTasks); ?></div>
            <div class="dashboard-label">Course Report Follow-up Tasks</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($cancelledTasks); ?></div>
            <div class="dashboard-label">Cancelled Tasks</div>
        </div>
    </div>

    <div class="panel">
        <h2>Tasks Needing Attention</h2>
        <p class="small-text">Shows overdue and high-priority tasks first.</p>

        <table>
            <tr>
                <th>ID</th>
                <th>Task</th>
                <th>Responsible</th>
                <th>Due Date</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Linked Accreditation</th>
                <th>Related Record</th>
                <th>Actions</th>
            </tr>

            <?php if (count($attentionTasks) > 0): ?>
                <?php foreach ($attentionTasks as $task): ?>
                    <tr>
                        <td><?php echo safeText($task["id"]); ?></td>

                        <td><?php echo safeText($task["task_title"]); ?></td>

                        <td><?php echo safeText($task["responsible_person"]); ?></td>

                        <td>
                            <?php echo safeText($task["due_date"]); ?>

                            <?php if (!empty($task["due_date"]) && strtotime($task["due_date"]) < strtotime(date("Y-m-d")) && !in_array($task["status"], ["Completed", "Cancelled"], true)): ?>
                                <br><span class="overdue">Overdue</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="<?php echo safeText(getPriorityClass($task["priority"])); ?>">
                                <?php echo safeText($task["priority"]); ?>
                            </span>
                        </td>

                        <td>
                            <span class="<?php echo safeText(getStatusClass($task["status"])); ?>">
                                <?php echo safeText($task["status"]); ?>
                            </span>
                        </td>

                        <td>
                            <?php if (!empty($task["substandard_code"])): ?>
                                <?php echo safeText($task["standard_code"]); ?>
                                -
                                <?php echo safeText($task["substandard_code"]); ?>
                            <?php else: ?>
                                <span class="small-text">Not linked</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($task["related_module"]) && !empty($task["related_record_id"])): ?>
                                <?php echo safeText($task["related_module"]); ?>
                                #<?php echo safeText($task["related_record_id"]); ?>
                            <?php else: ?>
                                <span class="small-text">Not linked</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <a href="edit_task.php?id=<?php echo safeText($task["id"]); ?>" class="btn btn-edit">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9">No high-priority or overdue tasks found.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Course Report Follow-up Tasks</h2>

        <table>
            <tr>
                <th>ID</th>
                <th>Task</th>
                <th>Course Report</th>
                <th>Responsible</th>
                <th>Due Date</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>

            <?php if (count($courseReportTasks) > 0): ?>
                <?php foreach ($courseReportTasks as $task): ?>
                    <tr>
                        <td><?php echo safeText($task["id"]); ?></td>

                        <td><?php echo safeText($task["task_title"]); ?></td>

                        <td>
                            <?php if (!empty($task["course_code"])): ?>
                                <strong><?php echo safeText($task["course_code"]); ?></strong>
                                -
                                <?php echo safeText($task["course_title"]); ?>
                                <br>
                                <span class="small-text">
                                    <?php echo safeText($task["academic_year"]); ?>
                                    |
                                    <?php echo safeText($task["semester"]); ?>
                                </span>
                            <?php else: ?>
                                Course Report ID:
                                <?php echo safeText($task["related_record_id"]); ?>
                            <?php endif; ?>
                        </td>

                        <td><?php echo safeText($task["responsible_person"]); ?></td>

                        <td><?php echo safeText($task["due_date"]); ?></td>

                        <td>
                            <span class="<?php echo safeText(getPriorityClass($task["priority"])); ?>">
                                <?php echo safeText($task["priority"]); ?>
                            </span>
                        </td>

                        <td>
                            <span class="<?php echo safeText(getStatusClass($task["status"])); ?>">
                                <?php echo safeText($task["status"]); ?>
                            </span>
                        </td>

                        <td>
                            <a href="edit_task.php?id=<?php echo safeText($task["id"]); ?>" class="btn btn-edit">Edit</a>
                            <a href="view_course_report.php?id=<?php echo safeText($task["related_record_id"]); ?>" class="btn">View Report</a>
                            <a href="course_report_completion_check.php?id=<?php echo safeText($task["related_record_id"]); ?>" class="btn">Completion Check</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8">No Course Report follow-up tasks found.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Status Distribution</h2>

        <table>
            <tr>
                <th>Status</th>
                <th>Total</th>
            </tr>

            <?php foreach ($statusRows as $row): ?>
                <tr>
                    <td><?php echo safeText($row["status"]); ?></td>
                    <td><?php echo safeText($row["total"]); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Priority Distribution</h2>

        <table>
            <tr>
                <th>Priority</th>
                <th>Total</th>
            </tr>

            <?php foreach ($priorityRows as $row): ?>
                <tr>
                    <td><?php echo safeText($row["priority"]); ?></td>
                    <td><?php echo safeText($row["total"]); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

</div>

</body>
</html>