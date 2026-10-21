<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"]);

$loggedUserName = $_SESSION["full_name"] ?? "";
$message = "";

// ===============================
// Helper functions
// ===============================
function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

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

function logTaskStatusChange($conn, $taskId, $oldStatus, $newStatus, $changedBy, $source, $comment = "") {
    if (!tableExists($conn, "task_status_logs")) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO task_status_logs
        (
            task_id,
            old_status,
            new_status,
            changed_by,
            change_source,
            change_comment
        )
        VALUES
        (
            :task_id,
            :old_status,
            :new_status,
            :changed_by,
            :change_source,
            :change_comment
        )
    ");

    $stmt->execute([
        ":task_id" => $taskId,
        ":old_status" => $oldStatus,
        ":new_status" => $newStatus,
        ":changed_by" => $changedBy,
        ":change_source" => $source,
        ":change_comment" => $comment
    ]);
}

function isOverdue($dueDate, $status) {
    if (empty($dueDate)) {
        return false;
    }

    if ($status === "Completed" || $status === "Cancelled") {
        return false;
    }

    return strtotime($dueDate) < strtotime(date("Y-m-d"));
}

function getPriorityClass($priority) {
    if ($priority === "High") return "badge-danger";
    if ($priority === "Medium") return "badge-warning";
    if ($priority === "Low") return "badge-success";
    return "badge-muted";
}

function getStatusClass($status) {
    if ($status === "Completed") return "badge-success";
    if ($status === "Pending") return "badge-warning";
    if ($status === "In Progress") return "badge-info";
    if ($status === "Cancelled") return "badge-muted";
    return "badge-muted";
}

// ===============================
// Related columns
// ===============================
$hasRelatedModule = columnExists($conn, "tasks", "related_module");
$hasRelatedRecordId = columnExists($conn, "tasks", "related_record_id");

// ===============================
// Filters
// ===============================
$filter_status = $_GET["status"] ?? "";
$filter_priority = $_GET["priority"] ?? "";
$filter_overdue = $_GET["overdue"] ?? "";
$filter_search = trim($_GET["search"] ?? "");

// ===============================
// Pagination
// ===============================
$recordsPerPage = 10;
$page = isset($_GET["page"]) && is_numeric($_GET["page"]) ? (int)$_GET["page"] : 1;

if ($page < 1) {
    $page = 1;
}

$offset = ($page - 1) * $recordsPerPage;

// ===============================
// Quick status update with history log
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_status"])) {
    $task_id = !empty($_POST["task_id"]) ? (int)$_POST["task_id"] : 0;
    $new_status = trim($_POST["status"] ?? "");

    $allowedStatuses = ["Pending", "In Progress", "Completed", "Cancelled"];

    if ($task_id > 0 && $new_status !== "" && in_array($new_status, $allowedStatuses, true)) {

        if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])) {
            $loadStmt = $conn->prepare("
                SELECT id, status, responsible_person
                FROM tasks
                WHERE id = :id
                LIMIT 1
            ");
            $loadStmt->execute([":id" => $task_id]);
        } else {
            $loadStmt = $conn->prepare("
                SELECT id, status, responsible_person
                FROM tasks
                WHERE id = :id
                  AND responsible_person LIKE :responsible_person
                LIMIT 1
            ");
            $loadStmt->execute([
                ":id" => $task_id,
                ":responsible_person" => "%" . $loggedUserName . "%"
            ]);
        }

        $currentTask = $loadStmt->fetch(PDO::FETCH_ASSOC);

        if (!$currentTask) {
            $message = "No task was updated. You may not have permission to update this task.";
        } else {
            $old_status = $currentTask["status"] ?? "";

            if ($old_status === $new_status) {
                $message = "No change made. Task already has this status.";
            } else {
                $updateStmt = $conn->prepare("
                    UPDATE tasks
                    SET status = :status
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ":status" => $new_status,
                    ":id" => $task_id
                ]);

                logTaskStatusChange(
                    $conn,
                    $task_id,
                    $old_status,
                    $new_status,
                    $loggedUserName,
                    "My Tasks",
                    "Quick status update from My Tasks page."
                );

                logActivity(
                    $conn,
                    "Update",
                    "Tasks",
                    $task_id,
                    "Task status changed from " . $old_status . " to " . $new_status . " from My Tasks page."
                );

                $message = "Task status updated.";
            }
        }
    } else {
        $message = "Invalid status update request.";
    }
}

