<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// Pagination settings
// ===============================
$recordsPerPage = 10;

$page = isset($_GET["page"]) && is_numeric($_GET["page"]) ? (int)$_GET["page"] : 1;

if ($page < 1) {
    $page = 1;
}

$offset = ($page - 1) * $recordsPerPage;

// ===============================
// Count total KPIs
// ===============================
$countStmt = $conn->query("
    SELECT COUNT(*)
    FROM kpis
");

$totalRecords = (int)$countStmt->fetchColumn();

$totalPages = (int)ceil($totalRecords / $recordsPerPage);

if ($totalPages < 1) {
    $totalPages = 1;
}

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $recordsPerPage;
}

// Safety
$recordsPerPage = (int)$recordsPerPage;
$offset = (int)$offset;

// ===============================
// Load paginated KPIs
// ===============================
$sql = "
    SELECT *
    FROM kpis
    ORDER BY id DESC
    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->query($sql);
$kpis = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Pagination URL
// ===============================
function buildPaginationUrl($pageNumber) {
    return "kpis.php?page=" . (int)$pageNumber;
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>KPI Management</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .top-bar {
            margin-bottom: 20px;
        }

        .status {
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
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>KPI Management</h1>
        <p>Manage program KPIs, targets, measurement methods, responsible persons, and KPI results.</p>
    </div>

    <div class="top-bar">

        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
            <a href="create_kpi.php" class="btn">Add KPI</a>
        <?php endif; ?>

        <a href="kpi_results.php" class="btn btn-purple">KPI Results</a>

        <a href="export_kpis_csv.php" class="btn btn-purple">
            Export KPIs CSV
        </a>

    </div>

    <div class="pagination-info">
        Page <?php echo htmlspecialchars($page); ?> of <?php echo htmlspecialchars($totalPages); ?>
        |
        <?php echo htmlspecialchars($recordsPerPage); ?> records per page
        |
        Total KPIs: <?php echo htmlspecialchars($totalRecords); ?>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>KPI Code</th>
            <th>KPI Name</th>
            <th>Description</th>
            <th>Measurement Method</th>
            <th>Target</th>
            <th>Unit</th>
            <th>Responsible Person</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>

        <?php if (count($kpis) > 0): ?>

            <?php foreach ($kpis as $kpi): ?>
                <tr>
                    <td><?php echo htmlspecialchars($kpi["id"]); ?></td>

                    <td>
                        <strong><?php echo htmlspecialchars($kpi["kpi_code"]); ?></strong>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($kpi["kpi_name"]); ?>
                    </td>

                    <td>
                        <?php echo nl2br(htmlspecialchars($kpi["kpi_description"] ?? "")); ?>
                    </td>

                    <td>
                        <?php echo nl2br(htmlspecialchars($kpi["measurement_method"] ?? "")); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($kpi["target_value"]); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($kpi["unit"] ?? ""); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($kpi["responsible_person"] ?? ""); ?>
                    </td>

                    <td class="status">
                        <?php echo htmlspecialchars($kpi["status"]); ?>
                    </td>

                    <td class="actions">
                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                            <a href="edit_kpi.php?id=<?php echo htmlspecialchars($kpi["id"]); ?>" class="btn btn-edit">
                                Edit
                            </a>
                        <?php endif; ?>

                        <?php if (hasRole(["Admin", "Quality Coordinator"])): ?>
                            <?php echo deleteForm('delete_kpi.php?id=' . urlencode((string)$kpi['id']), 'Are you sure you want to delete this KPI?'); ?>
                        <?php endif; ?>

                        <a href="kpi_results.php?kpi_id=<?php echo htmlspecialchars($kpi["id"]); ?>" class="btn btn-purple">
                            Results
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>

        <?php else: ?>

            <tr>
                <td colspan="10">No KPIs found.</td>
            </tr>

        <?php endif; ?>

    </table>

    <div class="pagination">
        <div class="pagination-info">
            Page <?php echo htmlspecialchars($page); ?> of <?php echo htmlspecialchars($totalPages); ?>
            |
            <?php echo htmlspecialchars($recordsPerPage); ?> records per page
            |
            Total KPIs: <?php echo htmlspecialchars($totalRecords); ?>
        </div>

        <?php if ($page > 1): ?>
            <a href="<?php echo htmlspecialchars(buildPaginationUrl(1)); ?>">First</a>
            <a href="<?php echo htmlspecialchars(buildPaginationUrl($page - 1)); ?>">Previous</a>
        <?php else: ?>
            <span class="disabled">First</span>
            <span class="disabled">Previous</span>
        <?php endif; ?>

        <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
        ?>

        <?php if ($startPage > 1): ?>
            <a href="<?php echo htmlspecialchars(buildPaginationUrl(1)); ?>">1</a>

            <?php if ($startPage > 2): ?>
                <span class="disabled">...</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="current-page"><?php echo htmlspecialchars($i); ?></span>
            <?php else: ?>
                <a href="<?php echo htmlspecialchars(buildPaginationUrl($i)); ?>">
                    <?php echo htmlspecialchars($i); ?>
                </a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?>
                <span class="disabled">...</span>
            <?php endif; ?>

            <a href="<?php echo htmlspecialchars(buildPaginationUrl($totalPages)); ?>">
                <?php echo htmlspecialchars($totalPages); ?>
            </a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
            <a href="<?php echo htmlspecialchars(buildPaginationUrl($page + 1)); ?>">Next</a>
            <a href="<?php echo htmlspecialchars(buildPaginationUrl($totalPages)); ?>">Last</a>
        <?php else: ?>
            <span class="disabled">Next</span>
            <span class="disabled">Last</span>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
