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

if (!tableExists($conn, "study_plans")) {
    die("study_plans table does not exist.");
}

if (!tableExists($conn, "study_plan_course_matrix")) {
    die("study_plan_course_matrix table does not exist.");
}

// ===============================
// Load study plan
// ===============================
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
// Summary
// ===============================
$summaryStmt = $conn->prepare("
    SELECT
        COUNT(*) AS course_count,
        COALESCE(SUM(credit_hours), 0) AS calculated_credit_hours,
        COUNT(DISTINCT semester_no) AS semester_count,

        COALESCE(SUM(credit_theory), 0) AS total_credit_theory,
        COALESCE(SUM(credit_practical), 0) AS total_credit_practical,
        COALESCE(SUM(credit_training), 0) AS total_credit_training,
        COALESCE(SUM(credit_graduation_project), 0) AS total_credit_graduation_project,

        COALESCE(SUM(contact_theory), 0) AS total_contact_theory,
        COALESCE(SUM(contact_practical), 0) AS total_contact_practical,
        COALESCE(SUM(contact_training), 0) AS total_contact_training,
        COALESCE(SUM(contact_graduation_project), 0) AS total_contact_graduation_project
    FROM study_plan_course_matrix
    WHERE study_plan_id = :study_plan_id
");
$summaryStmt->execute([":study_plan_id" => $study_plan_id]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$courseCount = (int)($summary["course_count"] ?? 0);
$calculatedCredits = $summary["calculated_credit_hours"] ?? 0;
$semesterCount = (int)($summary["semester_count"] ?? 0);

// ===============================
// Requirement type summary
// ===============================
$requirementStmt = $conn->prepare("
    SELECT
        course_requirement_type,
        COUNT(*) AS course_count,
        COALESCE(SUM(credit_hours), 0) AS credit_sum
    FROM study_plan_course_matrix
    WHERE study_plan_id = :study_plan_id
    GROUP BY course_requirement_type
    ORDER BY credit_sum DESC, course_requirement_type ASC
");
$requirementStmt->execute([":study_plan_id" => $study_plan_id]);
$requirementRows = $requirementStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Semester summary
// ===============================
$semesterStmt = $conn->prepare("
    SELECT
        semester_no,
        COUNT(*) AS course_count,
        COALESCE(SUM(credit_hours), 0) AS credit_sum
    FROM study_plan_course_matrix
    WHERE study_plan_id = :study_plan_id
    GROUP BY semester_no
    ORDER BY semester_no ASC
");
$semesterStmt->execute([":study_plan_id" => $study_plan_id]);
$semesterRows = $semesterStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Course rows
// ===============================
$coursesStmt = $conn->prepare("
    SELECT *
    FROM study_plan_course_matrix
    WHERE study_plan_id = :study_plan_id
    ORDER BY
        semester_no ASC,
        course_sequence ASC,
        id ASC
");
$coursesStmt->execute([":study_plan_id" => $study_plan_id]);
$courses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

$statusClass = statusBadgeClass($plan["approval_status"] ?? "");
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Study Plan Profile</title>
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

        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
        }

        .profile-title {
            font-size: 24px;
            font-weight: bold;
            color: #222;
            margin-bottom: 6px;
        }

        .profile-subtitle {
            color: #555;
            margin-bottom: 12px;
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

        .actions {
            margin-bottom: 18px;
        }

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .matrix-table {
            min-width: 1800px;
            width: 100%;
            border-collapse: collapse;
        }

        .matrix-table th,
        .matrix-table td {
            vertical-align: top;
            font-size: 12px;
        }

        .semester-title {
            background: #f8f9fa;
            font-weight: bold;
            color: #333;
        }

        @media (max-width: 1100px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(130px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Study Plan Profile</h1>
        <p>Detailed bilingual course matrix, credit-hour distribution, contact-hour distribution, and prerequisites.</p>
    </div>

    <div class="actions">
                <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
            <a href="study_plans.php" class="btn btn-secondary">Back to Study Plans</a>
		<a href="edit_study_plan.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-edit">Edit Plan</a>
            <a href="manage_study_plan_courses.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-purple">Manage Courses</a>
            <a href="create_study_plan_course.php?study_plan_id=<?php echo safeText($study_plan_id); ?>" class="btn">Add Course</a>
            <a href="upload_study_plan_courses_csv.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-purple">Upload Courses CSV</a>
		<a href="study_plan_validation.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-purple">
    Validate Plan
</a>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="profile-title">
            <?php echo safeText($plan["plan_title"]); ?>
        </div>

        <div class="profile-subtitle">
            <?php echo safeText($plan["program_name"]); ?>

            <?php if (!empty($plan["academic_year"])): ?>
                | Academic Year: <?php echo safeText($plan["academic_year"]); ?>
            <?php endif; ?>

            <?php if (!empty($plan["plan_version"])): ?>
                | Version: <?php echo safeText($plan["plan_version"]); ?>
            <?php endif; ?>
        </div>

        <span class="badge <?php echo safeText($statusClass); ?>">
            <?php echo safeText($plan["approval_status"]); ?>
        </span>

        <br><br>

        <table>
            <tr>
                <th>Program Name</th>
                <td><?php echo safeText($plan["program_name"]); ?></td>
                <th>Department</th>
                <td><?php echo safeText($plan["department_name"]); ?></td>
            </tr>

            <tr>
                <th>College</th>
                <td><?php echo safeText($plan["college_name"]); ?></td>
                <th>Institution</th>
                <td><?php echo safeText($plan["institution_name"]); ?></td>
            </tr>

            <tr>
                <th>Plan Version</th>
                <td><?php echo safeText($plan["plan_version"]); ?></td>
                <th>Academic Year</th>
                <td><?php echo safeText($plan["academic_year"]); ?></td>
            </tr>

            <tr>
                <th>Declared Credit Hours</th>
                <td><?php echo safeText($plan["total_credit_hours"]); ?></td>
                <th>Calculated Credit Hours</th>
                <td><?php echo safeText($calculatedCredits); ?></td>
            </tr>

            <tr>
                <th>Approval Date</th>
                <td><?php echo safeText($plan["approval_date"]); ?></td>
                <th>Created / Updated</th>
                <td>
                    <?php echo safeText($plan["created_at"]); ?>
                    <br>
                    <span class="small-text"><?php echo safeText($plan["updated_at"]); ?></span>
                </td>
            </tr>

            <tr>
                <th>Notes</th>
                <td colspan="3"><?php echo nl2br(safeText($plan["notes"])); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($courseCount); ?></div>
            <div class="summary-label">Courses</div>
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
            <div class="summary-number"><?php echo safeText($calculatedCredits); ?></div>
            <div class="summary-label">Calculated Credits</div>
        </div>

        <div class="summary-card">
            <div class="summary-number">
                <?php
                $difference = (float)$calculatedCredits - (float)($plan["total_credit_hours"] ?? 0);
                echo safeText($difference);
                ?>
            </div>
            <div class="summary-label">Credit Difference</div>
        </div>
    </div>

    <div class="panel">
        <h2>Credit Hours by Requirement Type</h2>

        <table>
            <tr>
                <th>Requirement Type</th>
                <th>Courses</th>
                <th>Credit Hours</th>
            </tr>

            <?php if (count($requirementRows) > 0): ?>
                <?php foreach ($requirementRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["course_requirement_type"] ?: "Not specified"); ?></td>
                        <td><?php echo safeText($row["course_count"]); ?></td>
                        <td><?php echo safeText($row["credit_sum"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="3">No requirement type summary available.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Credit Hours by Semester</h2>

        <table>
            <tr>
                <th>Semester</th>
                <th>Courses</th>
                <th>Credit Hours</th>
            </tr>

            <?php if (count($semesterRows) > 0): ?>
                <?php foreach ($semesterRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["semester_no"]); ?></td>
                        <td><?php echo safeText($row["course_count"]); ?></td>
                        <td><?php echo safeText($row["credit_sum"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="3">No semester summary available.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Detailed Study Plan Course Matrix</h2>

        <div class="actions">
            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                <a href="create_study_plan_course.php?study_plan_id=<?php echo safeText($study_plan_id); ?>" class="btn">Add Course</a>
                <a href="manage_study_plan_courses.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-purple">Manage Courses</a>
                <a href="upload_study_plan_courses_csv.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-purple">Upload CSV</a>
            <?php endif; ?>
        </div>

        <div class="wide-table-wrapper">
            <table class="matrix-table">
                <tr>
                    <th>Seq.</th>
                    <th>Semester</th>
                    <th>Course Code AR</th>
                    <th>Course Name AR</th>
                    <th>Course Code EN</th>
                    <th>Course Name EN</th>
                    <th>Credits</th>
                    <th>Requirement Type</th>
                    <th>Teaching Mode</th>
                    <th>Activity</th>
                    <th>Credit Distribution</th>
                    <th>Contact Distribution</th>
                    <th>Prerequisites</th>
                    <th>Responsible Department</th>
                    <th>Description</th>
                    <th>Actions</th>
                </tr>

                <?php if (count($courses) > 0): ?>
                    <?php
                    $currentSemester = null;
                    ?>

                    <?php foreach ($courses as $course): ?>
                        <?php if ($currentSemester !== $course["semester_no"]): ?>
                            <?php $currentSemester = $course["semester_no"]; ?>
                            <tr class="semester-title">
                                <td colspan="16">Semester <?php echo safeText($currentSemester); ?></td>
                            </tr>
                        <?php endif; ?>

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
                                Edu. App.: <?php echo safeText($course["credit_educational_applications"]); ?><br>
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
                                Edu. App.: <?php echo safeText($course["contact_educational_applications"]); ?><br>
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

                                <?php if (empty($course["prerequisite_1_name"]) && empty($course["prerequisite_1_code"]) && empty($course["prerequisite_2_name"]) && empty($course["prerequisite_2_code"]) && empty($course["prerequisite_other"])): ?>
                                    <span class="missing">None</span>
                                <?php endif; ?>
                            </td>

                            <td><?php echo safeText($course["responsible_department"]); ?></td>

                            <td>
                                <?php echo safeText(mb_substr($course["course_description"] ?? "", 0, 220)); ?>
                                <?php if (mb_strlen($course["course_description"] ?? "") > 220) echo "..."; ?>
                            </td>

                            <td class="actions">
                                <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                                    <a href="edit_study_plan_course.php?id=<?php echo safeText($course["id"]); ?>" class="btn btn-edit">
                                        Edit
                                    </a>

                                    <?php echo deleteForm('delete_study_plan_course.php?id=' . urlencode((string)$course['id']), 'Delete this course from the study plan?'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="16">No courses added to this study plan yet.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
