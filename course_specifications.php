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

function statusClass($status) {
    if ($status === "Active") {
        return "status-active";
    }

    if ($status === "Inactive") {
        return "status-inactive";
    }

    if ($status === "Draft") {
        return "status-draft";
    }

    return "";
}

function linkedReportsClass($count) {
    $count = (int)$count;

    if ($count > 0) {
        return "linked-used";
    }

    return "linked-unused";
}

// ===============================
// Read filters
// ===============================
$filter_search = trim($_GET["search"] ?? "");
$filter_program = trim($_GET["program_name"] ?? "");
$filter_department = trim($_GET["department_name"] ?? "");
$filter_status = trim($_GET["status"] ?? "");

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

if ($filter_search !== "") {
    $whereSql .= " AND (
        cs.course_code LIKE :search_course_code
        OR cs.course_title LIKE :search_course_title
        OR cs.program_name LIKE :search_program
        OR cs.department_name LIKE :search_department
        OR cs.college_name LIKE :search_college
        OR cs.institution_name LIKE :search_institution
        OR cs.course_description LIKE :search_description
        OR cs.course_objectives LIKE :search_objectives
    )";

    $searchValue = "%" . $filter_search . "%";

    $params[":search_course_code"] = $searchValue;
    $params[":search_course_title"] = $searchValue;
    $params[":search_program"] = $searchValue;
    $params[":search_department"] = $searchValue;
    $params[":search_college"] = $searchValue;
    $params[":search_institution"] = $searchValue;
    $params[":search_description"] = $searchValue;
    $params[":search_objectives"] = $searchValue;
}

if ($filter_program !== "") {
    $whereSql .= " AND cs.program_name LIKE :program_name";
    $params[":program_name"] = "%" . $filter_program . "%";
}

if ($filter_department !== "") {
    $whereSql .= " AND cs.department_name LIKE :department_name";
    $params[":department_name"] = "%" . $filter_department . "%";
}

if ($filter_status !== "") {
    $whereSql .= " AND cs.status = :status";
    $params[":status"] = $filter_status;
}

// ===============================
// Count total filtered records
// ===============================
$countSql = "
    SELECT COUNT(*)
    FROM course_specifications cs
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

// Safety
$recordsPerPage = (int)$recordsPerPage;
$offset = (int)$offset;

// ===============================
// Load paginated course specifications
// ===============================
$sql = "
    SELECT 
        cs.id,
        cs.course_code,
        cs.course_title,
        cs.program_name,
        cs.department_name,
        cs.college_name,
        cs.institution_name,
        cs.specification_version,
        cs.last_revision_date,
        cs.credit_hours,
        cs.theoretical_hours,
        cs.practical_hours,
        cs.course_type_a,
        cs.course_type_b,
        cs.level_year,
        cs.course_description,
        cs.pre_requisites,
        cs.co_requisites,
        cs.course_objectives,
        cs.approval_council,
        cs.approval_reference_no,
        cs.approval_date,
        cs.status,
        cs.created_at,
        cs.updated_at,

        COUNT(DISTINCT clo.id) AS clo_count,
        COUNT(DISTINCT topic.id) AS topic_count,
        COUNT(DISTINCT ass.id) AS assessment_count,
        COUNT(DISTINCT cr.id) AS linked_reports_count

    FROM course_specifications cs

    LEFT JOIN course_specification_clos clo 
        ON clo.course_specification_id = cs.id

    LEFT JOIN course_specification_topics topic 
        ON topic.course_specification_id = cs.id

    LEFT JOIN course_specification_assessments ass 
        ON ass.course_specification_id = cs.id

    LEFT JOIN course_reports cr
        ON cr.course_specification_id = cs.id

    $whereSql

    GROUP BY 
        cs.id,
        cs.course_code,
        cs.course_title,
        cs.program_name,
        cs.department_name,
        cs.college_name,
        cs.institution_name,
        cs.specification_version,
        cs.last_revision_date,
        cs.credit_hours,
        cs.theoretical_hours,
        cs.practical_hours,
        cs.course_type_a,
        cs.course_type_b,
        cs.level_year,
        cs.course_description,
        cs.pre_requisites,
        cs.co_requisites,
        cs.course_objectives,
        cs.approval_council,
        cs.approval_reference_no,
        cs.approval_date,
        cs.status,
        cs.created_at,
        cs.updated_at

    ORDER BY cs.course_code ASC, cs.id DESC

    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$courseSpecifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Preserve filters in pagination links
