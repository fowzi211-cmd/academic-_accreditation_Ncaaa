<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

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

function buildPaginationUrl($pageNumber) {
    $queryParams = $_GET;
    $queryParams["page"] = $pageNumber;
    return "study_plans.php?" . http_build_query($queryParams);
}

function statusBadgeClass($status) {
    if ($status === "Approved") {
        return "badge-approved";
    }

    if ($status === "Draft") {
        return "badge-draft";
    }

    if ($status === "Under Review") {
        return "badge-review";
    }

    if ($status === "Archived") {
        return "badge-archived";
    }

    return "badge-muted";
}

// ===============================
// Required tables
// ===============================
if (!tableExists($conn, "study_plans")) {
    die("study_plans table does not exist. Run the study plan SQL first.");
}

if (!tableExists($conn, "study_plan_course_matrix")) {
    die("study_plan_course_matrix table does not exist. Run the study plan course matrix SQL first.");
}

// ===============================
// Filters
// ===============================
$filter_search = trim($_GET["search"] ?? "");
$filter_academic_year = trim($_GET["academic_year"] ?? "");
$filter_approval_status = trim($_GET["approval_status"] ?? "");
$filter_plan_version = trim($_GET["plan_version"] ?? "");

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
// Build WHERE
// ===============================
$whereSql = " WHERE 1 = 1 ";
$params = [];

if ($filter_search !== "") {
    $whereSql .= " AND (
        sp.plan_title LIKE :search
        OR sp.program_name LIKE :search
        OR sp.department_name LIKE :search
        OR sp.college_name LIKE :search
        OR sp.institution_name LIKE :search
        OR sp.plan_version LIKE :search
        OR sp.academic_year LIKE :search
        OR sp.notes LIKE :search
    )";
    $params[":search"] = "%" . $filter_search . "%";
}

if ($filter_academic_year !== "") {
    $whereSql .= " AND sp.academic_year = :academic_year";
    $params[":academic_year"] = $filter_academic_year;
}

if ($filter_approval_status !== "") {
    $whereSql .= " AND sp.approval_status = :approval_status";
    $params[":approval_status"] = $filter_approval_status;
}

if ($filter_plan_version !== "") {
    $whereSql .= " AND sp.plan_version = :plan_version";
    $params[":plan_version"] = $filter_plan_version;
}

// ===============================
// Count
// ===============================
$countSql = "
    SELECT COUNT(*)
    FROM study_plans sp
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

// ===============================
// Load study plans
// ===============================
$sql = "
    SELECT
        sp.id,
        sp.plan_title,
        sp.program_name,
        sp.department_name,
        sp.college_name,
        sp.institution_name,
        sp.plan_version,
        sp.academic_year,
        sp.total_credit_hours,
        sp.approval_status,
        sp.approval_date,
        sp.notes,
        sp.created_at,
        sp.updated_at,

        COUNT(spcm.id) AS course_count,
        COALESCE(SUM(spcm.credit_hours), 0) AS calculated_credit_hours,
        COUNT(DISTINCT spcm.semester_no) AS semester_count

    FROM study_plans sp
    LEFT JOIN study_plan_course_matrix spcm ON sp.id = spcm.study_plan_id

    $whereSql

    GROUP BY
        sp.id,
        sp.plan_title,
        sp.program_name,
        sp.department_name,
        sp.college_name,
        sp.institution_name,
        sp.plan_version,
        sp.academic_year,
        sp.total_credit_hours,
        sp.approval_status,
        sp.approval_date,
        sp.notes,
        sp.created_at,
        sp.updated_at

    ORDER BY
        CASE
            WHEN sp.approval_status = 'Approved' THEN 1
            WHEN sp.approval_status = 'Under Review' THEN 2
            WHEN sp.approval_status = 'Draft' THEN 3
            WHEN sp.approval_status = 'Archived' THEN 4
            ELSE 5
        END,
        sp.academic_year DESC,
        sp.id DESC

    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$studyPlans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Summary counts