// ===============================
// FROM / JOIN
// ===============================
$fromSql = "
    FROM tasks t
    LEFT JOIN evidence_items e ON t.evidence_id = e.id
    LEFT JOIN substandards ss ON t.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
";

// ===============================
// WHERE
// ===============================
$whereSql = " WHERE 1 = 1 ";
$params = [];

if (!hasRole(["Admin", "Quality Coordinator", "Program Leader"])) {
    $whereSql .= " AND t.responsible_person LIKE :responsible_person_logged";
    $params[":responsible_person_logged"] = "%" . $loggedUserName . "%";
}

if ($filter_status !== "") {
    $whereSql .= " AND t.status = :status";
    $params[":status"] = $filter_status;
}

if ($filter_priority !== "") {
    $whereSql .= " AND t.priority = :priority";
    $params[":priority"] = $filter_priority;
}

if ($filter_overdue === "1") {
    $whereSql .= "
        AND t.due_date IS NOT NULL
        AND t.due_date < CURDATE()
        AND t.status NOT IN ('Completed', 'Cancelled')
    ";
}

if ($filter_search !== "") {
    $whereSql .= " AND (
        t.task_title LIKE :search
        OR t.task_description LIKE :search
        OR t.notes LIKE :search
        OR t.responsible_person LIKE :search
        OR e.title LIKE :search
        OR ss.substandard_code LIKE :search
        OR ss.substandard_text LIKE :search
        OR s.standard_code LIKE :search
        OR s.standard_title LIKE :search
    ";

    if ($hasRelatedModule) {
        $whereSql .= " OR t.related_module LIKE :search";
    }

    if ($hasRelatedRecordId) {
        $whereSql .= " OR t.related_record_id LIKE :search";
    }

    $whereSql .= " )";

    $params[":search"] = "%" . $filter_search . "%";
}

// ===============================
// Count
// ===============================
$countSql = "
    SELECT COUNT(*)
    $fromSql
    $whereSql
";

$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$totalTasks = (int)$countStmt->fetchColumn();

$totalPages = (int)ceil($totalTasks / $recordsPerPage);

if ($totalPages < 1) {
    $totalPages = 1;
}

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $recordsPerPage;
}

$recordsPerPage = (int)$recordsPerPage;
$offset = (int)$offset;

// ===============================
// Related columns select
// ===============================
$relatedModuleSelect = $hasRelatedModule ? "t.related_module" : "NULL AS related_module";
$relatedRecordIdSelect = $hasRelatedRecordId ? "t.related_record_id" : "NULL AS related_record_id";

// ===============================
// Load tasks
// ===============================
$sql = "
    SELECT 
        t.id,
        t.task_title,
        t.task_description,
        t.responsible_person,
        t.due_date,
        t.priority,
        t.status,
        t.notes,
t.created_at,
t.updated_at,

(
    SELECT COUNT(*)
    FROM task_status_logs tsl
    WHERE tsl.task_id = t.id
) AS status_log_count,

$relatedModuleSelect,
$relatedRecordIdSelect,

        e.title AS evidence_title,

        ss.substandard_code,
        ss.substandard_text,

        s.standard_code,
        s.standard_title

    $fromSql
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

    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Dashboard counts
// ===============================
$countStatusSql = "
    SELECT 
        SUM(CASE WHEN t.status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_count,
        SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) AS completed_count,
        SUM(
            CASE 
                WHEN t.due_date IS NOT NULL
                 AND t.due_date < CURDATE()
                 AND t.status NOT IN ('Completed', 'Cancelled')
                THEN 1 ELSE 0 
            END
        ) AS overdue_count
    $fromSql
    $whereSql
";