// ===============================
$queryParams = $_GET;
unset($queryParams["page"]);

$baseQueryString = http_build_query($queryParams);
$basePageUrl = "course_specifications.php" . ($baseQueryString !== "" ? "?" . $baseQueryString . "&" : "?");
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Specifications</title>
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
            grid-template-columns: repeat(4, minmax(180px, 1fr));
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

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .status-active {
            color: #28a745;
            font-weight: bold;
        }

        .status-inactive {
            color: #dc3545;
            font-weight: bold;
        }

        .status-draft {
            color: #fd7e14;
            font-weight: bold;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            background: #e9ecef;
            color: #495057;
            margin-bottom: 3px;
        }

        .linked-used {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            background: #d4edda;
            color: #155724;
            margin-bottom: 3px;
        }

        .linked-unused {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            background: #f8d7da;
            color: #721c24;
            margin-bottom: 3px;
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

        @media (max-width: 1100px) {
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
        <h1>Course Specifications</h1>
        <p>Manage approved course specifications used as source data for course reports and automated report generation.</p>
    </div>

    <div class="filter-box">
        <h2>Filter Course Specifications</h2>

        <form method="GET" action="course_specifications.php">

            <div class="filter-grid">

                <div>
                    <label>Search</label>
                    <input type="text" name="search"
                           value="<?php echo safeText($filter_search); ?>"
                           placeholder="Code, title, description, objective">
                </div>

                <div>
                    <label>Program</label>
                    <input type="text" name="program_name"
                           value="<?php echo safeText($filter_program); ?>"
                           placeholder="Example: Health Promotion">
                </div>

                <div>
                    <label>Department</label>
                    <input type="text" name="department_name"
                           value="<?php echo safeText($filter_department); ?>"
                           placeholder="Example: Health Promotion">
                </div>

                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>

                        <?php
                        $statuses = ["Active", "Inactive", "Draft"];
                        foreach ($statuses as $status):
                        ?>
                            <option value="<?php echo safeText($status); ?>"
                                <?php if ($filter_status === $status) echo "selected"; ?>>
                                <?php echo safeText($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>

            <div class="filter-actions">
                <button type="submit" class="btn">Apply Filters</button>
                <a href="course_specifications.php" class="btn btn-secondary">Clear Filters</a>

                <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                    <a href="create_course_specification.php" class="btn">Add Course Specification</a>
                <?php endif; ?>
            </div>

        </form>
    </div>

    <div class="result-count">
        Results found: <?php echo safeText($totalRecords); ?>
    </div>

    <div class="pagination-info">
        Page <?php echo safeText($page); ?> of <?php echo safeText($totalPages); ?>
        |
        <?php echo safeText($recordsPerPage); ?> records per page
        |
        Total records: <?php echo safeText($totalRecords); ?>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>Course</th>
            <th>Program / Department</th>
            <th>Version</th>
            <th>Credit Hours</th>
            <th>Level / Year</th>
            <th>Details Count</th>
            <th>Linked Reports</th>
            <th>Status</th>
            <th>Updated At</th>
            <th>Actions</th>
        </tr>

        <?php if (count($courseSpecifications) > 0): ?>

            <?php foreach ($courseSpecifications as $spec): ?>
                <tr>
                    <td><?php echo safeText($spec["id"]); ?></td>

                    <td>
                        <strong><?php echo safeText($spec["course_code"]); ?></strong><br>
                        <?php echo safeText($spec["course_title"]); ?>
                    </td>

                    <td>
                        <strong>Program:</strong>
                        <?php echo safeText($spec["program_name"]); ?><br>

                        <strong>Department:</strong>
                        <?php echo safeText($spec["department_name"]); ?><br>

                        <span class="small-text">
                            <?php echo safeText($spec["college_name"]); ?>
                        </span>
                    </td>

                    <td>
                        <?php echo safeText($spec["specification_version"]); ?><br>
                        <span class="small-text">
                            Last revision:
                            <?php echo safeText($spec["last_revision_date"]); ?>
                        </span>
                    </td>

                    <td>
                        <?php echo safeText($spec["credit_hours"]); ?>
                    </td>

                    <td>
                        <?php echo safeText($spec["level_year"]); ?>
                    </td>

                    <td>
                        <span class="badge">CLOs: <?php echo safeText($spec["clo_count"]); ?></span><br>
                        <span class="badge">Topics: <?php echo safeText($spec["topic_count"]); ?></span><br>
                        <span class="badge">Assessments: <?php echo safeText($spec["assessment_count"]); ?></span>
                    </td>

                    <td>
                        <span class="<?php echo safeText(linkedReportsClass($spec["linked_reports_count"])); ?>">
                            Reports: <?php echo safeText($spec["linked_reports_count"]); ?>
                        </span>

                        <?php if ((int)$spec["linked_reports_count"] > 0): ?>
                            <br>
                            <span class="small-text">
                                Used in Course Reports
                            </span>
                        <?php else: ?>
                            <br>
                            <span class="small-text">
                                Not linked yet
                            </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <span class="<?php echo safeText(statusClass($spec["status"])); ?>">
                            <?php echo safeText($spec["status"]); ?>
                        </span>
                    </td>

                    <td>
                        <span class="small-text">
                            <?php echo safeText($spec["updated_at"]); ?>
                        </span>
                    </td>

                    <td class="actions">
			<a href="course_specification_profile.php?id=<?php echo safeText($spec["id"]); ?>" class="btn">
    Profile
</a>
                        <a href="view_course_specification.php?id=<?php echo safeText($spec["id"]); ?>" class="btn btn-purple">
                            View
                        </a>

                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                            <a href="edit_course_specification.php?id=<?php echo safeText($spec["id"]); ?>" class="btn btn-edit">
                                Edit
                            </a>

                            <a href="manage_course_specification_details.php?id=<?php echo safeText($spec["id"]); ?>" class="btn">
                                Manage Details
                            </a>
                        <?php endif; ?>

                        <?php if (hasRole(["Admin", "Quality Coordinator"])): ?>
                            <?php echo deleteForm('delete_course_specification.php?id=' . urlencode((string)$spec['id'])); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

        <?php else: ?>

            <tr>
                <td colspan="11">No course specifications found.</td>
            </tr>

        <?php endif; ?>

    </table>

    <div class="pagination">
        <div class="pagination-info">
            Page <?php echo safeText($page); ?> of <?php echo safeText($totalPages); ?>
            |
            <?php echo safeText($recordsPerPage); ?> records per page
            |
            Total records: <?php echo safeText($totalRecords); ?>
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

        <?php if ($startPage > 1): ?>
            <a href="<?php echo safeText($basePageUrl); ?>page=1">1</a>

            <?php if ($startPage > 2): ?>
                <span class="disabled">...</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="current-page"><?php echo safeText($i); ?></span>
            <?php else: ?>
                <a href="<?php echo safeText($basePageUrl); ?>page=<?php echo safeText($i); ?>">
                    <?php echo safeText($i); ?>
                </a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?>
                <span class="disabled">...</span>
            <?php endif; ?>

            <a href="<?php echo safeText($basePageUrl); ?>page=<?php echo safeText($totalPages); ?>">
                <?php echo safeText($totalPages); ?>
            </a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
            <a href="<?php echo safeText($basePageUrl); ?>page=<?php echo safeText($page + 1); ?>">Next</a>
            <a href="<?php echo safeText($basePageUrl); ?>page=<?php echo safeText($totalPages); ?>">Last</a>
        <?php else: ?>
            <span class="disabled">Next</span>
            <span class="disabled">Last</span>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
