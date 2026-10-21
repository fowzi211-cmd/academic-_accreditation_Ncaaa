<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

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

function getStatusClass($status) {
    if ($status === "Completed" || $status === "Closed") {
        return "badge-active";
    }

    if ($status === "Pending" || $status === "In Progress" || $status === "Open") {
        return "badge-warning";
    }

    if ($status === "Cancelled" || $status === "Overdue") {
        return "badge-danger";
    }

    return "badge-muted";
}

function getPriorityClass($priority) {
    if ($priority === "High") {
        return "badge-danger";
    }

    if ($priority === "Medium") {
        return "badge-warning";
    }

    if ($priority === "Low") {
        return "badge-active";
    }

    return "badge-muted";
}

function buildPaginationUrl($pageNumber) {
    $queryParams = $_GET;
    $queryParams["page"] = $pageNumber;
    return "committee_tasks_dashboard.php?" . http_build_query($queryParams);
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

$filter_search = trim($_GET["search"] ?? "");
$filter_committee_id = trim($_GET["committee_id"] ?? "");
$filter_status = trim($_GET["status"] ?? "");
$filter_priority = trim($_GET["priority"] ?? "");
$filter_responsible = trim($_GET["responsible_person"] ?? "");
$filter_overdue = trim($_GET["overdue"] ?? "");

$recordsPerPage = 10;
$currentPage = isset($_GET["page"]) && is_numeric($_GET["page"]) ? (int)$_GET["page"] : 1;

if ($currentPage < 1) {
    $currentPage = 1;
}

$offset = ($currentPage - 1) * $recordsPerPage;

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

$fromSql = "
    FROM committee_recommendations r
    INNER JOIN tasks t ON r.linked_task_id = t.id
    LEFT JOIN committees c ON r.committee_id = c.id
    LEFT JOIN committee_meetings m ON r.meeting_id = m.id
";

$countSql = "
    SELECT COUNT(*)
    $fromSql
    $whereSql
";

$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$totalResults = (int)$countStmt->fetchColumn();

$totalPages = (int)ceil($totalResults / $recordsPerPage);

if ($totalPages < 1) {
    $totalPages = 1;
}

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
    $offset = ($currentPage - 1) * $recordsPerPage;
}

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
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summaryStmt = $conn->query("
    SELECT
        COUNT(*) AS total_tasks,
        SUM(CASE WHEN t.status = 'Pending' THEN 1 ELSE 0 END) AS pending_tasks,
        SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
        SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) AS completed_tasks,
        SUM(CASE
            WHEN t.due_date IS NOT NULL
             AND t.due_date < CURDATE()
             AND t.status NOT IN ('Completed', 'Cancelled')
            THEN 1 ELSE 0
        END) AS overdue_tasks,
        SUM(CASE WHEN t.priority = 'High' THEN 1 ELSE 0 END) AS high_priority_tasks
    FROM committee_recommendations r
    INNER JOIN tasks t ON r.linked_task_id = t.id
");

$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$totalTasks = (int)($summary["total_tasks"] ?? 0);
$pendingTasks = (int)($summary["pending_tasks"] ?? 0);
$inProgressTasks = (int)($summary["in_progress_tasks"] ?? 0);
$completedTasks = (int)($summary["completed_tasks"] ?? 0);
$overdueTasks = (int)($summary["overdue_tasks"] ?? 0);
$highPriorityTasks = (int)($summary["high_priority_tasks"] ?? 0);

