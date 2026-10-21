<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// Helpers
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

function getStatusClass($status) {
    if ($status === "Completed" || $status === "Closed") {
        return "badge-active";
    }

    if ($status === "Open" || $status === "In Progress") {
        return "badge-warning";
    }

    if ($status === "Cancelled") {
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
    return "committee_recommendations_dashboard.php?" . http_build_query($queryParams);
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
// Filters
// ===============================
$filter_search = trim($_GET["search"] ?? "");
$filter_committee_id = trim($_GET["committee_id"] ?? "");
$filter_status = trim($_GET["status"] ?? "");
$filter_priority = trim($_GET["priority"] ?? "");
$filter_responsible_staff_id = trim($_GET["responsible_staff_id"] ?? "");
$filter_overdue = trim($_GET["overdue"] ?? "");
$filter_linked_task = trim($_GET["linked_task"] ?? "");

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
// Build SQL
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

$fromSql = "
    FROM committee_recommendations r
    LEFT JOIN committees c ON r.committee_id = c.id
    LEFT JOIN committee_meetings m ON r.meeting_id = m.id
    LEFT JOIN program_staff ps ON r.responsible_staff_id = ps.id
    $taskJoin
    $evidenceJoin
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

// ===============================
// Load recommendations
// ===============================
$sql = "
    SELECT
        r.id,
        r.meeting_id,
        r.committee_id,
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

        $taskSelect,
        $evidenceSelect

    $fromSql
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

    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Summary counts
// ===============================
$summaryStmt = $conn->query("
    SELECT
        COUNT(*) AS total_recommendations,
        SUM(CASE WHEN status = 'Open' THEN 1 ELSE 0 END) AS open_recommendations,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_recommendations,
        SUM(CASE WHEN status IN ('Completed', 'Closed') THEN 1 ELSE 0 END) AS completed_recommendations,
        SUM(CASE
            WHEN target_date IS NOT NULL
             AND target_date < CURDATE()
             AND status NOT IN ('Completed', 'Closed', 'Cancelled')
            THEN 1 ELSE 0
        END) AS overdue_recommendations,
        SUM(CASE WHEN priority = 'High' THEN 1 ELSE 0 END) AS high_priority_recommendations
    FROM committee_recommendations
");

$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$totalRecommendations = (int)($summary["total_recommendations"] ?? 0);
$openRecommendations = (int)($summary["open_recommendations"] ?? 0);
$inProgressRecommendations = (int)($summary["in_progress_recommendations"] ?? 0);
$completedRecommendations = (int)($summary["completed_recommendations"] ?? 0);
$overdueRecommendations = (int)($summary["overdue_recommendations"] ?? 0);
$highPriorityRecommendations = (int)($summary["high_priority_recommendations"] ?? 0);

// ===============================
// Filter options
// ===============================
$committeeRows = $conn->query("
    SELECT id, committee_name, academic_year
    FROM committees
    ORDER BY academic_year DESC, committee_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$staffRows = [];

if ($hasProgramStaff) {
    $staffRows = $conn->query("
        SELECT id, full_name
        FROM program_staff
        WHERE employment_status = 'Active'
        ORDER BY full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$statusOptions = ["Open", "In Progress", "Completed", "Closed", "Cancelled"];
$priorityOptions = ["High", "Medium", "Low"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Committee Recommendations Dashboard</title>
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
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
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
            width: 120px;
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
        <h1>Committee Recommendations Dashboard</h1>
        <p>Track all committee recommendations, responsible staff, due dates, linked tasks, and evidence.</p>
    </div>

    <div class="actions">
        <a href="committees.php" class="btn btn-secondary">Committees</a>
    <a href="tasks.php" class="btn">Tasks</a>
    <a href="program_staff.php" class="btn">Program Staff</a>
    <a href="export_committee_recommendations_csv.php?<?php echo safeText(http_build_query($_GET)); ?>" class="btn btn-purple">
        Export CSV
	</a>
  
  </div>


    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalRecommendations); ?></div>
            <div class="summary-label">Total</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($openRecommendations); ?></div>
            <div class="summary-label">Open</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($inProgressRecommendations); ?></div>
            <div class="summary-label">In Progress</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($completedRecommendations); ?></div>
            <div class="summary-label">Completed / Closed</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($overdueRecommendations); ?></div>
            <div class="summary-label">Overdue</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($highPriorityRecommendations); ?></div>
            <div class="summary-label">High Priority</div>
        </div>
    </div>

    <div class="filter-box">
        <h2>Filter Recommendations</h2>

        <form method="GET" action="committee_recommendations_dashboard.php">
            <div class="filter-grid">
                <div>
                    <label>Search</label>
                    <input type="text"
                           name="search"
                           value="<?php echo safeText($filter_search); ?>"
                           placeholder="Recommendation, meeting, committee">
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
                    <label>Status</label>
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
                    <label>Priority</label>
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
                    <label>Responsible Staff</label>
                    <select name="responsible_staff_id">
                        <option value="">All Staff</option>
                        <?php foreach ($staffRows as $staff): ?>
                            <option value="<?php echo safeText($staff["id"]); ?>"
                                <?php if ((string)$filter_responsible_staff_id === (string)$staff["id"]) echo "selected"; ?>>
                                <?php echo safeText($staff["full_name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Overdue</label>
                    <select name="overdue">
                        <option value="">All</option>
                        <option value="1" <?php if ($filter_overdue === "1") echo "selected"; ?>>Overdue Only</option>
                    </select>
                </div>

                <div>
                    <label>Task Link</label>
                    <select name="linked_task">
                        <option value="">All</option>
                        <option value="linked" <?php if ($filter_linked_task === "linked") echo "selected"; ?>>Linked to Task</option>
                        <option value="unlinked" <?php if ($filter_linked_task === "unlinked") echo "selected"; ?>>Not Linked to Task</option>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn">Apply Filters</button>
                <a href="committee_recommendations_dashboard.php" class="btn btn-secondary">Clear Filters</a>
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
            <th>Recommendation</th>
            <th>Committee / Meeting</th>
            <th>Responsible / Target</th>
            <th>Priority / Status</th>
            <th>Task Link</th>
            <th>Evidence Link</th>
            <th>Actions</th>
        </tr>

        <?php if (count($recommendations) > 0): ?>
            <?php foreach ($recommendations as $row): ?>
                <?php
                    $isOverdue = false;

                    if (!empty($row["target_date"]) &&
                        $row["target_date"] < date("Y-m-d") &&
                        !in_array($row["status"], ["Completed", "Closed", "Cancelled"], true)) {
                        $isOverdue = true;
                    }
                ?>
                <tr class="<?php echo $isOverdue ? 'overdue-row' : ''; ?>">
                    <td><?php echo safeText($row["id"]); ?></td>

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
                        <strong><?php echo safeText($row["committee_name"]); ?></strong>
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
                        <?php if (!empty($row["responsible_name"])): ?>
                            <?php echo safeText($row["responsible_name"]); ?>
                        <?php else: ?>
                            <span class="missing">Not assigned</span>
                        <?php endif; ?>

                        <br>

                        <span class="small-text">
                            Target: <?php echo safeText($row["target_date"]); ?>
                        </span>

                        <?php if ($isOverdue): ?>
                            <br>
                            <span class="badge badge-danger">Overdue</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <span class="badge <?php echo safeText(getPriorityClass($row["priority"])); ?>">
                            <?php echo safeText($row["priority"]); ?>
                        </span>

                        <br><br>

                        <span class="badge <?php echo safeText(getStatusClass($row["status"])); ?>">
                            <?php echo safeText($row["status"]); ?>
                        </span>
                    </td>

                    <td>
                        <?php if (!empty($row["linked_task_id"])): ?>
                            <a href="edit_task.php?id=<?php echo safeText($row["linked_task_id"]); ?>">
                                Task #<?php echo safeText($row["linked_task_id"]); ?>
                            </a>

                            <?php if (!empty($row["task_title"])): ?>
                                <br>
                                <span class="small-text">
                                    <?php echo safeText($row["task_title"]); ?>
                                    |
                                    <?php echo safeText($row["task_status"]); ?>
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="missing">No task</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($row["linked_evidence_id"])): ?>
                            Evidence #<?php echo safeText($row["linked_evidence_id"]); ?>

                            <?php if (!empty($row["evidence_title"])): ?>
                                <br>
                                <span class="small-text">
                                    <?php echo safeText($row["evidence_title"]); ?>
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="missing">No evidence</span>
                        <?php endif; ?>
                    </td>

                    <td class="actions">
                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
			<a href="committee_tasks_dashboard.php" class="btn btn-purple">Committee Tasks</a>
                            <a href="edit_committee_recommendation.php?id=<?php echo safeText($row["id"]); ?>" class="btn btn-edit">
                                Edit
                            </a>

                            <?php if (!empty($row["linked_task_id"])): ?>
                                <a href="edit_task.php?id=<?php echo safeText($row["linked_task_id"]); ?>" class="btn">
                                    View Task
                                </a>
                            <?php else: ?>
                                <a href="committee_recommendation_followup_task.php?id=<?php echo safeText($row["id"]); ?>" class="btn btn-purple">
                                    Create Task
                                </a>
                            <?php endif; ?>

                            <?php echo deleteForm('delete_committee_recommendation.php?id=' . urlencode((string)$row['id']), 'Delete this recommendation? This cannot be undone.'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="8">No committee recommendations found.</td>
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
