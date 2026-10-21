<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"]);

$program_specification_id = isset($_GET["program_specification_id"]) && is_numeric($_GET["program_specification_id"])
    ? (int)$_GET["program_specification_id"]
    : 3;

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

function columnExists($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND column_name = :column_name
        ");
        $stmt->execute([
            ":table_name" => $tableName,
            ":column_name" => $columnName
        ]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function hasValue($row, $field) {
    return isset($row[$field]) && trim((string)$row[$field]) !== "";
}

function countRowsByReport($conn, $tableName, $reportId) {
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
    $stmt->execute([":report_id" => $reportId]);

    return (int)$stmt->fetchColumn();
}

function calcRate($part, $total) {
    $part = (int)$part;
    $total = (int)$total;

    if ($total <= 0) {
        return 0;
    }

    return round(($part / $total) * 100, 1);
}

function badgeClass($status) {
    if ($status === "Complete" || $status === "Linked" || $status === "Approved" || $status === "Good") {
        return "badge-ok";
    }

    if ($status === "Partial" || $status === "Needs Work" || $status === "Draft" || $status === "Under Review") {
        return "badge-warning";
    }

    return "badge-danger";
}

if (!tableExists($conn, "course_reports")) {
    die("course_reports table does not exist.");
}

/*
    Program specification identity.
*/
$programSpec = null;

if (tableExists($conn, "program_specifications")) {
    $programStmt = $conn->prepare("
        SELECT *
        FROM program_specifications
        WHERE id = :id
        LIMIT 1
    ");
    $programStmt->execute([":id" => $program_specification_id]);
    $programSpec = $programStmt->fetch(PDO::FETCH_ASSOC);
}

/*
    Load course reports with linked specification.
*/
$sql = "
    SELECT 
        cr.*,
        cs.id AS linked_spec_id,
        cs.course_code AS spec_course_code,
        cs.course_title AS spec_course_title,
        cs.version_no AS spec_version,
        cs.status AS spec_status,
        cs.approval_status AS spec_approval_status,
        cs.program_specification_id AS linked_program_specification_id
    FROM course_reports cr
    LEFT JOIN course_specifications cs
        ON cs.id = cr.course_specification_id
";

$params = [];

if (tableExists($conn, "course_specifications")) {
    /*
        Include reports linked to this program specification.
        Also include unlinked reports, because they need attention.
    */
    $sql .= "
        WHERE 
            cs.program_specification_id = :program_specification_id
            OR cr.course_specification_id IS NULL
    ";
    $params[":program_specification_id"] = $program_specification_id;
}

$sql .= " ORDER BY cr.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
    Build dashboard rows.
*/
$dashboardRows = [];

$totalReports = count($reports);
$linkedReports = 0;
$unlinkedReports = 0;
$brokenLinks = 0;

$completeReports = 0;
$partialReports = 0;
$weakReports = 0;

$totalStudentsEnrolled = 0;
$totalStudentsPassed = 0;
$totalStudentsFailed = 0;

$totalGradeRows = 0;
$totalCloResultRows = 0;
$totalExamAlignmentRows = 0;
$totalTopicsNotCoveredRows = 0;
$totalPreviousActionsRows = 0;
$totalImprovementPlanRows = 0;

$statusCounts = [];

foreach ($reports as $report) {
    $reportId = (int)($report["id"] ?? 0);

    $linkedSpecId = isset($report["linked_spec_id"]) && is_numeric($report["linked_spec_id"])
        ? (int)$report["linked_spec_id"]
        : 0;

    $courseSpecificationId = isset($report["course_specification_id"]) && is_numeric($report["course_specification_id"])
        ? (int)$report["course_specification_id"]
        : 0;

    $linkStatus = "Not Linked";

    if ($linkedSpecId > 0) {
        $linkStatus = "Linked";
        $linkedReports++;
    } elseif ($courseSpecificationId > 0 && $linkedSpecId === 0) {
        $linkStatus = "Broken Link";
        $brokenLinks++;
    } else {
        $unlinkedReports++;
    }

    $gradeCount = countRowsByReport($conn, "course_report_grade_distribution", $reportId);
    $cloCount = countRowsByReport($conn, "course_report_clo_results", $reportId);
    $examAlignmentCount = countRowsByReport($conn, "course_report_exam_alignment", $reportId);
    $topicsNotCoveredCount = countRowsByReport($conn, "course_report_topics_not_covered", $reportId);
    $previousActionsCount = countRowsByReport($conn, "course_report_previous_improvement_actions", $reportId);
    $improvementPlanCount = countRowsByReport($conn, "course_report_improvement_plan", $reportId);

    $totalGradeRows += $gradeCount;
    $totalCloResultRows += $cloCount;
    $totalExamAlignmentRows += $examAlignmentCount;
    $totalTopicsNotCoveredRows += $topicsNotCoveredCount;
    $totalPreviousActionsRows += $previousActionsCount;
    $totalImprovementPlanRows += $improvementPlanCount;

    $checks = [];

    $checks[] = $linkedSpecId > 0;
    $checks[] = hasValue($report, "course_code") && hasValue($report, "course_title");
    $checks[] = hasValue($report, "academic_year") && hasValue($report, "semester");
    $checks[] = hasValue($report, "course_coordinator");
    $checks[] = hasValue($report, "program_name") && hasValue($report, "department_name") && hasValue($report, "college_name") && hasValue($report, "institution_name");
    $checks[] = isset($report["students_enrolled"]) && (int)$report["students_enrolled"] > 0;
    $checks[] = $gradeCount > 0;
    $checks[] = $cloCount > 0;
    $checks[] = $examAlignmentCount > 0;
    $checks[] = $topicsNotCoveredCount > 0;
    $checks[] = $previousActionsCount > 0;
    $checks[] = hasValue($report, "student_feedback_summary");
    $checks[] = $improvementPlanCount > 0;
    $checks[] = hasValue($report, "general_notes");

    $completedChecks = 0;

    foreach ($checks as $check) {
        if ($check) {
            $completedChecks++;
        }
    }

    $totalChecks = count($checks);
    $completionPercent = $totalChecks > 0 ? round(($completedChecks / $totalChecks) * 100, 1) : 0;

    $completionStatus = "Weak";

    if ($completionPercent >= 85) {
        $completionStatus = "Complete";
        $completeReports++;
    } elseif ($completionPercent >= 50) {
        $completionStatus = "Partial";
        $partialReports++;
    } else {
        $weakReports++;
    }

    $studentsEnrolled = (int)($report["students_enrolled"] ?? 0);
    $studentsPassed = (int)($report["students_passed"] ?? 0);
    $studentsFailed = (int)($report["students_failed"] ?? 0);

    $totalStudentsEnrolled += $studentsEnrolled;
    $totalStudentsPassed += $studentsPassed;
    $totalStudentsFailed += $studentsFailed;

    $status = trim((string)($report["status"] ?? "Draft"));
    if ($status === "") {
        $status = "Draft";
    }

    if (!isset($statusCounts[$status])) {
        $statusCounts[$status] = 0;
    }
    $statusCounts[$status]++;

    $dashboardRows[] = [
        "report" => $report,
        "report_id" => $reportId,
        "link_status" => $linkStatus,
        "completion_status" => $completionStatus,
        "completed_checks" => $completedChecks,
        "total_checks" => $totalChecks,
        "completion_percent" => $completionPercent,
        "grade_count" => $gradeCount,
        "clo_count" => $cloCount,
        "exam_alignment_count" => $examAlignmentCount,
        "topics_not_covered_count" => $topicsNotCoveredCount,
        "previous_actions_count" => $previousActionsCount,
        "improvement_plan_count" => $improvementPlanCount,
        "pass_rate" => calcRate($studentsPassed, $studentsEnrolled)
    ];
}

$linkCoveragePercent = $totalReports > 0 ? round(($linkedReports / $totalReports) * 100, 1) : 0;
$completionCoveragePercent = $totalReports > 0 ? round(($completeReports / $totalReports) * 100, 1) : 0;
$overallPassRate = $totalStudentsEnrolled > 0 ? round(($totalStudentsPassed / $totalStudentsEnrolled) * 100, 1) : 0;

/*
    Course specification counts for comparison.
*/
$totalCourseSpecs = 0;

if (tableExists($conn, "course_specifications")) {
    $specCountStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM course_specifications
        WHERE program_specification_id = :program_specification_id
    ");
    $specCountStmt->execute([":program_specification_id" => $program_specification_id]);
    $totalCourseSpecs = (int)$specCountStmt->fetchColumn();
}

$missingReportsForSpecs = max($totalCourseSpecs - $linkedReports, 0);

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Report Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
        }

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
            font-size: 25px;
            font-weight: bold;
            color: #007bff;
        }

        .summary-label {
            color: #555;
            font-size: 13px;
            margin-top: 6px;
        }

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

        .progress-wrap {
            background: #e9ecef;
            border-radius: 999px;
            height: 24px;
            overflow: hidden;
            border: 1px solid #ccc;
            margin-bottom: 12px;
        }

        .progress-bar {
            height: 24px;
            background: #0d6efd;
            color: white;
            text-align: center;
            line-height: 24px;
            font-weight: bold;
            font-size: 12px;
        }

        .badge-ok {
            background: #d1e7dd;
            color: #0f5132;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }

        .badge-warning {
            background: #fff3cd;
            color: #664d03;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }

        .badge-danger {
            background: #f8d7da;
            color: #842029;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .wide-table {
            min-width: 1550px;
            width: 100%;
            border-collapse: collapse;
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
        <h1>Course Report Dashboard</h1>
        <p>Dashboard for course report linkage, completion status, student results, and missing detail sections.</p>
    </div>

    <div class="actions">
        <a href="course_reports.php" class="btn btn-secondary">
            Course Reports
        </a>

        <a href="create_course_report.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Add Course Report
        </a>

        <a href="bulk_create_course_reports_from_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Bulk Create Course Reports
        </a>

        <a href="link_course_reports_to_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Link Reports to Specs
        </a>

        <a href="export_course_report_specification_linkage_word.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Export Linkage Report
        </a>

        <a href="program_specification_dashboard.php?id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Program Spec Dashboard
        </a>
	<a href="export_course_report_dashboard_word.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
    Export Dashboard Word
</a>
	<a href="course_report_workflow.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
    Course Report Workflow
</a>
	<a href="bulk_generate_course_report_details.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
    Bulk Generate Details
</a>
    </div>

    <div class="panel">
        <h2>Program Specification</h2>

        <table>
            <tr>
                <th>Program Specification ID</th>
                <td><?php echo safeText($program_specification_id); ?></td>
                <th>Program</th>
                <td><?php echo safeText($programSpec["program_name"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Program Code</th>
                <td><?php echo safeText($programSpec["program_code"] ?? ""); ?></td>
                <th>College</th>
                <td><?php echo safeText($programSpec["college_name"] ?? ""); ?></td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Progress</h2>

        <p><strong>Course report linkage coverage</strong></p>
        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText(min($linkCoveragePercent, 100)); ?>%;">
                <?php echo safeText($linkCoveragePercent); ?>%
            </div>
        </div>

        <p><strong>Course report completion coverage</strong></p>
        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText(min($completionCoveragePercent, 100)); ?>%;">
                <?php echo safeText($completionCoveragePercent); ?>%
            </div>
        </div>

        <p><strong>Overall student pass rate</strong></p>
        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText(min($overallPassRate, 100)); ?>%;">
                <?php echo safeText($overallPassRate); ?>%
            </div>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalCourseSpecs); ?></div>
            <div class="summary-label">Course Specifications</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalReports); ?></div>
            <div class="summary-label">Course Reports</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($linkedReports); ?></div>
            <div class="summary-label">Linked Reports</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($unlinkedReports); ?></div>
            <div class="summary-label">Unlinked Reports</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($brokenLinks); ?></div>
            <div class="summary-label">Broken Links</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($missingReportsForSpecs); ?></div>
            <div class="summary-label">Missing Reports for Specs</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($completeReports); ?></div>
            <div class="summary-label">Complete Reports</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($partialReports); ?></div>
            <div class="summary-label">Partial Reports</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($weakReports); ?></div>
            <div class="summary-label">Weak Reports</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalStudentsEnrolled); ?></div>
            <div class="summary-label">Students Enrolled</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalStudentsPassed); ?></div>
            <div class="summary-label">Students Passed</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($overallPassRate); ?>%</div>
            <div class="summary-label">Overall Pass Rate</div>
        </div>
    </div>

    <div class="panel">
        <h2>Detail Table Coverage</h2>

        <table>
            <tr>
                <th>Grade Distribution Rows</th>
                <th>CLO Result Rows</th>
                <th>Exam Alignment Rows</th>
                <th>Topics Not Covered Rows</th>
                <th>Previous Actions Rows</th>
                <th>Improvement Plan Rows</th>
            </tr>
            <tr>
                <td><?php echo safeText($totalGradeRows); ?></td>
                <td><?php echo safeText($totalCloResultRows); ?></td>
                <td><?php echo safeText($totalExamAlignmentRows); ?></td>
                <td><?php echo safeText($totalTopicsNotCoveredRows); ?></td>
                <td><?php echo safeText($totalPreviousActionsRows); ?></td>
                <td><?php echo safeText($totalImprovementPlanRows); ?></td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Report Status Distribution</h2>

        <table>
            <tr>
                <th>Status</th>
                <th>Total Reports</th>
            </tr>

            <?php if (count($statusCounts) > 0): ?>
                <?php foreach ($statusCounts as $status => $count): ?>
                    <tr>
                        <td><?php echo safeText($status); ?></td>
                        <td><?php echo safeText($count); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="2">No course reports found.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Course Report Readiness Details</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>ID</th>
                    <th>Course</th>
                    <th>Linked Specification</th>
                    <th>Academic Year</th>
                    <th>Semester</th>
                    <th>Status</th>
                    <th>Students</th>
                    <th>Pass Rate</th>
                    <th>Grade Rows</th>
                    <th>CLO Rows</th>
                    <th>Exam Align.</th>
                    <th>Topics NC</th>
                    <th>Prev. Actions</th>
                    <th>Improve Plan</th>
                    <th>Completion</th>
                    <th>Actions</th>
                </tr>

                <?php if (count($dashboardRows) > 0): ?>
                    <?php foreach ($dashboardRows as $row): ?>
                        <?php
                        $report = $row["report"];
                        $linkStatus = $row["link_status"];
                        $completionStatus = $row["completion_status"];
                        ?>
                        <tr>
                            <td><?php echo safeText($row["report_id"]); ?></td>

                            <td>
                                <strong><?php echo safeText($report["course_code"] ?? ""); ?></strong><br>
                                <?php echo safeText($report["course_title"] ?? ""); ?>
                            </td>

                            <td>
                                <span class="<?php echo safeText(badgeClass($linkStatus)); ?>">
                                    <?php echo safeText($linkStatus); ?>
                                </span>

                                <?php if (!empty($report["linked_spec_id"])): ?>
                                    <br>
                                    <span class="small-text">
                                        Spec ID: <?php echo safeText($report["linked_spec_id"]); ?><br>
                                        <?php echo safeText($report["spec_course_code"] ?? ""); ?>
                                        -
                                        <?php echo safeText($report["spec_course_title"] ?? ""); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td><?php echo safeText($report["academic_year"] ?? ""); ?></td>
                            <td><?php echo safeText($report["semester"] ?? ""); ?></td>

                            <td>
                                <span class="<?php echo safeText(badgeClass($report["status"] ?? "Draft")); ?>">
                                    <?php echo safeText($report["status"] ?? "Draft"); ?>
                                </span>
                            </td>

                            <td>
                                Enrolled: <?php echo safeText($report["students_enrolled"] ?? "0"); ?><br>
                                Passed: <?php echo safeText($report["students_passed"] ?? "0"); ?><br>
                                Failed: <?php echo safeText($report["students_failed"] ?? "0"); ?>
                            </td>

                            <td><?php echo safeText($row["pass_rate"]); ?>%</td>
                            <td><?php echo safeText($row["grade_count"]); ?></td>
                            <td><?php echo safeText($row["clo_count"]); ?></td>
                            <td><?php echo safeText($row["exam_alignment_count"]); ?></td>
                            <td><?php echo safeText($row["topics_not_covered_count"]); ?></td>
                            <td><?php echo safeText($row["previous_actions_count"]); ?></td>
                            <td><?php echo safeText($row["improvement_plan_count"]); ?></td>

                            <td>
                                <span class="<?php echo safeText(badgeClass($completionStatus)); ?>">
                                    <?php echo safeText($completionStatus); ?>
                                </span>
                                <br>
                                <span class="small-text">
                                    <?php echo safeText($row["completed_checks"]); ?> /
                                    <?php echo safeText($row["total_checks"]); ?>
                                    |
                                    <?php echo safeText($row["completion_percent"]); ?>%
                                </span>
                            </td>

                            <td>
                                <a href="view_course_report.php?id=<?php echo safeText($row["report_id"]); ?>" class="btn btn-purple">
                                    View
                                </a>

                                <a href="course_report_completion_check.php?id=<?php echo safeText($row["report_id"]); ?>" class="btn">
                                    Check
                                </a>

                                <a href="manage_course_report_details.php?id=<?php echo safeText($row["report_id"]); ?>" class="btn">
                                    Details
                                </a>

                                <a href="export_course_report_word.php?id=<?php echo safeText($row["report_id"]); ?>" class="btn btn-purple">
                                    Export
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="16">No course reports found.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>