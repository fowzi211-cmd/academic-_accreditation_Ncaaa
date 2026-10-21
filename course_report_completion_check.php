<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"]);

// ===============================
// Validate course report ID
// Accept both ?id= and ?report_id=
// ===============================
$report_id = 0;

if (isset($_GET["id"]) && is_numeric($_GET["id"])) {
    $report_id = (int)$_GET["id"];
} elseif (isset($_GET["report_id"]) && is_numeric($_GET["report_id"])) {
    $report_id = (int)$_GET["report_id"];
}

if ($report_id <= 0) {
    die("Invalid course report ID.");
}

// ===============================
// Helper functions
// ===============================
function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SHOW TABLES LIKE :table_name");
        $stmt->execute([":table_name" => $tableName]);
        return $stmt->fetch(PDO::FETCH_NUM) ? true : false;
    } catch (Exception $e) {
        return false;
    }
}

function columnExists($conn, $tableName, $columnName) {
    try {
        if (!tableExists($conn, $tableName)) {
            return false;
        }

        $stmt = $conn->prepare("SHOW COLUMNS FROM `$tableName` LIKE :column_name");
        $stmt->execute([":column_name" => $columnName]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ? true : false;
    } catch (Exception $e) {
        return false;
    }
}

function countRowsByReport($conn, $tableName, $report_id) {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    $fk = null;

    if (columnExists($conn, $tableName, "course_report_id")) {
        $fk = "course_report_id";
    } elseif (columnExists($conn, $tableName, "report_id")) {
        $fk = "report_id";
    }

    if ($fk === null) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM `$tableName`
        WHERE `$fk` = :report_id
    ");
    $stmt->execute([":report_id" => $report_id]);

    return (int)$stmt->fetchColumn();
}

function hasValue($row, $field) {
    return isset($row[$field]) && trim((string)$row[$field]) !== "";
}

function statusLabel($isComplete) {
    if ($isComplete) {
        return '<span class="status-ok">Complete</span>';
    }

    return '<span class="status-missing">Missing</span>';
}

// ===============================
// Load course report
// ===============================
$stmt = $conn->prepare("
    SELECT 
        cr.*,
        cs.id AS spec_id,
        cs.course_code AS spec_course_code,
        cs.course_title AS spec_course_title,
        cs.version_no AS spec_version,
        cs.status AS spec_status
    FROM course_reports cr
    LEFT JOIN course_specifications cs
        ON cs.id = cr.course_specification_id
    WHERE cr.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $report_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die("Course report not found.");
}

// ===============================
// Detail table counts
// ===============================
$gradeCount = countRowsByReport($conn, "course_report_grade_distribution", $report_id);
$cloCount = countRowsByReport($conn, "course_report_clo_results", $report_id);
$examAlignmentCount = countRowsByReport($conn, "course_report_exam_alignment", $report_id);
$topicsNotCoveredCount = countRowsByReport($conn, "course_report_topics_not_covered", $report_id);
$previousActionsCount = countRowsByReport($conn, "course_report_previous_improvement_actions", $report_id);
$improvementPlanCount = countRowsByReport($conn, "course_report_improvement_plan", $report_id);

// ===============================
// Completion checks
// ===============================
$checks = [];

$checks[] = [
    "section" => "Linked Course Specification",
    "requirement" => "Course Report is linked to a valid Course Specification.",
    "complete" => !empty($report["spec_id"]),
    "details" => !empty($report["spec_id"])
        ? (($report["spec_course_code"] ?? "") . " - " . ($report["spec_course_title"] ?? "") . " | Version: " . ($report["spec_version"] ?? ""))
        : "No valid Course Specification linked."
];

$checks[] = [
    "section" => "Course Identity",
    "requirement" => "Course code, title, academic year, semester, and coordinator are entered.",
    "complete" =>
        hasValue($report, "course_code") &&
        hasValue($report, "course_title") &&
        hasValue($report, "academic_year") &&
        hasValue($report, "semester") &&
        hasValue($report, "course_coordinator"),
    "details" => "Required identity fields checked."
];

$checks[] = [
    "section" => "Official Header Details",
    "requirement" => "Program, department, college, institution, location, sections, and report date are entered.",
    "complete" =>
        hasValue($report, "program_name") &&
        hasValue($report, "department_name") &&
        hasValue($report, "college_name") &&
        hasValue($report, "institution_name") &&
        hasValue($report, "location") &&
        hasValue($report, "number_of_sections") &&
        hasValue($report, "report_date"),
    "details" => "Header fields checked."
];

$checks[] = [
    "section" => "Student Results",
    "requirement" => "Student enrolled, passed, and failed numbers are entered.",
    "complete" =>
        isset($report["students_enrolled"]) &&
        (int)$report["students_enrolled"] > 0 &&
        isset($report["students_passed"]) &&
        isset($report["students_failed"]),
    "details" =>
        "Enrolled: " . ($report["students_enrolled"] ?? "0") .
        ", Passed: " . ($report["students_passed"] ?? "0") .
        ", Failed: " . ($report["students_failed"] ?? "0")
];

$checks[] = [
    "section" => "Grade Distribution",
    "requirement" => "At least one grade distribution row is added.",
    "complete" => $gradeCount > 0,
    "details" => $gradeCount . " row(s) found."
];

$checks[] = [
    "section" => "CLO Actual Achievement",
    "requirement" => "CLO achievement rows are added.",
    "complete" => $cloCount > 0,
    "details" => $cloCount . " row(s) found."
];

$checks[] = [
    "section" => "Final Exam Alignment",
    "requirement" => "Final exam alignment rows are added.",
    "complete" => $examAlignmentCount > 0,
    "details" => $examAlignmentCount . " row(s) found."
];

$checks[] = [
    "section" => "Topics Not Covered",
    "requirement" => "Topics not covered section is completed, even if all topics were covered.",
    "complete" => $topicsNotCoveredCount > 0,
    "details" => $topicsNotCoveredCount . " row(s) found."
];

$checks[] = [
    "section" => "Previous Improvement Actions",
    "requirement" => "Previous improvement actions are entered.",
    "complete" => $previousActionsCount > 0,
    "details" => $previousActionsCount . " row(s) found."
];

$checks[] = [
    "section" => "Student Feedback",
    "requirement" => "Student feedback summary is entered.",
    "complete" => hasValue($report, "student_feedback_summary"),
    "details" => hasValue($report, "student_feedback_summary")
        ? "Feedback summary found."
        : "Student feedback summary missing."
];

$checks[] = [
    "section" => "Course Improvement Plan",
    "requirement" => "Course improvement plan rows are added.",
    "complete" => $improvementPlanCount > 0,
    "details" => $improvementPlanCount . " row(s) found."
];

$checks[] = [
    "section" => "General Notes",
    "requirement" => "General notes are entered.",
    "complete" => hasValue($report, "general_notes"),
    "details" => hasValue($report, "general_notes")
        ? "General notes found."
        : "General notes missing."
];

// ===============================
// Summary calculations
// ===============================
$totalChecks = count($checks);
$completeChecks = 0;

foreach ($checks as $check) {
    if ($check["complete"]) {
        $completeChecks++;
    }
}

$missingChecks = $totalChecks - $completeChecks;
$completionPercent = $totalChecks > 0 ? round(($completeChecks / $totalChecks) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Report Completion Check</title>
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

        .summary-box {
            display: grid;
            grid-template-columns: repeat(3, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .summary-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
        }

        .summary-number {
            font-size: 28px;
            font-weight: bold;
            color: #007bff;
        }

        .summary-label {
            color: #555;
            font-size: 13px;
            margin-top: 6px;
        }

        .status-ok {
            color: #155724;
            background: #d4edda;
            padding: 5px 9px;
            border-radius: 999px;
            font-weight: bold;
            display: inline-block;
        }

        .status-missing {
            color: #721c24;
            background: #f8d7da;
            padding: 5px 9px;
            border-radius: 999px;
            font-weight: bold;
            display: inline-block;
        }

        .actions {
            margin-bottom: 18px;
        }

        .completion-good {
            color: #155724;
            background: #d4edda;
            padding: 5px 9px;
            border-radius: 999px;
            font-weight: bold;
            display: inline-block;
        }

        .completion-warning {
            color: #856404;
            background: #fff3cd;
            padding: 5px 9px;
            border-radius: 999px;
            font-weight: bold;
            display: inline-block;
        }

        .completion-danger {
            color: #721c24;
            background: #f8d7da;
            padding: 5px 9px;
            border-radius: 999px;
            font-weight: bold;
            display: inline-block;
        }

        @media (max-width: 800px) {
            .summary-box {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Course Report Completion Check</h1>
        <p>Check whether the Course Report has enough data for official review and Word export.</p>
    </div>

    <div class="actions">
        <a href="view_course_report.php?id=<?php echo safeText($report_id); ?>" class="btn btn-purple">View Report</a>
        <a href="manage_course_report_details.php?id=<?php echo safeText($report_id); ?>" class="btn">Manage Details</a>
        <a href="edit_course_report.php?id=<?php echo safeText($report_id); ?>" class="btn btn-edit">Edit Main Report</a>
        <a href="export_course_report_word.php?id=<?php echo safeText($report_id); ?>" class="btn">Export Word</a>
        <a href="course_report_followup_actions.php" class="btn btn-purple">Follow-up Actions</a>
        <a href="course_reports.php" class="btn btn-secondary">Back to Course Reports</a>
    </div>

    <div class="panel">
        <h2>
            <?php echo safeText($report["course_code"] ?? ""); ?>
            -
            <?php echo safeText($report["course_title"] ?? ""); ?>
        </h2>

        <table>
            <tr>
                <th>Academic Year</th>
                <td><?php echo safeText($report["academic_year"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Semester</th>
                <td><?php echo safeText($report["semester"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Coordinator</th>
                <td><?php echo safeText($report["course_coordinator"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Linked Specification</th>
                <td>
                    <?php if (!empty($report["spec_id"])): ?>
                        <?php echo safeText($report["spec_course_code"] ?? ""); ?>
                        -
                        <?php echo safeText($report["spec_course_title"] ?? ""); ?>
                        |
                        Version:
                        <?php echo safeText($report["spec_version"] ?? ""); ?>
                    <?php else: ?>
                        Not linked
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <div class="summary-box">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($completeChecks); ?></div>
            <div class="summary-label">Completed Sections</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($missingChecks); ?></div>
            <div class="summary-label">Missing Sections</div>
        </div>

        <div class="summary-card">
            <div class="summary-number">
                <?php echo safeText($completionPercent); ?>%
            </div>
            <div class="summary-label">Completion Rate</div>
        </div>
    </div>

    <div class="panel">
        <h2>Completion Checklist</h2>

        <table>
            <tr>
                <th>No.</th>
                <th>Section</th>
                <th>Requirement</th>
                <th>Status</th>
                <th>Details</th>
            </tr>

            <?php foreach ($checks as $index => $check): ?>
                <tr>
                    <td><?php echo safeText($index + 1); ?></td>
                    <td><?php echo safeText($check["section"]); ?></td>
                    <td><?php echo safeText($check["requirement"]); ?></td>
                    <td><?php echo statusLabel($check["complete"]); ?></td>
                    <td><?php echo safeText($check["details"]); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

</div>

</body>
</html>