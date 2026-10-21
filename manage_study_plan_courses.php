<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid study plan ID.");
}

$study_plan_id = (int)$_GET["id"];

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
    return "manage_study_plan_courses.php?" . http_build_query($queryParams);
}

if (!tableExists($conn, "study_plans")) {
    die("study_plans table does not exist.");
}

if (!tableExists($conn, "study_plan_course_matrix")) {
    die("study_plan_course_matrix table does not exist.");
}

$stmt = $conn->prepare("
    SELECT *
    FROM study_plans
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $study_plan_id]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    die("Study plan not found.");
}

// ===============================
// Filters
// ===============================
$filter_search = trim($_GET["search"] ?? "");
$filter_semester_no = trim($_GET["semester_no"] ?? "");
$filter_requirement_type = trim($_GET["course_requirement_type"] ?? "");
$filter_teaching_mode = trim($_GET["teaching_mode"] ?? "");
$filter_responsible_department = trim($_GET["responsible_department"] ?? "");

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
// WHERE
// ===============================
$whereSql = " WHERE study_plan_id = :study_plan_id ";
$params = [
    ":study_plan_id" => $study_plan_id
];

if ($filter_search !== "") {
    $whereSql .= " AND (
        course_code_ar LIKE :search
        OR course_name_ar LIKE :search
        OR course_code_en LIKE :search
        OR course_name_en LIKE :search
        OR course_description LIKE :search
        OR responsible_department LIKE :search
        OR prerequisite_1_name LIKE :search
        OR prerequisite_2_name LIKE :search
        OR prerequisite_1_code LIKE :search
        OR prerequisite_2_code LIKE :search
    )";
    $params[":search"] = "%" . $filter_search . "%";
}

if ($filter_semester_no !== "") {
    $whereSql .= " AND semester_no = :semester_no";
    $params[":semester_no"] = $filter_semester_no;
}

if ($filter_requirement_type !== "") {
    $whereSql .= " AND course_requirement_type = :course_requirement_type";
    $params[":course_requirement_type"] = $filter_requirement_type;
}

if ($filter_teaching_mode !== "") {
    $whereSql .= " AND teaching_mode = :teaching_mode";
    $params[":teaching_mode"] = $filter_teaching_mode;
}

if ($filter_responsible_department !== "") {
    $whereSql .= " AND responsible_department = :responsible_department";
    $params[":responsible_department"] = $filter_responsible_department;
}

// ===============================
// Count
// ===============================
$countStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM study_plan_course_matrix
    $whereSql
");
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
// Load courses
// ===============================
$sql = "
    SELECT *
    FROM study_plan_course_matrix
    $whereSql
    ORDER BY
        semester_no ASC,
        course_sequence ASC,
        id ASC
    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Summary