$countStatusStmt = $conn->prepare($countStatusSql);
$countStatusStmt->execute($params);
$statusCounts = $countStatusStmt->fetch(PDO::FETCH_ASSOC);

$pendingCount = (int)($statusCounts["pending_count"] ?? 0);
$inProgressCount = (int)($statusCounts["in_progress_count"] ?? 0);
$completedCount = (int)($statusCounts["completed_count"] ?? 0);
$overdueCount = (int)($statusCounts["overdue_count"] ?? 0);

// ===============================
// Pagination base URL
// ===============================
$queryParams = $_GET;
unset($queryParams["page"]);

$baseQueryString = http_build_query($queryParams);
$basePageUrl = "my_tasks.php" . ($baseQueryString !== "" ? "?" . $baseQueryString . "&" : "?");
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>My Tasks</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 14px;
        }

        .card-number {
            font-size: 30px;
            font-weight: bold;
            color: #007bff;
            line-height: 1;
        }

        .warning-number {
            color: #dc3545;
        }

        .pending-number {
            color: #fd7e14;
        }

        .card-label {
            color: #555;
            margin-top: 8px;
            font-size: 13px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 12px;
            align-items: end;
        }

        .checkbox-line {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: bold;
            margin-top: 24px;
        }

        .checkbox-line input {
            width: auto;
        }

        .message {
            background: white;
            padding: 10px;
            border: 1px solid #ccc;
            color: #28a745;
            font-weight: bold;
            margin-bottom: 15px;
            border-radius: 6px;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .missing {
            color: #999;
            font-style: italic;
        }

        .overdue {
            color: #dc3545;
            font-weight: bold;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            white-space: nowrap;
        }

        .badge-danger {
            background: #f8d7da;
            color: #842029;
        }

        .badge-warning {
            background: #fff3cd;
            color: #664d03;
        }

        .badge-success {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-info {
            background: #cff4fc;
            color: #055160;
        }

        .badge-muted {
            background: #e9ecef;
            color: #495057;
        }

        .related-badge {
            background: #e7d8ff;
            color: #4b148c;
        }

        .quick-update {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: nowrap;
        }

        .quick-update select {
            width: 130px;
            padding: 6px;
            font-size: 12px;
        }

        .task-title {
            font-weight: bold;
            color: #222;
        }

        .task-description {
            margin-top: 5px;
            color: #555;
            font-size: 12px;
        }

        .action-links {
            margin-top: 8px;
        }

        .pagination {
            margin-top: 18px;
            margin-bottom: 20px;
            background: white;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .pagination a,
        .pagination span {
            display: inline-block;
            padding: 7px 10px;
            margin-right: 4px;
            margin-bottom: 4px;
            border-radius: 4px;
            text-decoration: none;
            border: 1px solid #ccc;
            color: #007bff;
            background: #fff;
            font-size: 13px;
        }

        .pagination .current-page {
            background: #007bff;
            color: white;
            border-color: #007bff;
            font-weight: bold;
        }

        .pagination .disabled {
            color: #999;
            background: #f2f2f2;
        }

        .pagination-info {
            margin-bottom: 8px;
            font-size: 13px;
            color: #555;
        }

        @media (max-width: 1200px) {
            .dashboard-cards,
            .filter-grid {
                grid-template-columns: repeat(2, minmax(160px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .dashboard-cards,
            .filter-grid {
                grid-template-columns: 1fr;
            }

            .quick-update {
                flex-wrap: wrap;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>My Tasks</h1>
        <p>Personal and assigned quality tasks for the logged-in user.</p>
    </div>

    <p>
        Logged-in user:
        <strong><?php echo safeText($loggedUserName); ?></strong>
    </p>

    <?php if ($message !== ""): ?>
        <div class="message">
            <?php echo safeText($message); ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <div class="dashboard-cards">
            <div class="card">
                <div class="card-number"><?php echo safeText($totalTasks); ?></div>
                <div class="card-label">Total Filtered Tasks</div>
            </div>

            <div class="card">
                <div class="card-number pending-number"><?php echo safeText($pendingCount); ?></div>
                <div class="card-label">Pending</div>
            </div>

            <div class="card">
                <div class="card-number"><?php echo safeText($inProgressCount); ?></div>
                <div class="card-label">In Progress</div>
            </div>

            <div class="card">
                <div class="card-number warning-number"><?php echo safeText($overdueCount); ?></div>
                <div class="card-label">Overdue</div>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>Filter My Tasks</h2>

        <form method="GET" action="my_tasks.php">
            <div class="filter-grid">
                <div>
                    <label>Search</label>
                    <input type="text" name="search"
                           value="<?php echo safeText($filter_search); ?>"
                           placeholder="Search task, evidence, standard, course report">
                </div>

                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach (["Pending", "In Progress", "Completed", "Cancelled"] as $status): ?>
                            <option value="<?php echo safeText($status); ?>"
                                <?php if ($filter_status === $status) echo "selected"; ?>>
                                <?php echo safeText($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Priority</label>
                    <select name="priority">
                        <option value="">All Priorities</option>
                        <?php foreach (["High", "Medium", "Low"] as $priority): ?>
                            <option value="<?php echo safeText($priority); ?>"
                                <?php if ($filter_priority === $priority) echo "selected"; ?>>
                                <?php echo safeText($priority); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="checkbox-line">
                        <input type="checkbox" name="overdue" value="1"
                            <?php if ($filter_overdue === "1") echo "checked"; ?>>
                        Overdue only
                    </label>
                </div>
            </div>

            <br>

            <button type="submit" class="btn">Apply Filters</button>
            <a href="my_tasks.php" class="btn btn-secondary">Clear Filters</a>
            <a href="tasks.php" class="btn">All Tasks</a>
            <a href="task_dashboard.php" class="btn btn-purple">Task Dashboard</a>
            <a href="task_status_logs.php" class="btn">Status Logs</a>
        </form>
    </div>

    <div class="panel">
        <h2>Task List</h2>

        <div class="pagination-info">
            Page <?php echo safeText($page); ?> of <?php echo safeText($totalPages); ?>
            |
            <?php echo safeText($recordsPerPage); ?> records per page
            |
            Total records: <?php echo safeText($totalTasks); ?>
        </div>

        <table>
            <tr>
                <th>ID</th>
                <th>Task</th>
                <th>Standard / Substandard</th>
                <th>Evidence</th>
                <th>Related Record</th>
                <th>Responsible</th>
                <th>Due Date</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Quick Update</th>
                <th>Notes</th>
            </tr>

            <?php if (count($tasks) > 0): ?>

                <?php foreach ($tasks as $task): ?>
                    <tr>
                        <td><?php echo safeText($task["id"]); ?></td>

                        <td>
                            <div class="task-title">
                                <?php echo safeText($task["task_title"]); ?>
                            </div>

                            <?php if (!empty($task["task_description"])): ?>
                                <div class="task-description">
                                    <?php echo nl2br(safeText($task["task_description"])); ?>
                                </div>
                            <?php endif; ?>

                            <div class="action-links">
                                <a href="edit_task.php?id=<?php echo safeText($task["id"]); ?>">
                                    Full Edit
                                </a>
                                |
                                <a href="task_status_logs.php?task_id=<?php echo safeText($task["id"]); ?>">
    				Status Log (<?php echo safeText($task["status_log_count"] ?? 0); ?>)

                                </a>
                            </div>
                        </td>

                        <td>
                            <?php if (!empty($task["substandard_code"])): ?>
                                <strong><?php echo safeText($task["standard_code"]); ?></strong><br>
                                <span class="badge badge-muted">
                                    <?php echo safeText($task["substandard_code"]); ?>
                                </span>
                                <br>
                                <span class="small-text">
                                    <?php echo safeText($task["substandard_text"]); ?>
                                </span>
                            <?php else: ?>
                                <span class="missing">Not linked</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($task["evidence_title"])): ?>
                                <?php echo safeText($task["evidence_title"]); ?>
                            <?php else: ?>
                                <span class="missing">Not linked</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($task["related_module"]) && !empty($task["related_record_id"])): ?>
                                <span class="badge related-badge">
                                    <?php echo safeText($task["related_module"]); ?>
                                </span>
                                <br>
                                <span class="small-text">
                                    Record ID: <?php echo safeText($task["related_record_id"]); ?>
                                </span>

                                <?php if ($task["related_module"] === "Course Reports"): ?>
                                    <br>
                                    <a href="view_course_report.php?id=<?php echo safeText($task["related_record_id"]); ?>" class="small-text">
                                        View Course Report
                                    </a>
                                    <br>
                                    <a href="course_report_completion_check.php?id=<?php echo safeText($task["related_record_id"]); ?>" class="small-text">
                                        Completion Check
                                    </a>
                                    <br>
                                    <a href="manage_course_report_details.php?id=<?php echo safeText($task["related_record_id"]); ?>" class="small-text">
                                        Manage Details
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="missing">Not linked</span>
                            <?php endif; ?>
                        </td>

                        <td><?php echo safeText($task["responsible_person"] ?? ""); ?></td>

                        <td>
                            <?php if (!empty($task["due_date"])): ?>
                                <?php echo safeText($task["due_date"]); ?>

                                <?php if (isOverdue($task["due_date"], $task["status"])): ?>
                                    <br><span class="overdue">Overdue</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="missing">No due date</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="badge <?php echo safeText(getPriorityClass($task["priority"] ?? "")); ?>">
                                <?php echo safeText($task["priority"] ?? ""); ?>
                            </span>
                        </td>

                        <td>
                            <span class="badge <?php echo safeText(getStatusClass($task["status"] ?? "")); ?>">
                                <?php echo safeText($task["status"] ?? ""); ?>
                            </span>
                        </td>

                        <td>
                            <form method="POST" action="my_tasks.php" class="quick-update">
                                <input type="hidden" name="task_id" value="<?php echo safeText($task["id"]); ?>">

                                <select name="status">
                                    <?php foreach (["Pending", "In Progress", "Completed", "Cancelled"] as $status): ?>
                                        <option value="<?php echo safeText($status); ?>"
                                            <?php if (($task["status"] ?? "") === $status) echo "selected"; ?>>
                                            <?php echo safeText($status); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <button type="submit" name="update_status" class="btn btn-small">
                                    Update
                                </button>
                            </form>
                        </td>

                        <td><?php echo nl2br(safeText($task["notes"] ?? "")); ?></td>
                    </tr>
                <?php endforeach; ?>

            <?php else: ?>
                <tr>
                    <td colspan="11">No tasks found for your account or selected filters.</td>
                </tr>
            <?php endif; ?>

        </table>

        <div class="pagination">
            <div class="pagination-info">
                Page <?php echo safeText($page); ?> of <?php echo safeText($totalPages); ?>
                |
                <?php echo safeText($recordsPerPage); ?> records per page
                |
                Total records: <?php echo safeText($totalTasks); ?>
            </div>

            <?php if ($page > 1): ?>
                <a href="<?php echo safeText($basePageUrl); ?>page=1">First</a>
                <a href="<?php echo safeText($basePageUrl); ?>page=<?php echo safeText($page - 1); ?>">Previous</a>
            <?php else: ?>
                <span class="disabled">First</span>
                <span class="disabled">Previous</span>
            <?php endif; ?>

            <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
            ?>

            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current-page"><?php echo safeText($i); ?></span>
                <?php else: ?>
                    <a href="<?php echo safeText($basePageUrl); ?>page=<?php echo safeText($i); ?>">
                        <?php echo safeText($i); ?>
                    </a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?php echo safeText($basePageUrl); ?>page=<?php echo safeText($page + 1); ?>">Next</a>
                <a href="<?php echo safeText($basePageUrl); ?>page=<?php echo safeText($totalPages); ?>">Last</a>
            <?php else: ?>
                <span class="disabled">Next</span>
                <span class="disabled">Last</span>
            <?php endif; ?>
        </div>

    </div>

</div>

</body>
</html>