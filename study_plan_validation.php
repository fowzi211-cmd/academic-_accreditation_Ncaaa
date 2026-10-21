<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// Validate study plan ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid study plan ID.");
}

$study_plan_id = (int)$_GET["id"];

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

function issueClass($severity) {
    if ($severity === "High") {
        return "badge-danger";
    }

    if ($severity === "Medium") {
        return "badge-warning";
    }

    if ($severity === "Low") {
        return "badge-muted";
    }

    return "badge-muted";
}

function addIssue(&$issues, $severity, $category, $courseLabel, $message, $action) {
    $issues[] = [
        "severity" => $severity,
        "category" => $category,
        "course" => $courseLabel,
        "message" => $message,
        "action" => $action
    ];
}

// ===============================
// Required tables
// ===============================
if (!tableExists($conn, "study_plans")) {
    die("study_plans table does not exist.");
}

if (!tableExists($conn, "study_plan_course_matrix")) {
    die("study_plan_course_matrix table does not exist.");
}

// ===============================
// Load plan
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
// Load courses
// ===============================
$coursesStmt = $conn->prepare("
    SELECT *
    FROM study_plan_course_matrix
    WHERE study_plan_id = :study_plan_id
    ORDER BY semester_no ASC, course_sequence ASC, id ASC
");
$coursesStmt->execute([":study_plan_id" => $study_plan_id]);
$courses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Summary
// ===============================
$summaryStmt = $conn->prepare("
    SELECT
        COUNT(*) AS course_count,
        COALESCE(SUM(credit_hours), 0) AS calculated_credit_hours,
        COUNT(DISTINCT semester_no) AS semester_count
    FROM study_plan_course_matrix
    WHERE study_plan_id = :study_plan_id
");
$summaryStmt->execute([":study_plan_id" => $study_plan_id]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$courseCount = (int)($summary["course_count"] ?? 0);
$calculatedCredits = (float)($summary["calculated_credit_hours"] ?? 0);
$declaredCredits = (float)($plan["total_credit_hours"] ?? 0);
$semesterCount = (int)($summary["semester_count"] ?? 0);

// ===============================
// Validation rules
// ===============================
$issues = [];

if ($courseCount === 0) {
    addIssue(
        $issues,
        "High",
        "Missing Courses",
        "Study Plan",
        "No courses are entered for this study plan.",
        "Upload courses using CSV or add courses manually."
    );
}

if ($declaredCredits > 0 && abs($declaredCredits - $calculatedCredits) > 0.01) {
    addIssue(
        $issues,
        "High",
        "Credit Hours Mismatch",
        "Study Plan",
        "Declared credit hours are " . $declaredCredits . ", but calculated credit hours from courses are " . $calculatedCredits . ".",
        "Review course credit hours or update the declared total credit hours."
    );
}

$courseCodeCounts = [];

foreach ($courses as $course) {
    $courseId = (int)$course["id"];

    $courseCode = trim((string)($course["course_code_en"] ?? ""));
    if ($courseCode === "") {
        $courseCode = trim((string)($course["course_code_ar"] ?? ""));
    }

    $courseName = trim((string)($course["course_name_en"] ?? ""));
    if ($courseName === "") {
        $courseName = trim((string)($course["course_name_ar"] ?? ""));
    }

    $courseLabel = "ID " . $courseId;

    if ($courseCode !== "") {
        $courseLabel .= " | " . $courseCode;
    }

    if ($courseName !== "") {
        $courseLabel .= " | " . $courseName;
    }

    $semesterNo = trim((string)($course["semester_no"] ?? ""));
    $creditHours = (float)($course["credit_hours"] ?? 0);

    $creditDistribution =
        (float)($course["credit_theory"] ?? 0) +
        (float)($course["credit_practical"] ?? 0) +
        (float)($course["credit_studio"] ?? 0) +
        (float)($course["credit_training"] ?? 0) +
        (float)($course["credit_graduation_project"] ?? 0) +
        (float)($course["credit_seminar"] ?? 0) +
        (float)($course["credit_clinical_training"] ?? 0) +
        (float)($course["credit_educational_applications"] ?? 0) +
        (float)($course["credit_clinical_simulation"] ?? 0) +
        (float)($course["credit_other"] ?? 0);

    $contactDistribution =
        (float)($course["contact_theory"] ?? 0) +
        (float)($course["contact_practical"] ?? 0) +
        (float)($course["contact_studio"] ?? 0) +
        (float)($course["contact_training"] ?? 0) +
        (float)($course["contact_graduation_project"] ?? 0) +
        (float)($course["contact_seminar"] ?? 0) +
        (float)($course["contact_clinical_training"] ?? 0) +
        (float)($course["contact_educational_applications"] ?? 0) +
        (float)($course["contact_clinical_simulation"] ?? 0) +
        (float)($course["contact_other"] ?? 0);

    if ($semesterNo === "") {
        addIssue(
            $issues,
            "High",
            "Missing Semester",
            $courseLabel,
            "Semester number is missing.",
            "Edit the course and enter the semester number."
        );
    }

    if (trim((string)($course["course_code_en"] ?? "")) === "" && trim((string)($course["course_code_ar"] ?? "")) === "") {
        addIssue(
            $issues,
            "High",
            "Missing Course Code",
            $courseLabel,
            "Both Arabic and English course codes are missing.",
            "Edit the course and enter at least one course code."
        );
    }

    if (trim((string)($course["course_name_en"] ?? "")) === "" && trim((string)($course["course_name_ar"] ?? "")) === "") {
        addIssue(
            $issues,
            "High",
            "Missing Course Name",
            $courseLabel,
            "Both Arabic and English course names are missing.",
            "Edit the course and enter at least one course name."
        );
    }

    if ($creditHours <= 0 && trim((string)($course["course_activity"] ?? "")) !== "Internship") {
        addIssue(
            $issues,
            "Medium",
            "Missing Credit Hours",
            $courseLabel,
            "Credit hours are zero or missing.",
            "Check whether this course should have credit hours. Internship may be zero-credit if approved."
        );
    }

    if ($creditHours > 0 && abs($creditHours - $creditDistribution) > 0.01) {
        addIssue(
            $issues,
            "High",
            "Credit Distribution Mismatch",
            $courseLabel,
            "Course credit hours are " . $creditHours . ", but activity credit distribution totals " . $creditDistribution . ".",
            "Edit the course and correct the credit distribution fields."
        );
    }

    if ($contactDistribution <= 0) {
        addIssue(
            $issues,
            "Medium",
            "Missing Contact Hours",
            $courseLabel,
            "Contact hours distribution is empty or totals zero.",
            "Enter the contact hours distribution for theory, practical, training, project, or other activity."
        );
    }

    if (trim((string)($course["course_requirement_type"] ?? "")) === "") {
        addIssue(
            $issues,
            "Medium",
            "Missing Requirement Type",
            $courseLabel,
            "Course requirement type is missing.",
            "Select whether the course is university, college, program, elective, project, internship, or other."
        );
    }

    if (trim((string)($course["teaching_mode"] ?? "")) === "") {
        addIssue(
            $issues,
            "Low",
            "Missing Teaching Mode",
            $courseLabel,
            "Teaching mode is missing.",
            "Enter Face-to-face, Online, Blended, or other mode."
        );
    }

    if (trim((string)($course["course_activity"] ?? "")) === "") {
        addIssue(
            $issues,
            "Low",
            "Missing Activity",
            $courseLabel,
            "Course activity is missing.",
            "Enter Theory, Practical, Theory/Practical, Training, Graduation Project, or other."
        );
    }

    if (trim((string)($course["responsible_department"] ?? "")) === "") {
        addIssue(
            $issues,
            "Medium",
            "Missing Responsible Department",
            $courseLabel,
            "Responsible teaching department is missing.",
            "Enter the department or college responsible for teaching the course."
        );
    }

    if (trim((string)($course["course_description"] ?? "")) === "") {
        addIssue(
            $issues,
            "Low",
            "Missing Course Description",
            $courseLabel,
            "Short course description is missing.",
            "Add the short course description in the program language."
        );
    }

    if ($courseCode !== "") {
        $normalizedCode = strtoupper($courseCode);
        if (!isset($courseCodeCounts[$normalizedCode])) {
            $courseCodeCounts[$normalizedCode] = [];
        }
        $courseCodeCounts[$normalizedCode][] = $courseLabel;
    }
}

// Duplicate course codes
foreach ($courseCodeCounts as $code => $labels) {
    if (count($labels) > 1) {
        addIssue(
            $issues,
            "High",
            "Duplicate Course Code",
            $code,
            "This course code appears " . count($labels) . " times: " . implode(" | ", $labels),
            "Review duplicate course records. Delete duplicates or correct the course code."
        );
    }
}

// ===============================
// Issue counts
// ===============================
$totalIssues = count($issues);
$highIssues = 0;
$mediumIssues = 0;
$lowIssues = 0;

foreach ($issues as $issue) {
    if ($issue["severity"] === "High") {
        $highIssues++;
    } elseif ($issue["severity"] === "Medium") {
        $mediumIssues++;
    } elseif ($issue["severity"] === "Low") {
        $lowIssues++;
    }
}

$validationStatus = "Passed";

if ($highIssues > 0) {
    $validationStatus = "Failed";
} elseif ($mediumIssues > 0 || $lowIssues > 0) {
    $validationStatus = "Needs Review";
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Study Plan Validation</title>
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

        .status-pass {
            background: #d1e7dd;
            color: #0f5132;
        }

        .status-review {
            background: #fff3cd;
            color: #664d03;
        }

        .status-fail {
            background: #f8d7da;
            color: #842029;
        }

        .small-text {
            font-size: 12px;
            color: #555;
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
        <h1>Study Plan Validation</h1>
        <p>Check data completeness, credit consistency, duplicate codes, and readiness for program specification mapping.</p>
    </div>

    <div class="actions">
        <a href="study_plan_profile.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-secondary">
            Back to Study Plan Profile
        </a>

        <a href="manage_study_plan_courses.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-purple">
            Manage Courses
        </a>

        <a href="upload_study_plan_courses_csv.php?id=<?php echo safeText($study_plan_id); ?>" class="btn">
            Upload CSV
        </a>
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

            <tr>
                <th>Validation Status</th>
                <td colspan="3">
                    <?php
                    $statusClass = "status-pass";
                    if ($validationStatus === "Failed") {
                        $statusClass = "status-fail";
                    } elseif ($validationStatus === "Needs Review") {
                        $statusClass = "status-review";
                    }
                    ?>
                    <span class="badge <?php echo safeText($statusClass); ?>">
                        <?php echo safeText($validationStatus); ?>
                    </span>
                </td>
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
            <div class="summary-number"><?php echo safeText($declaredCredits); ?></div>
            <div class="summary-label">Declared Credits</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($calculatedCredits); ?></div>
            <div class="summary-label">Calculated Credits</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalIssues); ?></div>
            <div class="summary-label">Total Issues</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($highIssues); ?></div>
            <div class="summary-label">High Severity</div>
        </div>
    </div>

    <div class="panel">
        <h2>Validation Issues</h2>

        <table>
            <tr>
                <th>Severity</th>
                <th>Category</th>
                <th>Course / Area</th>
                <th>Issue</th>
                <th>Recommended Action</th>
            </tr>

            <?php if (count($issues) > 0): ?>
                <?php foreach ($issues as $issue): ?>
                    <tr>
                        <td>
                            <span class="badge <?php echo safeText(issueClass($issue["severity"])); ?>">
                                <?php echo safeText($issue["severity"]); ?>
                            </span>
                        </td>
                        <td><?php echo safeText($issue["category"]); ?></td>
                        <td><?php echo safeText($issue["course"]); ?></td>
                        <td><?php echo safeText($issue["message"]); ?></td>
                        <td><?php echo safeText($issue["action"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5">
                        No validation issues found. The study plan is ready for use in program specification and curriculum mapping.
                    </td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>