// ===============================
$summaryStmt = $conn->prepare("
    SELECT
        COUNT(*) AS course_count,
        COALESCE(SUM(credit_hours), 0) AS total_credits,
        COUNT(DISTINCT semester_no) AS semester_count
    FROM study_plan_course_matrix
    WHERE study_plan_id = :study_plan_id
");
$summaryStmt->execute([":study_plan_id" => $study_plan_id]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$courseCount = (int)($summary["course_count"] ?? 0);
$totalCredits = $summary["total_credits"] ?? 0;
$semesterCount = (int)($summary["semester_count"] ?? 0);

// ===============================
// Filter options
// ===============================
$semesterRowsStmt = $conn->prepare("
    SELECT DISTINCT semester_no
    FROM study_plan_course_matrix
    WHERE study_plan_id = :study_plan_id
      AND semester_no IS NOT NULL
    ORDER BY semester_no ASC
");
$semesterRowsStmt->execute([":study_plan_id" => $study_plan_id]);
$semesterRows = $semesterRowsStmt->fetchAll(PDO::FETCH_COLUMN);

$typeRowsStmt = $conn->prepare("
    SELECT DISTINCT course_requirement_type
    FROM study_plan_course_matrix
    WHERE study_plan_id = :study_plan_id
      AND course_requirement_type IS NOT NULL
      AND course_requirement_type <> ''
    ORDER BY course_requirement_type ASC
");
$typeRowsStmt->execute([":study_plan_id" => $study_plan_id]);
$typeRows = $typeRowsStmt->fetchAll(PDO::FETCH_COLUMN);

$modeRowsStmt = $conn->prepare("
    SELECT DISTINCT teaching_mode
    FROM study_plan_course_matrix
    WHERE study_plan_id = :study_plan_id
      AND teaching_mode IS NOT NULL
      AND teaching_mode <> ''
    ORDER BY teaching_mode ASC
");
$modeRowsStmt->execute([":study_plan_id" => $study_plan_id]);
$modeRows = $modeRowsStmt->fetchAll(PDO::FETCH_COLUMN);

$departmentRowsStmt = $conn->prepare("
    SELECT DISTINCT responsible_department
    FROM study_plan_course_matrix
    WHERE study_plan_id = :study_plan_id
      AND responsible_department IS NOT NULL
      AND responsible_department <> ''
    ORDER BY responsible_department ASC
");
$departmentRowsStmt->execute([":study_plan_id" => $study_plan_id]);
$departmentRows = $departmentRowsStmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Study Plan Courses</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(150px, 1fr));
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
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 12px;
        }

        .filter-actions {
            margin-top: 15px;
        }

        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
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
            .actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 18px;
}

.actions a {
    display: inline-block;
    width: auto;
    text-align: center;
    margin-bottom: 0;
}

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .matrix-table {
            min-width: 1500px;
            width: 100%;
            border-collapse: collapse;
        }

        .matrix-table th,
        .matrix-table td {
            vertical-align: top;
            font-size: 12px;
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

        @media (max-width: 1000px) {
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
        <h1>Manage Study Plan Courses</h1>
        <p>Manage the detailed bilingual course matrix for the selected study plan.</p>
    </div>

    <div class="actions">
        <a href="study_plan_profile.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-secondary">Back to Plan Profile</a>
        <a href="study_plans.php" class="btn">Study Plans</a>

        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
            <a href="create_study_plan_course.php?study_plan_id=<?php echo safeText($study_plan_id); ?>" class="btn">Add Course</a>
            <a href="upload_study_plan_courses_csv.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-purple">Upload CSV</a>
		<a href="study_plan_course_template_csv.php" class="btn btn-purple">
    Template CSV
		<a href="export_study_plan_courses_csv.php?id=<?php echo safeText($study_plan_id); ?>&<?php echo safeText(http_build_query($_GET)); ?>" class="btn btn-purple">
    Export CSV
</a>

        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Study Plan</h2>

        <table>
            <tr>
                <th>Plan Title</th>
                <td><?php echo safeText($plan["plan_title"]); ?></td>
                <th>Program</th>
                <td><?php echo safeText($plan["program_name"]); ?></td>
            </tr>

            <tr>
                <th>Version</th>
                <td><?php echo safeText($plan["plan_version"]); ?></td>
                <th>Academic Year</th>
                <td><?php echo safeText($plan["academic_year"]); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($courseCount); ?></div>
            <div class="summary-label">Total Courses</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($semesterCount); ?></div>
            <div class="summary-label">Semesters</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($plan["total_credit_hours"]); ?></div>
            <div class="summary-label">Declared Credits</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalCredits); ?></div>
            <div class="summary-label">Calculated Credits</div>
        </div>
    </div>

    <div class="filter-box">
        <h2>Filter Courses</h2>

        <form method="GET" action="manage_study_plan_courses.php">
            <input type="hidden" name="id" value="<?php echo safeText($study_plan_id); ?>">

            <div class="filter-grid">
                <div>
                    <label>Search</label>
                    <input type="text"
                           name="search"
                           value="<?php echo safeText($filter_search); ?>"
                           placeholder="Course code, name, prerequisite, department">
                </div>

                <div>
                    <label>Semester</label>
                    <select name="semester_no">
                        <option value="">All Semesters</option>
                        <?php foreach ($semesterRows as $semester): ?>
                            <option value="<?php echo safeText($semester); ?>"
                                <?php if ((string)$filter_semester_no === (string)$semester) echo "selected"; ?>>
                                <?php echo safeText($semester); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Requirement Type</label>
                    <select name="course_requirement_type">
                        <option value="">All Types</option>
                        <?php foreach ($typeRows as $type): ?>
                            <option value="<?php echo safeText($type); ?>"
                                <?php if ($filter_requirement_type === $type) echo "selected"; ?>>
                                <?php echo safeText($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Teaching Mode</label>
                    <select name="teaching_mode">
                        <option value="">All Modes</option>
                        <?php foreach ($modeRows as $mode): ?>
                            <option value="<?php echo safeText($mode); ?>"
                                <?php if ($filter_teaching_mode === $mode) echo "selected"; ?>>
                                <?php echo safeText($mode); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Responsible Department</label>
                    <select name="responsible_department">
                        <option value="">All Departments</option>
                        <?php foreach ($departmentRows as $department): ?>
                            <option value="<?php echo safeText($department); ?>"
                                <?php if ($filter_responsible_department === $department) echo "selected"; ?>>
                                <?php echo safeText($department); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn">Apply Filters</button>
		<a href="export_study_plan_courses_csv.php?id=<?php echo safeText($study_plan_id); ?>&<?php echo safeText(http_build_query($_GET)); ?>" class="btn btn-purple">
    Export CSV
</a>

                <a href="manage_study_plan_courses.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-secondary">
                    Clear Filters
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

    <div class="wide-table-wrapper">
        <table class="matrix-table">
            <tr>
                <th>Seq.</th>
                <th>Semester</th>
                <th>Code AR</th>
                <th>Name AR</th>
                <th>Code EN</th>
                <th>Name EN</th>
                <th>Credits</th>
                <th>Requirement</th>
                <th>Mode</th>
                <th>Activity</th>
                <th>Credit Distribution</th>
                <th>Contact Distribution</th>
                <th>Prerequisites</th>
                <th>Department</th>
                <th>Description</th>
                <th>Actions</th>
            </tr>

            <?php if (count($courses) > 0): ?>
                <?php foreach ($courses as $course): ?>
                    <tr>
                        <td><?php echo safeText($course["course_sequence"]); ?></td>
                        <td><?php echo safeText($course["semester_no"]); ?></td>
                        <td><?php echo safeText($course["course_code_ar"]); ?></td>
                        <td><?php echo safeText($course["course_name_ar"]); ?></td>
                        <td><?php echo safeText($course["course_code_en"]); ?></td>
                        <td><?php echo safeText($course["course_name_en"]); ?></td>
                        <td><?php echo safeText($course["credit_hours"]); ?></td>
                        <td><?php echo safeText($course["course_requirement_type"]); ?></td>
                        <td><?php echo safeText($course["teaching_mode"]); ?></td>
                        <td><?php echo safeText($course["course_activity"]); ?></td>

                        <td>
                            Theory: <?php echo safeText($course["credit_theory"]); ?><br>
                            Practical: <?php echo safeText($course["credit_practical"]); ?><br>
                            Training: <?php echo safeText($course["credit_training"]); ?><br>
                            Project: <?php echo safeText($course["credit_graduation_project"]); ?><br>
                            Seminar: <?php echo safeText($course["credit_seminar"]); ?><br>
                            Clinical: <?php echo safeText($course["credit_clinical_training"]); ?><br>
                            Edu.App: <?php echo safeText($course["credit_educational_applications"]); ?><br>
                            Simulation: <?php echo safeText($course["credit_clinical_simulation"]); ?><br>
                            Other: <?php echo safeText($course["credit_other"]); ?>
                        </td>

                        <td>
                            Theory: <?php echo safeText($course["contact_theory"]); ?><br>
                            Practical: <?php echo safeText($course["contact_practical"]); ?><br>
                            Training: <?php echo safeText($course["contact_training"]); ?><br>
                            Project: <?php echo safeText($course["contact_graduation_project"]); ?><br>
                            Seminar: <?php echo safeText($course["contact_seminar"]); ?><br>
                            Clinical: <?php echo safeText($course["contact_clinical_training"]); ?><br>
                            Edu.App: <?php echo safeText($course["contact_educational_applications"]); ?><br>
                            Simulation: <?php echo safeText($course["contact_clinical_simulation"]); ?><br>
                            Other: <?php echo safeText($course["contact_other"]); ?>
                        </td>

                        <td>
                            <?php if (!empty($course["prerequisite_1_name"]) || !empty($course["prerequisite_1_code"])): ?>
                                1: <?php echo safeText($course["prerequisite_1_name"]); ?>
                                <?php if (!empty($course["prerequisite_1_code"])): ?>
                                    (<?php echo safeText($course["prerequisite_1_code"]); ?>)
                                <?php endif; ?>
                                <br>
                            <?php endif; ?>

                            <?php if (!empty($course["prerequisite_2_name"]) || !empty($course["prerequisite_2_code"])): ?>
                                2: <?php echo safeText($course["prerequisite_2_name"]); ?>
                                <?php if (!empty($course["prerequisite_2_code"])): ?>
                                    (<?php echo safeText($course["prerequisite_2_code"]); ?>)
                                <?php endif; ?>
                                <br>
                            <?php endif; ?>

                            <?php if (!empty($course["prerequisite_other"])): ?>
                                Other: <?php echo safeText($course["prerequisite_other"]); ?>
                            <?php endif; ?>

                            <?php if (
                                empty($course["prerequisite_1_name"]) &&
                                empty($course["prerequisite_1_code"]) &&
                                empty($course["prerequisite_2_name"]) &&
                                empty($course["prerequisite_2_code"]) &&
                                empty($course["prerequisite_other"])
                            ): ?>
                                <span class="missing">None</span>
                            <?php endif; ?>
                        </td>

                        <td><?php echo safeText($course["responsible_department"]); ?></td>

                        <td>
                            <?php echo safeText(mb_substr($course["course_description"] ?? "", 0, 180)); ?>
                            <?php if (mb_strlen($course["course_description"] ?? "") > 180) echo "..."; ?>
                        </td>

                        <td class="actions">
                            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                                <a href="edit_study_plan_course.php?id=<?php echo safeText($course["id"]); ?>" class="btn btn-edit">
                                    Edit
                                </a>
				<a href="study_plan_validation.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-purple">
   				 Validate Plan
				</a>

                                <?php echo deleteForm('delete_study_plan_course.php?id=' . urlencode((string)$course['id']), 'Delete this course from the study plan?'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="16">No courses found.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

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
