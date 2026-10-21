<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty"]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: /tasks' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 302);
    exit;
}

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
    if ($priority === "High") return "priority-high";
    if ($priority === "Medium") return "priority-medium";
    if ($priority === "Low") return "priority-low";
    return "";
}

function getStatusClass($status) {
    if ($status === "Completed") return "completed";
    if ($status === "Pending" || $status === "In Progress") return "pending";
    if ($status === "Cancelled") return "cancelled";
    return "";
}

function buildPaginationUrl($pageNumber) {
    $queryParams = $_GET;
    $queryParams["page"] = $pageNumber;
    return "tasks.php?" . http_build_query($queryParams);
}

// ===============================
// Read filters
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
// Pagination
// ===============================
$recordsPerPage = 10;
$currentPage = isset($_GET["page"]) && is_numeric($_GET["page"]) ? (int)$_GET["page"] : 1;

if ($currentPage < 1) {
    $currentPage = 1;
}

$offset = ($currentPage - 1) * $recordsPerPage;

// ===============================
// Load substandards
// ===============================
$substandards = [];

if (tableExists($conn, "substandards") && tableExists($conn, "standards")) {
    $substandardStmt = $conn->query("
        SELECT 
            ss.id,
            ss.substandard_code,
            ss.substandard_text,
            s.standard_code
        FROM substandards ss
        INNER JOIN standards s ON ss.standard_id = s.id
        ORDER BY s.id, ss.substandard_code
    ");
    $substandards = $substandardStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Load evidence items
// ===============================
$evidenceItems = [];

if (tableExists($conn, "evidence_items")) {
    $evidenceStmt = $conn->query("
        SELECT id, title
        FROM evidence_items
        ORDER BY id DESC
    ");
    $evidenceItems = $evidenceStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Related columns
// ===============================
$hasRelatedModule = columnExists($conn, "tasks", "related_module");
$hasRelatedRecordId = columnExists($conn, "tasks", "related_record_id");

// ===============================
// Build WHERE
// ===============================
$whereSql = " WHERE 1 = 1 ";
$params = [];

if ($filter_status !== "") {
    $whereSql .= " AND t.status = :status";
    $params[":status"] = $filter_status;
}

if ($filter_priority !== "") {
    $whereSql .= " AND t.priority = :priority";
    $params[":priority"] = $filter_priority;
}

if ($filter_responsible_person !== "") {
    $whereSql .= " AND t.responsible_person LIKE :responsible_person";
    $params[":responsible_person"] = "%" . $filter_responsible_person . "%";
}

if ($filter_substandard_id !== "") {
    $whereSql .= " AND t.substandard_id = :substandard_id";
    $params[":substandard_id"] = $filter_substandard_id;
}

if ($filter_evidence_id !== "") {
    $whereSql .= " AND t.evidence_id = :evidence_id";
    $params[":evidence_id"] = $filter_evidence_id;
}

if ($hasRelatedModule && $filter_related_module !== "") {
    $whereSql .= " AND t.related_module = :related_module";
    $params[":related_module"] = $filter_related_module;
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
        OR t.status LIKE :search
        OR t.priority LIKE :search
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
// FROM
// ===============================
$fromSql = "
    FROM tasks t
    LEFT JOIN evidence_items e ON t.evidence_id = e.id
    LEFT JOIN substandards ss ON t.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
";

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
$totalResults = (int)$countStmt->fetchColumn();

$totalPages = (int)ceil($totalResults / $recordsPerPage);

if ($totalPages < 1) {
    $totalPages = 1;
}

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
    $offset = ($currentPage - 1) * $recordsPerPage;
}

$recordsPerPage = (int)$recordsPerPage;
$offset = (int)$offset;

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

        ss.id AS substandard_id,
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
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Quality Tasks</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .filter-box {
            background: white;
            padding: 15px;
            border: 1px solid #ccc;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .filter-actions {
            margin-top: 15px;
        }

        .checkbox-row {
            margin-top: 26px;
            font-weight: bold;
        }

        .checkbox-row input {
            width: auto;
            margin-right: 6px;
        }

        .result-count {
            margin-bottom: 12px;
            font-weight: bold;
            color: #333;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            background-color: #e9ecef;
        }

        .related-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            background: #e7d8ff;
            color: #4b148c;
            margin-bottom: 5px;
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

        .overdue {
            color: #dc3545;
            font-weight: bold;
        }

        .completed {
            color: #28a745;
            font-weight: bold;
        }

        .pending {
            color: #fd7e14;
            font-weight: bold;
        }

        .cancelled {
            color: #6c757d;
            font-weight: bold;
        }

        .actions a {
            display: block;
            width: 115px;
            text-align: center;
            margin-bottom: 5px;
        }

        .missing {
            color: #999;
            font-style: italic;
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

        .page-summary {
            margin-top: 10px;
            margin-bottom: 10px;
            color: #555;
            font-size: 14px;
        }

        @media (max-width: 900px) {
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
        <h1>Quality Tasks</h1>
        <p>Manage accreditation and quality improvement tasks, including Course Report follow-up actions.</p>
    </div>

    <div class="filter-box">
        <h2>Filter Tasks</h2>

        <form method="GET" action="tasks.php">

            <div class="filter-grid">

                <div>
                    <label>Search</label>
                    <input type="text" name="search"
                           value="<?php echo safeText($filter_search); ?>"
                           placeholder="Search task, notes, evidence, standard, related report">
                </div>

                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach (["Pending", "In Progress", "Completed", "Cancelled"] as $status): ?>
                            <option value="<?php echo safeText($status); ?>" <?php if ($filter_status === $status) echo "selected"; ?>>
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
                            <option value="<?php echo safeText($priority); ?>" <?php if ($filter_priority === $priority) echo "selected"; ?>>
                                <?php echo safeText($priority); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Responsible Person</label>
                    <input type="text" name="responsible_person"
                           value="<?php echo safeText($filter_responsible_person); ?>"
                           placeholder="Example: Quality Coordinator">
                </div>

                <div>
                    <label>Linked Substandard</label>
                    <select name="substandard_id">
                        <option value="">All Substandards</option>
                        <?php foreach ($substandards as $sub): ?>
                            <option value="<?php echo safeText($sub["id"]); ?>"
                                <?php if ((string)$filter_substandard_id === (string)$sub["id"]) echo "selected"; ?>>
                                <?php echo safeText($sub["standard_code"] . " - " . $sub["substandard_code"] . " | " . $sub["substandard_text"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Linked Evidence</label>
                    <select name="evidence_id">
                        <option value="">All Evidence Items</option>
                        <?php foreach ($evidenceItems as $evidence): ?>
                            <option value="<?php echo safeText($evidence["id"]); ?>"
                                <?php if ((string)$filter_evidence_id === (string)$evidence["id"]) echo "selected"; ?>>
                                <?php echo safeText($evidence["id"] . " - " . $evidence["title"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($hasRelatedModule): ?>
                    <div>
                        <label>Related Module</label>
                        <select name="related_module">
                            <option value="">All Related Modules</option>
                            <option value="Course Reports" <?php if ($filter_related_module === "Course Reports") echo "selected"; ?>>
                                Course Reports
                            </option>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="checkbox-row">
                    <label>
                        <input type="checkbox" name="overdue" value="1"
                            <?php if ($filter_overdue === "1") echo "checked"; ?>>
                        Overdue tasks only
                    </label>
                </div>

            </div>

            <div class="filter-actions">
                <button type="submit" class="btn">Apply Filters</button>
                <a href="tasks.php" class="btn btn-secondary">Clear Filters</a>

                <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                    <a href="create_task.php" class="btn">Add New Task</a>
                <?php endif; ?>

                <a href="task_dashboard.php" class="btn btn-purple">Task Dashboard</a>

<a href="export_task_dashboard_csv.php" class="btn btn-purple">
    Export Dashboard CSV
</a>

<a href="task_status_logs.php" class="btn">Status Logs</a>

<a href="course_report_followup_actions.php" class="btn btn-purple">
    Course Report Follow-up
</a>

                <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                    <a href="export_tasks_csv.php?<?php echo safeText(http_build_query($_GET)); ?>" class="btn btn-purple">
                        Export Tasks CSV
                    </a>
                <?php endif; ?>
            </div>

        </form>
    </div>

    <div class="result-count">
        Results found: <?php echo safeText($totalResults); ?>
    </div>

    <div class="page-summary">
        Page <?php echo safeText($currentPage); ?> of <?php echo safeText($totalPages); ?>
        | <?php echo safeText($recordsPerPage); ?> records per page
        | Total records: <?php echo safeText($totalResults); ?>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>Task</th>
            <th>Linked Standard/Substandard</th>
            <th>Linked Evidence</th>
            <th>Related Record</th>
            <th>Responsible Person</th>
            <th>Due Date</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Notes</th>
            <th>Actions</th>
        </tr>

        <?php if (count($tasks) > 0): ?>

            <?php foreach ($tasks as $task): ?>
                <tr>
                    <td><?php echo safeText($task["id"]); ?></td>

                    <td>
                        <strong><?php echo safeText($task["task_title"]); ?></strong>

                        <?php if (!empty($task["task_description"])): ?>
                            <br>
                            <span class="small-text">
                                <?php echo nl2br(safeText($task["task_description"])); ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($task["substandard_code"])): ?>
                            <strong><?php echo safeText($task["standard_code"]); ?></strong><br>
                            <span class="badge"><?php echo safeText($task["substandard_code"]); ?></span>
                            <br>
                            <span class="small-text"><?php echo safeText($task["substandard_text"]); ?></span>
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
                            <span class="related-badge">
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
                        <span class="<?php echo safeText(getPriorityClass($task["priority"] ?? "")); ?>">
                            <?php echo safeText($task["priority"] ?? ""); ?>
                        </span>
                    </td>

                    <td>
                        <span class="<?php echo safeText(getStatusClass($task["status"] ?? "")); ?>">
                            <?php echo safeText($task["status"] ?? ""); ?>
                        </span>
                    </td>

                    <td><?php echo nl2br(safeText($task["notes"] ?? "")); ?></td>

                    <td class="actions">

                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty"])): ?>
                            <a href="edit_task.php?id=<?php echo safeText($task["id"]); ?>" class="btn btn-edit">
                                Edit
                            </a>

                            <a href="task_status_logs.php?task_id=<?php echo safeText($task["id"]); ?>" class="btn">
   				 Status Log (<?php echo safeText($task["status_log_count"] ?? 0); ?>)
		             </a>
                       
			 <?php endif; ?>

                        <?php if (hasRole(["Admin", "Quality Coordinator"])): ?>
                            <?php echo deleteForm('delete_task.php?id=' . urlencode((string)$task['id']), 'Are you sure you want to delete this task?'); ?>
                        <?php endif; ?>

                    </td>
                </tr>
            <?php endforeach; ?>

        <?php else: ?>
            <tr>
                <td colspan="11">No tasks found for the selected filters.</td>
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

        <?php if ($startPage > 1): ?>
            <a href="<?php echo safeText(buildPaginationUrl(1)); ?>">1</a>

            <?php if ($startPage > 2): ?>
                <span class="disabled-page">...</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
            <?php if ($pageNumber == $currentPage): ?>
                <span class="active-page"><?php echo safeText($pageNumber); ?></span>
            <?php else: ?>
                <a href="<?php echo safeText(buildPaginationUrl($pageNumber)); ?>">
                    <?php echo safeText($pageNumber); ?>
                </a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?>
                <span class="disabled-page">...</span>
            <?php endif; ?>

            <a href="<?php echo safeText(buildPaginationUrl($totalPages)); ?>">
                <?php echo safeText($totalPages); ?>
            </a>
        <?php endif; ?>

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
