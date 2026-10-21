<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

// ===============================
// Read filters
// ===============================
$filter_user = trim($_GET["user"] ?? "");
$filter_action = trim($_GET["action_type"] ?? "");
$filter_module = trim($_GET["module_name"] ?? "");
$filter_search = trim($_GET["search"] ?? "");

// ===============================
// Pagination settings
// ===============================
$recordsPerPage = 20;

$page = isset($_GET["page"]) && is_numeric($_GET["page"]) ? (int)$_GET["page"] : 1;

if ($page < 1) {
    $page = 1;
}

$offset = ($page - 1) * $recordsPerPage;

// ===============================
// Build WHERE filters
// ===============================
$whereSql = " WHERE 1 = 1 ";
$params = [];

if ($filter_user !== "") {
    $whereSql .= " AND user_name LIKE :user";
    $params[":user"] = "%" . $filter_user . "%";
}

if ($filter_action !== "") {
    $whereSql .= " AND action_type = :action_type";
    $params[":action_type"] = $filter_action;
}

if ($filter_module !== "") {
    $whereSql .= " AND module_name = :module_name";
    $params[":module_name"] = $filter_module;
}

if ($filter_search !== "") {
    $whereSql .= " AND (
        description LIKE :search
        OR user_name LIKE :search
        OR user_role LIKE :search
        OR action_type LIKE :search
        OR module_name LIKE :search
    )";
    $params[":search"] = "%" . $filter_search . "%";
}

// ===============================
// Count total filtered records
// ===============================
$countSql = "
    SELECT COUNT(*)
    FROM activity_logs
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

// Safety: force integer values before inserting into LIMIT/OFFSET
$recordsPerPage = (int)$recordsPerPage;
$offset = (int)$offset;

// ===============================
// Main paginated query
// ===============================
$sql = "
    SELECT *
    FROM activity_logs
    $whereSql
    ORDER BY id DESC
    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Load distinct action types
