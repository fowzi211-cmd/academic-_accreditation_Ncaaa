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

function wordText($text) {
    return nl2br(htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8"));
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

function statusClass($status) {
    if ($status === "Complete" || $status === "Linked" || $status === "Approved" || $status === "Good") {
        return "ok";
    }

    if ($status === "Partial" || $status === "Needs Work" || $status === "Draft" || $status === "Under Review") {
        return "warning";
    }

    return "danger";
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
    $sql .= "
        WHERE 
            cs.program_specification_id = :program_specification_id
            OR cr.course_specification_id IS NULL
    ";
    $params[":program_specification_id"] = $program_specification_id;
}

$sql .= " ORDER BY cr.course_code ASC, cr.id ASC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
    Dashboard calculations.
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

$programCode = $programSpec["program_code"] ?? "program";
$programCode = preg_replace('/[^A-Za-z0-9_\-]/', '_', $programCode);

$filename = "course_report_dashboard_" . $programCode . "_" . date("Y-m-d_H-i-s") . ".doc";

header("Content-Type: application/msword; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Report Dashboard Report</title>

    <style>
        body {
            font-family: Arial, Tahoma, sans-serif;
            font-size: 11pt;
            line-height: 1.45;
            color: #111;
        }

        h1 {
            text-align: center;
            font-size: 22pt;
            margin-bottom: 6px;
        }

        h2 {
            background: #d9eaf7;
            border: 1px solid #9dbbd4;
            padding: 8px;
            font-size: 15pt;
            margin-top: 24px;
        }

        h3 {
            font-size: 13pt;
            color: #1f4e79;
            margin-top: 16px;
            margin-bottom: 6px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 14px;
        }

        th {
            background: #f2f2f2;
            font-weight: bold;
        }

        th, td {
            border: 1px solid #555;
            padding: 6px;
            vertical-align: top;
        }

        .center {
            text-align: center;
        }

        .cover {
            text-align: center;
            margin-top: 70px;
            margin-bottom: 80px;
        }

        .cover h1 {
            font-size: 24pt;
        }

        .cover p {
            font-size: 14pt;
        }

        .ok {
            background: #d1e7dd;
            color: #0f5132;
            font-weight: bold;
        }

        .warning {
            background: #fff3cd;
            color: #664d03;
            font-weight: bold;
        }

        .danger {
            background: #f8d7da;
            color: #842029;
            font-weight: bold;
        }

        .small {
            font-size: 9pt;
        }
    </style>
</head>

<body>

<div class="cover">
    <h1>Course Report Dashboard Report</h1>
    <p><strong><?php echo safeText($programSpec["program_name"] ?? ""); ?></strong></p>
    <p><?php echo safeText($programSpec["college_name"] ?? ""); ?></p>
    <p><?php echo safeText($programSpec["institution_name"] ?? ""); ?></p>
    <p>Generated on: <?php echo date("Y-m-d H:i"); ?></p>
</div>

<h2>1. Program Specification Identity</h2>

<table>
    <tr>
        <th>Program Specification ID</th>
        <td><?php echo safeText($program_specification_id); ?></td>
        <th>Program Code</th>
        <td><?php echo safeText($programSpec["program_code"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Program Name</th>
        <td colspan="3"><?php echo safeText($programSpec["program_name"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Department</th>
        <td><?php echo safeText($programSpec["department_name"] ?? ""); ?></td>
        <th>College</th>
        <td><?php echo safeText($programSpec["college_name"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Institution</th>
        <td><?php echo safeText($programSpec["institution_name"] ?? ""); ?></td>
        <th>Qualification Level</th>
        <td><?php echo safeText($programSpec["qualification_level"] ?? ""); ?></td>
    </tr>
</table>

<h2>2. Executive Summary</h2>

<table>
    <tr>
        <th>Course Specifications</th>
        <th>Course Reports</th>
        <th>Linked Reports</th>
        <th>Unlinked Reports</th>
        <th>Broken Links</th>
        <th>Missing Reports for Specs</th>
    </tr>
    <tr>
        <td class="center"><?php echo safeText($totalCourseSpecs); ?></td>
        <td class="center"><?php echo safeText($totalReports); ?></td>
        <td class="center ok"><?php echo safeText($linkedReports); ?></td>
        <td class="center danger"><?php echo safeText($unlinkedReports); ?></td>
        <td class="center danger"><?php echo safeText($brokenLinks); ?></td>
        <td class="center warning"><?php echo safeText($missingReportsForSpecs); ?></td>
    </tr>
</table>

<table>
    <tr>
        <th>Complete Reports</th>
        <th>Partial Reports</th>
        <th>Weak Reports</th>
        <th>Link Coverage</th>
        <th>Completion Coverage</th>
        <th>Overall Student Pass Rate</th>
    </tr>
    <tr>
        <td class="center ok"><?php echo safeText($completeReports); ?></td>
        <td class="center warning"><?php echo safeText($partialReports); ?></td>
        <td class="center danger"><?php echo safeText($weakReports); ?></td>
        <td class="center"><?php echo safeText($linkCoveragePercent); ?>%</td>
        <td class="center"><?php echo safeText($completionCoveragePercent); ?>%</td>
        <td class="center"><?php echo safeText($overallPassRate); ?>%</td>
    </tr>
</table>

<h2>3. Student Results Summary</h2>

<table>
    <tr>
        <th>Total Enrolled</th>
        <th>Total Passed</th>
        <th>Total Failed</th>
        <th>Overall Pass Rate</th>
    </tr>
    <tr>
        <td class="center"><?php echo safeText($totalStudentsEnrolled); ?></td>
        <td class="center ok"><?php echo safeText($totalStudentsPassed); ?></td>
        <td class="center danger"><?php echo safeText($totalStudentsFailed); ?></td>
        <td class="center"><?php echo safeText($overallPassRate); ?>%</td>
    </tr>
</table>

<h2>4. Course Report Detail Table Coverage</h2>

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
        <td class="center"><?php echo safeText($totalGradeRows); ?></td>
        <td class="center"><?php echo safeText($totalCloResultRows); ?></td>
        <td class="center"><?php echo safeText($totalExamAlignmentRows); ?></td>
        <td class="center"><?php echo safeText($totalTopicsNotCoveredRows); ?></td>
        <td class="center"><?php echo safeText($totalPreviousActionsRows); ?></td>
        <td class="center"><?php echo safeText($totalImprovementPlanRows); ?></td>
    </tr>
</table>

<h2>5. Report Status Distribution</h2>

<table>
    <tr>
        <th>Status</th>
        <th>Total Reports</th>
    </tr>

    <?php if (count($statusCounts) > 0): ?>
        <?php foreach ($statusCounts as $status => $count): ?>
            <tr>
                <td><?php echo safeText($status); ?></td>
                <td class="center"><?php echo safeText($count); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="2">No course reports found.</td>
        </tr>
    <?php endif; ?>
</table>

<h2>6. Detailed Course Report Readiness Table</h2>

<table>
    <tr>
        <th>No.</th>
        <th>Report ID</th>
        <th>Course Code</th>
        <th>Course Title</th>
        <th>Linked Specification</th>
        <th>Academic Year</th>
        <th>Semester</th>
        <th>Report Status</th>
        <th>Students Enrolled</th>
        <th>Passed</th>
        <th>Failed</th>
        <th>Pass Rate</th>
        <th>Grade Rows</th>
        <th>CLO Rows</th>
        <th>Exam Align.</th>
        <th>Topics NC</th>
        <th>Prev. Actions</th>
        <th>Improve Plan</th>
        <th>Completion</th>
    </tr>

    <?php if (count($dashboardRows) > 0): ?>
        <?php $i = 1; ?>
        <?php foreach ($dashboardRows as $row): ?>
            <?php
            $report = $row["report"];
            $linkStatus = $row["link_status"];
            $completionStatus = $row["completion_status"];
            ?>
            <tr>
                <td class="center"><?php echo safeText($i); ?></td>
                <td class="center"><?php echo safeText($row["report_id"]); ?></td>
                <td><strong><?php echo safeText($report["course_code"] ?? ""); ?></strong></td>
                <td><?php echo safeText($report["course_title"] ?? ""); ?></td>
                <td class="<?php echo safeText(statusClass($linkStatus)); ?>">
                    <?php echo safeText($linkStatus); ?>
                    <?php if (!empty($report["linked_spec_id"])): ?>
                        <br>
                        <span class="small">
                            Spec ID: <?php echo safeText($report["linked_spec_id"]); ?><br>
                            <?php echo safeText($report["spec_course_code"] ?? ""); ?>
                            -
                            <?php echo safeText($report["spec_course_title"] ?? ""); ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td><?php echo safeText($report["academic_year"] ?? ""); ?></td>
                <td><?php echo safeText($report["semester"] ?? ""); ?></td>
                <td><?php echo safeText($report["status"] ?? "Draft"); ?></td>
                <td class="center"><?php echo safeText($report["students_enrolled"] ?? "0"); ?></td>
                <td class="center"><?php echo safeText($report["students_passed"] ?? "0"); ?></td>
                <td class="center"><?php echo safeText($report["students_failed"] ?? "0"); ?></td>
                <td class="center"><?php echo safeText($row["pass_rate"]); ?>%</td>
                <td class="center"><?php echo safeText($row["grade_count"]); ?></td>
                <td class="center"><?php echo safeText($row["clo_count"]); ?></td>
                <td class="center"><?php echo safeText($row["exam_alignment_count"]); ?></td>
                <td class="center"><?php echo safeText($row["topics_not_covered_count"]); ?></td>
                <td class="center"><?php echo safeText($row["previous_actions_count"]); ?></td>
                <td class="center"><?php echo safeText($row["improvement_plan_count"]); ?></td>
                <td class="<?php echo safeText(statusClass($completionStatus)); ?>">
                    <?php echo safeText($completionStatus); ?><br>
                    <?php echo safeText($row["completed_checks"]); ?> /
                    <?php echo safeText($row["total_checks"]); ?>
                    |
                    <?php echo safeText($row["completion_percent"]); ?>%
                </td>
            </tr>
            <?php $i++; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="19" class="danger">No course reports found.</td>
        </tr>
    <?php endif; ?>
</table>

<h2>7. Interpretation and Recommended Actions</h2>

<table>
    <tr>
        <th>Area</th>
        <th>Interpretation</th>
        <th>Recommended Action</th>
    </tr>
    <tr>
        <td>Report Linkage</td>
        <td><?php echo safeText($linkCoveragePercent); ?>% of course reports are linked to course specifications.</td>
        <td>Use the linkage page to resolve unlinked or broken reports.</td>
    </tr>
    <tr>
        <td>Missing Reports</td>
        <td><?php echo safeText($missingReportsForSpecs); ?> course specification(s) do not yet have linked course reports.</td>
        <td>Use Bulk Create Course Reports from Specifications.</td>
    </tr>
    <tr>
        <td>Report Completion</td>
        <td><?php echo safeText($completionCoveragePercent); ?>% of course reports are complete according to the checklist.</td>
        <td>Open weak/partial reports and complete grade distribution, CLO results, exam alignment, topics not covered, previous actions, feedback, and improvement plan.</td>
    </tr>
    <tr>
        <td>Student Results</td>
        <td>Overall pass rate is <?php echo safeText($overallPassRate); ?>% based on entered student data.</td>
        <td>Check reports with missing or zero student numbers before final reporting.</td>
    </tr>
</table>

</body>
</html>