// ===============================
$summaryStmt = $conn->query("
    SELECT
        COUNT(*) AS total_plans,
        SUM(CASE WHEN approval_status = 'Approved' THEN 1 ELSE 0 END) AS approved_plans,
        SUM(CASE WHEN approval_status = 'Draft' THEN 1 ELSE 0 END) AS draft_plans,
        SUM(CASE WHEN approval_status = 'Under Review' THEN 1 ELSE 0 END) AS review_plans,
        SUM(CASE WHEN approval_status = 'Archived' THEN 1 ELSE 0 END) AS archived_plans
    FROM study_plans
");

$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$totalPlans = (int)($summary["total_plans"] ?? 0);
$approvedPlans = (int)($summary["approved_plans"] ?? 0);
$draftPlans = (int)($summary["draft_plans"] ?? 0);
$reviewPlans = (int)($summary["review_plans"] ?? 0);
$archivedPlans = (int)($summary["archived_plans"] ?? 0);

// ===============================
// Filter options
// ===============================
$yearRows = $conn->query("
    SELECT DISTINCT academic_year
    FROM study_plans
    WHERE academic_year IS NOT NULL AND academic_year <> ''
    ORDER BY academic_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

$versionRows = $conn->query("
    SELECT DISTINCT plan_version
    FROM study_plans
    WHERE plan_version IS NOT NULL AND plan_version <> ''
    ORDER BY plan_version DESC
")->fetchAll(PDO::FETCH_COLUMN);

$statusOptions = ["Draft", "Under Review", "Approved", "Archived"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Study Plans</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(130px, 1fr));
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

        .badge-approved {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-draft {
            background: #fff3cd;
            color: #664d03;
        }

        .badge-review {
            background: #cff4fc;
            color: #055160;
        }

        .badge-archived {
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

        .actions a {
            display: block;
            width: 125px;
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

        @media (max-width: 1100px) {
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
        <h1>Study Plans</h1>
        <p>Manage Health Promotion study plans and detailed bilingual course matrix.</p>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalPlans); ?></div>
            <div class="summary-label">Total Plans</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($approvedPlans); ?></div>
            <div class="summary-label">Approved</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($reviewPlans); ?></div>
            <div class="summary-label">Under Review</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($draftPlans); ?></div>
            <div class="summary-label">Draft</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($archivedPlans); ?></div>
            <div class="summary-label">Archived</div>
        </div>
    </div>

    <div class="filter-box">
        <h2>Filter Study Plans</h2>

        <form method="GET" action="study_plans.php">
            <div class="filter-grid">
                <div>
                    <label>Search</label>
                    <input type="text"
                           name="search"
                           value="<?php echo safeText($filter_search); ?>"
                           placeholder="Plan, program, department, notes">
                </div>

                <div>
                    <label>Academic Year</label>
                    <select name="academic_year">
                        <option value="">All Years</option>
                        <?php foreach ($yearRows as $year): ?>
                            <option value="<?php echo safeText($year); ?>"
                                <?php if ($filter_academic_year === $year) echo "selected"; ?>>
                                <?php echo safeText($year); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Plan Version</label>
                    <select name="plan_version">
                        <option value="">All Versions</option>
                        <?php foreach ($versionRows as $version): ?>
                            <option value="<?php echo safeText($version); ?>"
                                <?php if ($filter_plan_version === $version) echo "selected"; ?>>
                                <?php echo safeText($version); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Approval Status</label>
                    <select name="approval_status">
                        <option value="">All Statuses</option>
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?php echo safeText($status); ?>"
                                <?php if ($filter_approval_status === $status) echo "selected"; ?>>
                                <?php echo safeText($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn">Apply Filters</button>

                <a href="study_plans.php" class="btn btn-secondary">
                    Clear Filters
                </a>

                <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                    <a href="create_study_plan.php" class="btn">
                        Add Study Plan
                    </a>
                <?php endif; ?>
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
            <th>Study Plan</th>
            <th>Program / Department</th>
            <th>Version / Academic Year</th>
            <th>Courses / Credits</th>
            <th>Approval</th>
            <th>Actions</th>
        </tr>

        <?php if (count($studyPlans) > 0): ?>
            <?php foreach ($studyPlans as $plan): ?>
                <tr>
                    <td><?php echo safeText($plan["id"]); ?></td>

                    <td>
                        <strong><?php echo safeText($plan["plan_title"]); ?></strong>

                        <?php if (!empty($plan["notes"])): ?>
                            <br>
                            <span class="small-text">
                                <?php echo safeText(mb_substr($plan["notes"], 0, 140)); ?>
                                <?php if (mb_strlen($plan["notes"]) > 140) echo "..."; ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($plan["program_name"])): ?>
                            <strong><?php echo safeText($plan["program_name"]); ?></strong><br>
                        <?php endif; ?>

                        <?php if (!empty($plan["department_name"])): ?>
                            <span class="small-text">
                                Department: <?php echo safeText($plan["department_name"]); ?>
                            </span>
                            <br>
                        <?php endif; ?>

                        <?php if (!empty($plan["college_name"])): ?>
                            <span class="small-text">
                                College: <?php echo safeText($plan["college_name"]); ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <strong>Version:</strong>
                        <?php echo !empty($plan["plan_version"]) ? safeText($plan["plan_version"]) : "<span class='missing'>Not set</span>"; ?>
                        <br>

                        <strong>Year:</strong>
                        <?php echo !empty($plan["academic_year"]) ? safeText($plan["academic_year"]) : "<span class='missing'>Not set</span>"; ?>
                    </td>

                    <td>
                        <strong>Courses:</strong>
                        <?php echo safeText($plan["course_count"]); ?>
                        <br>

                        <strong>Declared Credits:</strong>
                        <?php echo safeText($plan["total_credit_hours"]); ?>
                        <br>

                        <strong>Calculated Credits:</strong>
                        <?php echo safeText($plan["calculated_credit_hours"]); ?>
                        <br>

                        <span class="small-text">
                            Semesters with courses: <?php echo safeText($plan["semester_count"]); ?>
                        </span>
                    </td>

                    <td>
                        <span class="badge <?php echo safeText(statusBadgeClass($plan["approval_status"])); ?>">
                            <?php echo safeText($plan["approval_status"]); ?>
                        </span>

                        <?php if (!empty($plan["approval_date"])): ?>
                            <br>
                            <span class="small-text">
                                Date: <?php echo safeText($plan["approval_date"]); ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <td class="actions">
                        <a href="study_plan_profile.php?id=<?php echo safeText($plan["id"]); ?>" class="btn">
                            Profile
                        </a>

                        <a href="manage_study_plan_courses.php?id=<?php echo safeText($plan["id"]); ?>" class="btn btn-purple">
                            Courses
                        </a>

                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                            <a href="edit_study_plan.php?id=<?php echo safeText($plan["id"]); ?>" class="btn btn-edit">
                                Edit
                            </a>
                        <?php endif; ?>

                        <?php if (hasRole(["Admin", "Quality Coordinator"])): ?>
                            <?php echo deleteForm('delete_study_plan.php?id=' . urlencode((string)$plan['id']), 'Delete this study plan? This should only be done for test records.'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="7">No study plans found.</td>
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
