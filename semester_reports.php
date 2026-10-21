<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// Read filters
// ===============================
$filter_academic_year = trim($_GET["academic_year"] ?? "");
$filter_semester = $_GET["semester"] ?? "";
$filter_status = $_GET["status"] ?? "";
$filter_prepared_by = trim($_GET["prepared_by"] ?? "");
$filter_search = trim($_GET["search"] ?? "");

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
// Build WHERE filters
// ===============================
$whereSql = " WHERE 1 = 1 ";
$params = [];

if ($filter_academic_year !== "") {
    $whereSql .= " AND academic_year LIKE :academic_year";
    $params[":academic_year"] = "%" . $filter_academic_year . "%";
}

if ($filter_semester !== "") {
    $whereSql .= " AND semester = :semester";
    $params[":semester"] = $filter_semester;
}

if ($filter_status !== "") {
    $whereSql .= " AND status = :status";
    $params[":status"] = $filter_status;
}

if ($filter_prepared_by !== "") {
    $whereSql .= " AND prepared_by LIKE :prepared_by";
    $params[":prepared_by"] = "%" . $filter_prepared_by . "%";
}

if ($filter_search !== "") {
    $whereSql .= " AND (
        report_title LIKE :search
        OR prepared_by LIKE :search
        OR program_strengths LIKE :search
        OR program_weaknesses LIKE :search
        OR improvement_actions LIKE :search
        OR kpi_summary LIKE :search
        OR course_report_summary LIKE :search
        OR student_feedback_summary LIKE :search
        OR teaching_learning_summary LIKE :search
        OR assessment_summary LIKE :search
        OR resources_summary LIKE :search
        OR general_notes LIKE :search
    )";
    $params[":search"] = "%" . $filter_search . "%";
}

// ===============================
// Count total filtered records
// ===============================
$countSql = "
    SELECT COUNT(*)
    FROM semester_reports
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
    FROM semester_reports
    $whereSql
    ORDER BY id DESC
    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Preserve filters in pagination links
// ===============================
$queryParams = $_GET;
unset($queryParams["page"]);

$baseQueryString = http_build_query($queryParams);
$basePageUrl = "semester_reports.php" . ($baseQueryString !== "" ? "?" . $baseQueryString . "&" : "?");
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Semester Program Reports</title>
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

        .result-count {
            margin-bottom: 12px;
            font-weight: bold;
            color: #333;
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

        @media (max-width: 1000px) {
            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
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
        <h1>Semester Program Reports</h1>
        <p>Manage semester-level program reports, improvement actions, and review status.</p>
    </div>

    <div class="filter-box">
        <h2>Filter Semester Reports</h2>

        <form method="GET" action="semester_reports.php">

            <div class="filter-grid">

                <div>
                    <label>Search</label>
                    <input type="text" name="search"
                           value="<?php echo htmlspecialchars($filter_search); ?>"
                           placeholder="Search title, summaries, notes">
                </div>

                <div>
                    <label>Academic Year</label>
                    <input type="text" name="academic_year"
                           value="<?php echo htmlspecialchars($filter_academic_year); ?>"
                           placeholder="Example: 2025-2026">
                </div>

                <div>
                    <label>Semester</label>
                    <select name="semester">
                        <option value="">All Semesters</option>

                        <?php
                        $semesters = ["First Semester", "Second Semester", "Third Semester", "Annual"];
                        foreach ($semesters as $semester):
                        ?>
                            <option value="<?php echo htmlspecialchars($semester); ?>"
                                <?php if ($filter_semester === $semester) echo "selected"; ?>>
                                <?php echo htmlspecialchars($semester); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>

                        <?php
                        $statuses = ["Draft", "Under Review", "Approved", "Archived"];
                        foreach ($statuses as $status):
                        ?>
                            <option value="<?php echo htmlspecialchars($status); ?>"
                                <?php if ($filter_status === $status) echo "selected"; ?>>
                                <?php echo htmlspecialchars($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Prepared By</label>
                    <input type="text" name="prepared_by"
                           value="<?php echo htmlspecialchars($filter_prepared_by); ?>"
                           placeholder="Example: Quality Coordinator">
                </div>

            </div>

            <div class="filter-actions">
                <button type="submit" class="btn">Apply Filters</button>
                <a href="semester_reports.php" class="btn btn-secondary">Clear Filters</a>

                <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                    <a href="create_semester_report.php" class="btn">Add Semester Report</a>
                <?php endif; ?>

                <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"])): ?>
                    <a href="export_semester_reports_csv.php?<?php echo htmlspecialchars(http_build_query($_GET)); ?>"
                       class="btn btn-purple">
                        Export Semester Reports CSV
                    </a>
                <?php endif; ?>
            </div>

        </form>
    </div>

    <div class="result-count">
        Results found: <?php echo htmlspecialchars($totalRecords); ?>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>Academic Year</th>
            <th>Semester</th>
            <th>Report Title</th>
            <th>Prepared By</th>
            <th>Status</th>
            <th>Created At</th>
            <th>Updated At</th>
            <th>Actions</th>
        </tr>

        <?php if (count($reports) > 0): ?>

            <?php foreach ($reports as $report): ?>
                <tr>
                    <td><?php echo htmlspecialchars($report["id"]); ?></td>

                    <td><?php echo htmlspecialchars($report["academic_year"]); ?></td>

                    <td><?php echo htmlspecialchars($report["semester"]); ?></td>

                    <td>
                        <strong><?php echo htmlspecialchars($report["report_title"]); ?></strong>
                    </td>

                    <td><?php echo htmlspecialchars($report["prepared_by"] ?? ""); ?></td>

                    <td class="status"><?php echo htmlspecialchars($report["status"]); ?></td>

                    <td>
                        <span class="small-text">
                            <?php echo htmlspecialchars($report["created_at"]); ?>
                        </span>
                    </td>

                    <td>
                        <span class="small-text">
                            <?php echo htmlspecialchars($report["updated_at"]); ?>
                        </span>
                    </td>

                    <td class="actions">

                        <a href="view_semester_report.php?id=<?php echo htmlspecialchars($report["id"]); ?>" class="btn btn-purple">
                            View
                        </a>

                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                            <a href="link_semester_report_items.php?id=<?php echo htmlspecialchars($report["id"]); ?>" class="btn">
                                Link Items
                            </a>
                        <?php endif; ?>

                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                            <a href="edit_semester_report.php?id=<?php echo htmlspecialchars($report["id"]); ?>" class="btn btn-edit">
                                Edit
                            </a>
                        <?php endif; ?>

                        <?php if (hasRole(["Admin", "Quality Coordinator"])): ?>
                            <?php echo deleteForm('delete_semester_report.php?id=' . urlencode((string)$report['id']), 'Are you sure you want to delete this semester report?'); ?>
                        <?php endif; ?>

                    </td>
                </tr>
            <?php endforeach; ?>

        <?php else: ?>

            <tr>
                <td colspan="9">No semester reports found for the selected filters.</td>
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

</body>
</html>
