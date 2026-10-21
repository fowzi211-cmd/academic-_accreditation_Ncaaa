<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// Read optional KPI filter
// ===============================
$filter_kpi_id = $_GET["kpi_id"] ?? "";

// ===============================
// Pagination settings
// ===============================
$recordsPerPage = 10;

$currentPage = isset($_GET["page"]) ? (int)$_GET["page"] : 1;

if ($currentPage < 1) {
    $currentPage = 1;
}

$offset = ($currentPage - 1) * $recordsPerPage;

// ===============================
// Load KPIs for filter
// ===============================
$kpiStmt = $conn->query("
    SELECT id, kpi_code, kpi_name
    FROM kpis
    ORDER BY kpi_code
");
$kpis = $kpiStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Build WHERE clause
// ===============================
$whereSql = " WHERE 1 = 1 ";
$params = [];

if ($filter_kpi_id !== "") {
    $whereSql .= " AND kr.kpi_id = :kpi_id";
    $params[":kpi_id"] = $filter_kpi_id;
}

// ===============================
// Shared FROM/JOIN section
// ===============================
$fromSql = "
    FROM kpi_results kr
    INNER JOIN kpis k ON kr.kpi_id = k.id
    LEFT JOIN semester_reports sr ON kr.linked_semester_report_id = sr.id
";

// ===============================
// Count total filtered records
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

// Safety: force integer values before inserting into LIMIT/OFFSET
$recordsPerPage = (int)$recordsPerPage;
$offset = (int)$offset;

// ===============================
// Load paginated KPI results
// ===============================
$sql = "
    SELECT 
        kr.id,
        kr.academic_year,
        kr.semester,
        kr.target_value,
        kr.actual_value,
        kr.achievement_percentage,
        kr.analysis,
        kr.improvement_action,
        kr.status,
        kr.created_at,
        kr.updated_at,

        k.kpi_code,
        k.kpi_name,
        k.unit,
        k.responsible_person,

        sr.report_title AS semester_report_title

    $fromSql
    $whereSql

    ORDER BY kr.id DESC

    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Build pagination URL
// ===============================
function buildPaginationUrl($pageNumber) {
    $queryParams = $_GET;
    $queryParams["page"] = $pageNumber;

    return "kpi_results.php?" . http_build_query($queryParams);
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>KPI Results</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .filter-box {
            background: white;
            padding: 15px;
            border: 1px solid #ccc;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        label {
            font-weight: bold;
            display: block;
            margin-bottom: 4px;
        }

        select {
            width: 500px;
            max-width: 100%;
            padding: 7px;
            box-sizing: border-box;
        }

        .filter-actions {
            margin-top: 15px;
        }

        .result-count {
            margin-bottom: 12px;
            font-weight: bold;
            color: #333;
        }

        .status {
            font-weight: bold;
        }

        .good {
            color: #28a745;
            font-weight: bold;
        }

        .warning {
            color: #fd7e14;
            font-weight: bold;
        }

        .bad {
            color: #dc3545;
            font-weight: bold;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .actions a {
            display: inline-block;
            margin-bottom: 4px;
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

        .pagination a:hover {
            background: #f0f0f0;
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
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>KPI Results</h1>
        <p>View, filter, export, and manage KPI achievement results.</p>
    </div>

    <div class="filter-box">
        <h2>Filter KPI Results</h2>

        <form method="GET" action="kpi_results.php">

            <label>KPI</label>
            <select name="kpi_id">
                <option value="">All KPIs</option>

                <?php foreach ($kpis as $kpi): ?>
                    <option value="<?php echo htmlspecialchars($kpi["id"]); ?>"
                        <?php if ((string)$filter_kpi_id === (string)$kpi["id"]) echo "selected"; ?>>
                        <?php echo htmlspecialchars($kpi["kpi_code"] . " - " . $kpi["kpi_name"]); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="filter-actions">
                <button type="submit" class="btn">Apply Filter</button>
                <a href="kpi_results.php" class="btn btn-secondary">Clear Filter</a>

                <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                    <a href="create_kpi_result.php<?php echo $filter_kpi_id !== "" ? '?kpi_id=' . htmlspecialchars($filter_kpi_id) : ''; ?>" class="btn">
                        Add KPI Result
                    </a>
                <?php endif; ?>

                <a href="export_kpi_results_csv.php?<?php echo htmlspecialchars(http_build_query($_GET)); ?>" 
                   class="btn btn-purple">
                    Export KPI Results CSV
                </a>
            </div>

        </form>
    </div>

    <div class="result-count">
        Results found: <?php echo htmlspecialchars($totalResults); ?>
    </div>

    <div class="page-summary">
        Page <?php echo htmlspecialchars($currentPage); ?> of <?php echo htmlspecialchars($totalPages); ?>
        | <?php echo htmlspecialchars($recordsPerPage); ?> records per page
        | Total records: <?php echo htmlspecialchars($totalResults); ?>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>KPI</th>
            <th>Academic Period</th>
            <th>Target</th>
            <th>Actual</th>
            <th>Achievement %</th>
            <th>Analysis</th>
            <th>Improvement Action</th>
            <th>Linked Semester Report</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>

        <?php if (count($results) > 0): ?>

            <?php foreach ($results as $row): ?>
                <?php
                    $achievement = (float)$row["achievement_percentage"];

                    $achievementClass = "bad";
                    if ($achievement >= 100) {
                        $achievementClass = "good";
                    } elseif ($achievement >= 80) {
                        $achievementClass = "warning";
                    }
                ?>

                <tr>
                    <td><?php echo htmlspecialchars($row["id"]); ?></td>

                    <td>
                        <strong><?php echo htmlspecialchars($row["kpi_code"]); ?></strong><br>
                        <?php echo htmlspecialchars($row["kpi_name"]); ?><br>
                        <span class="small-text">
                            Responsible:
                            <?php echo htmlspecialchars($row["responsible_person"] ?? ""); ?>
                        </span>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($row["academic_year"]); ?><br>
                        <?php echo htmlspecialchars($row["semester"]); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($row["target_value"]); ?>
                        <?php echo htmlspecialchars($row["unit"] ?? ""); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($row["actual_value"]); ?>
                        <?php echo htmlspecialchars($row["unit"] ?? ""); ?>
                    </td>

                    <td class="<?php echo htmlspecialchars($achievementClass); ?>">
                        <?php echo htmlspecialchars($row["achievement_percentage"]); ?>%
                    </td>

                    <td>
                        <?php echo nl2br(htmlspecialchars($row["analysis"] ?? "")); ?>
                    </td>

                    <td>
                        <?php echo nl2br(htmlspecialchars($row["improvement_action"] ?? "")); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($row["semester_report_title"] ?? "Not linked"); ?>
                    </td>

                    <td class="status">
                        <?php echo htmlspecialchars($row["status"]); ?>
                    </td>

                    <td class="actions">
                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                            <a href="edit_kpi_result.php?id=<?php echo htmlspecialchars($row["id"]); ?>" class="btn btn-edit">
                                Edit
                            </a>
                        <?php endif; ?>

                        <?php if (hasRole(["Admin", "Quality Coordinator"])): ?>
                            <?php echo deleteForm('delete_kpi_result.php?id=' . urlencode((string)$row['id']), 'Are you sure you want to delete this KPI result?'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

        <?php else: ?>

            <tr>
                <td colspan="11">No KPI results found.</td>
            </tr>

        <?php endif; ?>

    </table>

    <div class="pagination">

        <?php if ($currentPage > 1): ?>
            <a href="<?php echo htmlspecialchars(buildPaginationUrl(1)); ?>">First</a>

            <a href="<?php echo htmlspecialchars(buildPaginationUrl($currentPage - 1)); ?>">
                Previous
            </a>
        <?php else: ?>
            <span class="disabled-page">First</span>
            <span class="disabled-page">Previous</span>
        <?php endif; ?>

        <?php
        $startPage = max(1, $currentPage - 2);
        $endPage = min($totalPages, $currentPage + 2);
        ?>

        <?php if ($startPage > 1): ?>
            <a href="<?php echo htmlspecialchars(buildPaginationUrl(1)); ?>">1</a>

            <?php if ($startPage > 2): ?>
                <span class="disabled-page">...</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($page = $startPage; $page <= $endPage; $page++): ?>
            <?php if ($page == $currentPage): ?>
                <span class="active-page"><?php echo htmlspecialchars($page); ?></span>
            <?php else: ?>
                <a href="<?php echo htmlspecialchars(buildPaginationUrl($page)); ?>">
                    <?php echo htmlspecialchars($page); ?>
                </a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?>
                <span class="disabled-page">...</span>
            <?php endif; ?>

            <a href="<?php echo htmlspecialchars(buildPaginationUrl($totalPages)); ?>">
                <?php echo htmlspecialchars($totalPages); ?>
            </a>
        <?php endif; ?>

        <?php if ($currentPage < $totalPages): ?>
            <a href="<?php echo htmlspecialchars(buildPaginationUrl($currentPage + 1)); ?>">
                Next
            </a>

            <a href="<?php echo htmlspecialchars(buildPaginationUrl($totalPages)); ?>">
                Last
            </a>
        <?php else: ?>
            <span class="disabled-page">Next</span>
            <span class="disabled-page">Last</span>
        <?php endif; ?>

    </div>

</div>

</body>
</html>