$committeeRows = $conn->query("
    SELECT id, committee_name, academic_year
    FROM committees
    ORDER BY academic_year DESC, committee_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$statusOptions = ["Pending", "In Progress", "Completed", "Cancelled"];
$priorityOptions = ["High", "Medium", "Low"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Committee Tasks Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(130px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .summary-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
        }

        .summary-number {
            font-size: 26px;
            font-weight: bold;
            color: #007bff;
        }

        .summary-label {
            color: #555;
            font-size: 13px;
            margin-top: 6px;
        }

        .filter-box {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 22px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 12px;
        }

        .filter-actions {
            margin-top: 15px;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-active {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-warning {
            background: #fff3cd;
            color: #664d03;
        }

        .badge-danger {
            background: #f8d7da;
            color: #842029;
        }

        .badge-muted {
            background: #e9ecef;
            color: #495057;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .missing {
            color: #999;
            font-style: italic;
        }

        .overdue-row {
            background: #fff3cd;
        }

        .actions a {
            display: block;
            width: 115px;
            text-align: center;
            margin-bottom: 5px;
        }

        .page-summary {
            margin-top: 10px;
            margin-bottom: 10px;
            color: #555;
            font-size: 14px;
        }

        .pagination {
            margin-top: 20px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #ccc;
            background: #fff;
            text-decoration: none;
            color: #333;
            border-radius: 4px;
            font-size: 14px;
        }

        .pagination .active-page {
            background: #007bff;
            color: white;
            border-color: #007bff;
            font-weight: bold;
        }

        .pagination .disabled-page {
            color: #aaa;
            background: #f5f5f5;
        }

        @media (max-width: 1200px) {
            .summary-grid,
            .filter-grid {
                grid-template-columns: repeat(2, minmax(160px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .summary-grid,
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
        <h1>Committee Tasks Dashboard</h1>
        <p>Monitor formal tasks created from committee recommendations.</p>
    </div>

    <div class="actions">
    <a href="committee_recommendations_dashboard.php" class="btn btn-secondary">Recommendations</a>
    <a href="committees.php" class="btn">Committees</a>
    <a href="tasks.php" class="btn">All Tasks</a>

    <a href="export_committee_tasks_csv.php?<?php echo safeText(http_build_query($_GET)); ?>" class="btn btn-purple">
        Export CSV
    </a>
</div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalTasks); ?></div>
            <div class="summary-label">Committee Tasks</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($pendingTasks); ?></div>
            <div class="summary-label">Pending</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($inProgressTasks); ?></div>
            <div class="summary-label">In Progress</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($completedTasks); ?></div>
            <div class="summary-label">Completed</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($overdueTasks); ?></div>
            <div class="summary-label">Overdue</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($highPriorityTasks); ?></div>
            <div class="summary-label">High Priority</div>
        </div>
    </div>

    <div class="filter-box">
        <h2>Filter Committee Tasks</h2>

        <form method="GET" action="committee_tasks_dashboard.php">
            <div class="filter-grid">
                <div>
                    <label>Search</label>
                    <input type="text"
                           name="search"
                           value="<?php echo safeText($filter_search); ?>"
                           placeholder="Task, recommendation, committee">
                </div>

                <div>
                    <label>Committee</label>
                    <select name="committee_id">
                        <option value="">All Committees</option>
                        <?php foreach ($committeeRows as $committee): ?>
                            <option value="<?php echo safeText($committee["id"]); ?>"
                                <?php if ((string)$filter_committee_id === (string)$committee["id"]) echo "selected"; ?>>
                                <?php echo safeText($committee["committee_name"] . " | " . $committee["academic_year"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Task Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?php echo safeText($status); ?>" <?php if ($filter_status === $status) echo "selected"; ?>>
                                <?php echo safeText($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Task Priority</label>
                    <select name="priority">
                        <option value="">All Priorities</option>
                        <?php foreach ($priorityOptions as $priority): ?>
                            <option value="<?php echo safeText($priority); ?>" <?php if ($filter_priority === $priority) echo "selected"; ?>>
                                <?php echo safeText($priority); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Responsible Person</label>
                    <input type="text"
                           name="responsible_person"
                           value="<?php echo safeText($filter_responsible); ?>"
                           placeholder="Name">
                </div>

                <div>
                    <label>Overdue</label>
                    <select name="overdue">
                        <option value="">All</option>
                        <option value="1" <?php if ($filter_overdue === "1") echo "selected"; ?>>
                            Overdue Only
                        </option>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn">Apply Filters</button>

    <a href="committee_tasks_dashboard.php" class="btn btn-secondary">
        Clear Filters
    </a>

    <a href="export_committee_tasks_csv.php?<?php echo safeText(http_build_query($_GET)); ?>" class="btn btn-purple">
        Export CSV
    </a>
            </div>
        </form>
    </div>

    <div class="page-summary">
        Page <?php echo safeText($currentPage); ?> of <?php echo safeText($totalPages); ?>
        |
        <?php echo safeText($recordsPerPage); ?> records per page
        |
        Total filtered records: <?php echo safeText($totalResults); ?>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>Task</th>
            <th>Source Committee / Meeting</th>
            <th>Recommendation</th>
            <th>Responsible / Due Date</th>
            <th>Priority / Status</th>
            <th>Actions</th>
        </tr>

        <?php if (count($rows) > 0): ?>
            <?php foreach ($rows as $row): ?>
                <?php
                    $isOverdue = false;

                    if (!empty($row["due_date"]) &&
                        $row["due_date"] < date("Y-m-d") &&
                        !in_array($row["task_status"], ["Completed", "Cancelled"], true)) {
                        $isOverdue = true;
                    }
                ?>

                <tr class="<?php echo $isOverdue ? 'overdue-row' : ''; ?>">
                    <td><?php echo safeText($row["task_id"]); ?></td>

                    <td>
                        <strong><?php echo safeText($row["task_title"]); ?></strong>

                        <?php if (!empty($row["task_description"])): ?>
                            <br>
                            <span class="small-text">
                                <?php echo safeText(mb_substr($row["task_description"], 0, 180)); ?>
                                <?php if (mb_strlen($row["task_description"]) > 180) echo "..."; ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <a href="committee_profile.php?id=<?php echo safeText($row["committee_id"]); ?>">
                            <strong><?php echo safeText($row["committee_name"]); ?></strong>
                        </a>
                        <br>
                        <span class="small-text">
                            <?php echo safeText($row["committee_type"]); ?>
                            |
                            <?php echo safeText($row["academic_year"]); ?>
                        </span>
                        <br>
                        <a href="committee_meeting_profile.php?id=<?php echo safeText($row["meeting_id"]); ?>">
                            <?php echo safeText($row["meeting_title"]); ?>
                        </a>
                        <br>
                        <span class="small-text">
                            Meeting Date: <?php echo safeText($row["meeting_date"]); ?>
                        </span>
                    </td>

                    <td>
                        <?php echo nl2br(safeText($row["recommendation_text"])); ?>

                        <?php if (!empty($row["followup_notes"])): ?>
                            <br>
                            <span class="small-text">
                                Follow-up: <?php echo safeText($row["followup_notes"]); ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($row["responsible_person"])): ?>
                            <?php echo safeText($row["responsible_person"]); ?>
                        <?php else: ?>
                            <span class="missing">Not assigned</span>
                        <?php endif; ?>

                        <br>

                        <span class="small-text">
                            Due: <?php echo safeText($row["due_date"]); ?>
                        </span>

                        <?php if ($isOverdue): ?>
                            <br>
                            <span class="badge badge-danger">Overdue</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <span class="badge <?php echo safeText(getPriorityClass($row["task_priority"])); ?>">
                            <?php echo safeText($row["task_priority"]); ?>
                        </span>

                        <br><br>

                        <span class="badge <?php echo safeText(getStatusClass($row["task_status"])); ?>">
                            <?php echo safeText($row["task_status"]); ?>
                        </span>
                    </td>

                    <td class="actions">
                        <a href="edit_task.php?id=<?php echo safeText($row["task_id"]); ?>" class="btn btn-edit">
                            Edit Task
                        </a>

                        <a href="edit_committee_recommendation.php?id=<?php echo safeText($row["recommendation_id"]); ?>" class="btn btn-purple">
                            Edit Rec.
                        </a>

                        <a href="committee_meeting_profile.php?id=<?php echo safeText($row["meeting_id"]); ?>" class="btn">
                            Meeting
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="7">No committee-related tasks found.</td>
            </tr>
        <?php endif; ?>
    </table>

    <div class="pagination">
        <?php if ($currentPage > 1): ?>
            <a href="<?php echo safeText(buildPaginationUrl(1)); ?>">First</a>
            <a href="<?php echo safeText(buildPaginationUrl($currentPage - 1)); ?>">&laquo; Previous</a>
        <?php else: ?>
            <span class="disabled-page">First</span>
            <span class="disabled-page">&laquo; Previous</span>
        <?php endif; ?>

        <?php
        $startPage = max(1, $currentPage - 2);
        $endPage = min($totalPages, $currentPage + 2);
        ?>

        <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
            <?php if ($pageNumber === $currentPage): ?>
                <span class="active-page"><?php echo safeText($pageNumber); ?></span>
            <?php else: ?>
                <a href="<?php echo safeText(buildPaginationUrl($pageNumber)); ?>">
                    <?php echo safeText($pageNumber); ?>
                </a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($currentPage < $totalPages): ?>
            <a href="<?php echo safeText(buildPaginationUrl($currentPage + 1)); ?>">Next &raquo;</a>
            <a href="<?php echo safeText(buildPaginationUrl($totalPages)); ?>">Last</a>
        <?php else: ?>
            <span class="disabled-page">Next &raquo;</span>
            <span class="disabled-page">Last</span>
        <?php endif; ?>
    </div>

</div>

</body>
</html>