// ===============================
$actionStmt = $conn->query("
    SELECT DISTINCT action_type
    FROM activity_logs
    WHERE action_type IS NOT NULL AND action_type <> ''
    ORDER BY action_type
");
$actionTypes = $actionStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Load distinct modules
// ===============================
$moduleStmt = $conn->query("
    SELECT DISTINCT module_name
    FROM activity_logs
    WHERE module_name IS NOT NULL AND module_name <> ''
    ORDER BY module_name
");
$modules = $moduleStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Preserve filters in pagination links
// ===============================
$queryParams = $_GET;
unset($queryParams["page"]);

$baseQueryString = http_build_query($queryParams);
$basePageUrl = "activity_logs.php" . ($baseQueryString !== "" ? "?" . $baseQueryString . "&" : "?");
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Activity Logs</title>
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

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 12px;
            align-items: end;
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
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            background: #e9ecef;
            color: #495057;
            white-space: nowrap;
        }

        .badge-create {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-update {
            background: #cff4fc;
            color: #055160;
        }

        .badge-delete {
            background: #f8d7da;
            color: #842029;
        }

        .badge-login {
            background: #fff3cd;
            color: #664d03;
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

        .pagination a:hover {
            background: #f0f0f0;
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

        @media (max-width: 1000px) {
            .filter-grid {
                grid-template-columns: repeat(2, minmax(180px, 1fr));
            }
        }

        @media (max-width: 700px) {
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
        <h1>Activity Logs</h1>
        <p>Review system actions, user activity, module changes, and audit records.</p>
    </div>

    <div class="panel">
        <h2>Filter Activity Logs</h2>

        <form method="GET" action="activity_logs.php">
            <div class="filter-grid">

                <div>
                    <label>Search</label>
                    <input type="text" name="search"
                           value="<?php echo htmlspecialchars($filter_search); ?>"
                           placeholder="Description, user, module">
                </div>

                <div>
                    <label>User</label>
                    <input type="text" name="user"
                           value="<?php echo htmlspecialchars($filter_user); ?>"
                           placeholder="Example: Fowzi">
                </div>

                <div>
                    <label>Action Type</label>
                    <select name="action_type">
                        <option value="">All Actions</option>

                        <?php foreach ($actionTypes as $action): ?>
                            <option value="<?php echo htmlspecialchars($action["action_type"]); ?>"
                                <?php if ($filter_action === $action["action_type"]) echo "selected"; ?>>
                                <?php echo htmlspecialchars($action["action_type"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Module</label>
                    <select name="module_name">
                        <option value="">All Modules</option>

                        <?php foreach ($modules as $module): ?>
                            <option value="<?php echo htmlspecialchars($module["module_name"]); ?>"
                                <?php if ($filter_module === $module["module_name"]) echo "selected"; ?>>
                                <?php echo htmlspecialchars($module["module_name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>

            <br>

            <button type="submit" class="btn">Apply Filters</button>
            <a href="activity_logs.php" class="btn btn-secondary">Clear Filters</a>
        </form>
    </div>

    <div class="panel">
        <div class="result-count">
            Results found: <?php echo htmlspecialchars($totalRecords); ?>
        </div>

        <table>
            <tr>
                <th>ID</th>
                <th>Date/Time</th>
                <th>User</th>
                <th>Role</th>
                <th>Action</th>
                <th>Module</th>
                <th>Record ID</th>
                <th>Description</th>
                <th>IP Address</th>
            </tr>

            <?php if (count($logs) > 0): ?>

                <?php foreach ($logs as $log): ?>
                    <?php
                        $badgeClass = "badge";

                        if (stripos($log["action_type"], "create") !== false || stripos($log["action_type"], "add") !== false) {
                            $badgeClass .= " badge-create";
                        } elseif (stripos($log["action_type"], "update") !== false || stripos($log["action_type"], "edit") !== false) {
                            $badgeClass .= " badge-update";
                        } elseif (stripos($log["action_type"], "delete") !== false || stripos($log["action_type"], "unlink") !== false) {
                            $badgeClass .= " badge-delete";
                        } elseif (stripos($log["action_type"], "login") !== false) {
                            $badgeClass .= " badge-login";
                        }
                    ?>

                    <tr>
                        <td><?php echo htmlspecialchars($log["id"]); ?></td>

                        <td>
                            <?php echo htmlspecialchars($log["created_at"]); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($log["user_name"] ?? ""); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($log["user_role"] ?? ""); ?>
                        </td>

                        <td>
                            <span class="<?php echo htmlspecialchars($badgeClass); ?>">
                                <?php echo htmlspecialchars($log["action_type"]); ?>
                            </span>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($log["module_name"]); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($log["record_id"] ?? ""); ?>
                        </td>

                        <td>
                            <?php echo nl2br(htmlspecialchars($log["description"] ?? "")); ?>
                        </td>

                        <td>
                            <span class="small-text">
                                <?php echo htmlspecialchars($log["ip_address"] ?? ""); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="9">No activity logs found.</td>
                </tr>

            <?php endif; ?>

        </table>

        <div class="pagination">
            <div class="pagination-info">
                Page <?php echo htmlspecialchars($page); ?> of <?php echo htmlspecialchars($totalPages); ?>
                |
                <?php echo htmlspecialchars($recordsPerPage); ?> records per page
                |
                Total records: <?php echo htmlspecialchars($totalRecords); ?>
            </div>

            <?php if ($page > 1): ?>
                <a href="<?php echo htmlspecialchars($basePageUrl); ?>page=1">First</a>
                <a href="<?php echo htmlspecialchars($basePageUrl); ?>page=<?php echo htmlspecialchars($page - 1); ?>">Previous</a>
            <?php else: ?>
                <span class="disabled">First</span>
                <span class="disabled">Previous</span>
            <?php endif; ?>

            <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
            ?>

            <?php if ($startPage > 1): ?>
                <a href="<?php echo htmlspecialchars($basePageUrl); ?>page=1">1</a>

                <?php if ($startPage > 2): ?>
                    <span class="disabled">...</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current-page"><?php echo htmlspecialchars($i); ?></span>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars($basePageUrl); ?>page=<?php echo htmlspecialchars($i); ?>">
                        <?php echo htmlspecialchars($i); ?>
                    </a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                    <span class="disabled">...</span>
                <?php endif; ?>

                <a href="<?php echo htmlspecialchars($basePageUrl); ?>page=<?php echo htmlspecialchars($totalPages); ?>">
                    <?php echo htmlspecialchars($totalPages); ?>
                </a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?php echo htmlspecialchars($basePageUrl); ?>page=<?php echo htmlspecialchars($page + 1); ?>">Next</a>
                <a href="<?php echo htmlspecialchars($basePageUrl); ?>page=<?php echo htmlspecialchars($totalPages); ?>">Last</a>
            <?php else: ?>
                <span class="disabled">Next</span>
                <span class="disabled">Last</span>
            <?php endif; ?>
        </div>
    </div>

</div>

</body>
</html>