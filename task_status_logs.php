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

if (!tableExists($conn, "task_status_logs")) {
    die("task_status_logs table does not exist. Create it first.");
}

// ===============================
// Filters
// ===============================
$filter_task_id = trim($_GET["task_id"] ?? "");
$filter_new_status = trim($_GET["new_status"] ?? "");
$filter_changed_by = trim($_GET["changed_by"] ?? "");
$filter_source = trim($_GET["change_source"] ?? "");
$filter_search = trim($_GET["search"] ?? "");

// ===============================
// Pagination
// ===============================
$recordsPerPage = 20;
$page = isset($_GET["page"]) && is_numeric($_GET["page"]) ? (int)$_GET["page"] : 1;

if ($page < 1) {
    $page = 1;
}

$offset = ($page - 1) * $recordsPerPage;

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
// Count rows
// ===============================
$countSql = "
    SELECT COUNT(*)
    FROM task_status_logs l
    LEFT JOIN tasks t ON l.task_id = t.id
    $whereSql
";

$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

$totalPages = (int)ceil($totalRecords / $recordsPerPage);

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
// Load logs
// ===============================
$sql = "
    SELECT
        l.id,
        l.task_id,
        l.old_status,
        l.new_status,
        l.changed_by,
        l.change_source,
        l.change_comment,
        l.created_at,

        t.task_title,
        t.task_description,
        t.responsible_person,
        t.priority,
        t.status AS current_status,
        t.related_module,
        t.related_record_id

    FROM task_status_logs l

    LEFT JOIN tasks t 
        ON l.task_id = t.id

    $whereSql

    ORDER BY l.id DESC

    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Summary counts
// ===============================
$summaryStmt = $conn->query("
    SELECT
        COUNT(*) AS total_logs,
        SUM(CASE WHEN new_status = 'Pending' THEN 1 ELSE 0 END) AS pending_logs,
        SUM(CASE WHEN new_status = 'In Progress' THEN 1 ELSE 0 END) AS progress_logs,
        SUM(CASE WHEN new_status = 'Completed' THEN 1 ELSE 0 END) AS completed_logs,
        SUM(CASE WHEN new_status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_logs
    FROM task_status_logs
");

$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$totalLogs = (int)($summary["total_logs"] ?? 0);
$pendingLogs = (int)($summary["pending_logs"] ?? 0);
$progressLogs = (int)($summary["progress_logs"] ?? 0);
$completedLogs = (int)($summary["completed_logs"] ?? 0);
$cancelledLogs = (int)($summary["cancelled_logs"] ?? 0);

// ===============================
// Pagination base URL
// ===============================
$queryParams = $_GET;
unset($queryParams["page"]);

$baseQueryString = http_build_query($queryParams);
$basePageUrl = "task_status_logs.php" . ($baseQueryString !== "" ? "?" . $baseQueryString . "&" : "?");
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Task Status Update Log</title>
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

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(140px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .dashboard-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
        }

        .dashboard-number {
            font-size: 28px;
            font-weight: bold;
            color: #007bff;
        }

        .dashboard-label {
            color: #555;
            font-size: 13px;
            margin-top: 6px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(150px, 1fr));
            gap: 12px;
            align-items: end;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .missing {
            color: #999;
            font-style: italic;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-pending {
            background: #fff3cd;
            color: #664d03;
        }

        .status-progress {
            background: #cff4fc;
            color: #055160;
        }

        .status-completed {
            background: #d1e7dd;
            color: #0f5132;
        }

        .status-cancelled {
            background: #e9ecef;
            color: #495057;
        }

        .status-other {
            background: #f8f9fa;
            color: #333;
            border: 1px solid #ddd;
        }

        .explain-box {
            background: #f8f9fa;
            border-left: 4px solid #007bff;
            padding: 12px;
            margin-bottom: 18px;
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

        .actions {
            margin-bottom: 18px;
        }

        @media (max-width: 1100px) {
            .dashboard-grid,
            .filter-grid {
                grid-template-columns: repeat(2, minmax(150px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .dashboard-grid,
            .filter-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Task Status Update Log</h1>
        <p>Audit trail of task status changes.</p>
    </div>

    <div class="actions">
        <a href="task_dashboard.php" class="btn btn-purple">Task Dashboard</a>
        <a href="tasks.php" class="btn">All Tasks</a>
        <a href="my_tasks.php" class="btn">My Tasks</a>
    </div>

    <div class="explain-box">
        <strong>Responsible Person</strong> means the task owner.
        <br>
        <strong>Changed By</strong> means the logged-in user who changed the task status.
        <br>
        These two names can be different, and that is correct for audit tracking.
    </div>

    <div class="dashboard-grid">
        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($totalLogs); ?></div>
            <div class="dashboard-label">Total Status Changes</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($pendingLogs); ?></div>
            <div class="dashboard-label">Changed to Pending</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($progressLogs); ?></div>
            <div class="dashboard-label">Changed to In Progress</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($completedLogs); ?></div>
            <div class="dashboard-label">Changed to Completed</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($cancelledLogs); ?></div>
            <div class="dashboard-label">Changed to Cancelled</div>
        </div>
    </div>

    <div class="panel">
        <h2>Filter Status Logs</h2>

        <form method="GET" action="task_status_logs.php">
            <div class="filter-grid">
                <div>
                    <label>Task ID</label>
                    <input type="text" name="task_id"
                           value="<?php echo safeText($filter_task_id); ?>"
                           placeholder="Example: 12">
                </div>

                <div>
                    <label>New Status</label>
                    <select name="new_status">
                        <option value="">All Statuses</option>

                        <?php
                        $statuses = ["Pending", "In Progress", "Completed", "Cancelled"];
                        foreach ($statuses as $status):
                        ?>
                            <option value="<?php echo safeText($status); ?>"
                                <?php if ($filter_new_status === $status) echo "selected"; ?>>
                                <?php echo safeText($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Changed By</label>
                    <input type="text" name="changed_by"
                           value="<?php echo safeText($filter_changed_by); ?>"
                           placeholder="User name">
                </div>

                <div>
                    <label>Source</label>
                    <select name="change_source">
                        <option value="">All Sources</option>
                        <option value="My Tasks" <?php if ($filter_source === "My Tasks") echo "selected"; ?>>My Tasks</option>
                        <option value="Edit Task" <?php if ($filter_source === "Edit Task") echo "selected"; ?>>Edit Task</option>
                    </select>
                </div>

                <div>
                    <label>Search</label>
                    <input type="text" name="search"
                           value="<?php echo safeText($filter_search); ?>"
                           placeholder="Task title, person, comment">
                </div>
            </div>

            <br>

            <button type="submit" class="btn">Apply Filters</button>
<a href="task_status_logs.php" class="btn btn-secondary">Clear Filters</a>
<a href="export_task_status_logs_csv.php?<?php echo safeText(http_build_query($_GET)); ?>" class="btn btn-purple">
    Export Status Logs CSV
</a>
        </form>
    </div>

    <div class="panel">
        <h2>Status Change History</h2>

        <div class="small-text">
            Page <?php echo safeText($page); ?> of <?php echo safeText($totalPages); ?>
            |
            <?php echo safeText($recordsPerPage); ?> records per page
            |
            Total records: <?php echo safeText($totalRecords); ?>
        </div>

        <br>

        <table>
            <tr>
                <th>ID</th>
                <th>Task</th>
                <th>Responsible Person</th>
                <th>Old Status</th>
                <th>New Status</th>
                <th>Changed By</th>
                <th>Source</th>
                <th>Comment</th>
                <th>Date/Time</th>
                <th>Actions</th>
            </tr>

            <?php if (count($logs) > 0): ?>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $oldClass = "status-other";
                    $newClass = "status-other";

                    if ($log["old_status"] === "Pending") $oldClass = "status-pending";
                    if ($log["old_status"] === "In Progress") $oldClass = "status-progress";
                    if ($log["old_status"] === "Completed") $oldClass = "status-completed";
                    if ($log["old_status"] === "Cancelled") $oldClass = "status-cancelled";

                    if ($log["new_status"] === "Pending") $newClass = "status-pending";
                    if ($log["new_status"] === "In Progress") $newClass = "status-progress";
                    if ($log["new_status"] === "Completed") $newClass = "status-completed";
                    if ($log["new_status"] === "Cancelled") $newClass = "status-cancelled";
                    ?>
                    <tr>
                        <td><?php echo safeText($log["id"]); ?></td>

                        <td>
                            <?php if (!empty($log["task_title"])): ?>
                                <strong><?php echo safeText($log["task_title"]); ?></strong>
                                <br>
                                <span class="small-text">
                                    Task ID: <?php echo safeText($log["task_id"]); ?>
                                </span>
                            <?php else: ?>
                                <span class="missing">
                                    Deleted task / Task ID: <?php echo safeText($log["task_id"]); ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <td><?php echo safeText($log["responsible_person"]); ?></td>

                        <td>
                            <span class="status-badge <?php echo safeText($oldClass); ?>">
                                <?php echo safeText($log["old_status"]); ?>
                            </span>
                        </td>

                        <td>
                            <span class="status-badge <?php echo safeText($newClass); ?>">
                                <?php echo safeText($log["new_status"]); ?>
                            </span>
                        </td>

                        <td><?php echo safeText($log["changed_by"]); ?></td>

                        <td><?php echo safeText($log["change_source"]); ?></td>

                        <td><?php echo nl2br(safeText($log["change_comment"])); ?></td>

                        <td><?php echo safeText($log["created_at"]); ?></td>

                        <td>
                            <?php if (!empty($log["task_title"])): ?>
                                <a href="edit_task.php?id=<?php echo safeText($log["task_id"]); ?>" class="btn btn-edit">
                                    Edit Task
                                </a>

                                <?php if (($log["related_module"] ?? "") === "Course Reports" && !empty($log["related_record_id"])): ?>
                                    <a href="view_course_report.php?id=<?php echo safeText($log["related_record_id"]); ?>" class="btn">
                                        View Report
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="missing">No actions</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10">No status log records found.</td>
                </tr>
            <?php endif; ?>
        </table>

        <div class="pagination">
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