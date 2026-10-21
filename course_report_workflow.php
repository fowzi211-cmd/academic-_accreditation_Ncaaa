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

function hasValue($row, $field) {
    return isset($row[$field]) && trim((string)$row[$field]) !== "";
}

function badgeClass($status) {
    if ($status === "Complete" || $status === "Linked" || $status === "Available" || $status === "Started" || $status === "Ready") {
        return "badge-ok";
    }

    if ($status === "Partial" || $status === "Waiting" || $status === "Needs Work") {
        return "badge-warning";
    }

    return "badge-danger";
}

if (!tableExists($conn, "course_reports")) {
    die("course_reports table does not exist.");
}

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

$totalCourseSpecs = 0;

if (tableExists($conn, "course_specifications")) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM course_specifications
        WHERE program_specification_id = :program_specification_id
    ");
    $stmt->execute([":program_specification_id" => $program_specification_id]);
    $totalCourseSpecs = (int)$stmt->fetchColumn();
}

/*
    Load reports.
*/
$sql = "
    SELECT 
        cr.*,
        cs.id AS linked_spec_id,
        cs.course_code AS spec_course_code,
        cs.course_title AS spec_course_title,
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

$totalReports = count($reports);
$linkedReports = 0;
$unlinkedReports = 0;
$brokenLinks = 0;

$completeReports = 0;
$partialReports = 0;
$weakReports = 0;

$totalGradeRows = 0;
$totalCloRows = 0;
$totalExamAlignmentRows = 0;
$totalTopicsNotCoveredRows = 0;
$totalPreviousActionsRows = 0;
$totalImprovementPlanRows = 0;

foreach ($reports as $report) {
    $reportId = (int)($report["id"] ?? 0);

    $linkedSpecId = isset($report["linked_spec_id"]) && is_numeric($report["linked_spec_id"])
        ? (int)$report["linked_spec_id"]
        : 0;

    $courseSpecificationId = isset($report["course_specification_id"]) && is_numeric($report["course_specification_id"])
        ? (int)$report["course_specification_id"]
        : 0;

    if ($linkedSpecId > 0) {
        $linkedReports++;
    } elseif ($courseSpecificationId > 0 && $linkedSpecId === 0) {
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
    $totalCloRows += $cloCount;
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

    if ($completionPercent >= 85) {
        $completeReports++;
    } elseif ($completionPercent >= 50) {
        $partialReports++;
    } else {
        $weakReports++;
    }
}

$missingReportsForSpecs = max($totalCourseSpecs - $linkedReports, 0);

$linkCoveragePercent = $totalReports > 0 ? round(($linkedReports / $totalReports) * 100, 1) : 0;
$completionCoveragePercent = $totalReports > 0 ? round(($completeReports / $totalReports) * 100, 1) : 0;

$recommendedNextStep = "Bulk Create Course Reports";
$recommendedNextUrl = "bulk_create_course_reports_from_specifications.php?program_specification_id=" . $program_specification_id;

if ($totalReports === 0 || $missingReportsForSpecs > 0) {
    $recommendedNextStep = "Bulk Create Course Reports";
    $recommendedNextUrl = "bulk_create_course_reports_from_specifications.php?program_specification_id=" . $program_specification_id;
} elseif ($unlinkedReports > 0 || $brokenLinks > 0) {
    $recommendedNextStep = "Link Course Reports to Specifications";
    $recommendedNextUrl = "link_course_reports_to_specifications.php?program_specification_id=" . $program_specification_id;
} elseif ($weakReports > 0 || $partialReports > 0) {
    $recommendedNextStep = "Complete Course Report Details";
    $recommendedNextUrl = "course_report_dashboard.php?program_specification_id=" . $program_specification_id;
} elseif ($completeReports > 0) {
    $recommendedNextStep = "Export Course Report Dashboard";
    $recommendedNextUrl = "export_course_report_dashboard_word.php?program_specification_id=" . $program_specification_id;
}

$steps = [
    [
        "no" => 1,
        "title" => "Bulk Create Course Reports",
        "purpose" => "Create draft course reports from imported course specifications.",
        "status" => $totalReports > 0 ? "Started" : "Not Started",
        "url" => "bulk_create_course_reports_from_specifications.php?program_specification_id=" . $program_specification_id,
        "button" => "Create Reports"
    ],
    [
        "no" => 2,
        "title" => "Link Reports to Specifications",
        "purpose" => "Link course reports to imported course specifications using course code.",
        "status" => $linkedReports > 0 ? "Started" : "Waiting",
        "url" => "link_course_reports_to_specifications.php?program_specification_id=" . $program_specification_id,
        "button" => "Open Linkage"
    ],
    [
        "no" => 3,
        "title" => "Complete Report Details",
        "purpose" => "Enter grade distribution, CLO results, exam alignment, topics not covered, previous actions, and improvement plan.",
        "status" => ($totalGradeRows + $totalCloRows + $totalImprovementPlanRows) > 0 ? "Started" : "Waiting",
        "url" => "course_report_dashboard.php?program_specification_id=" . $program_specification_id,
        "button" => "Open Dashboard"
    ],
    [
        "no" => 4,
        "title" => "Run Completion Check",
        "purpose" => "Check whether each report is ready for review and export.",
        "status" => $completeReports > 0 ? "Started" : "Waiting",
        "url" => "course_report_dashboard.php?program_specification_id=" . $program_specification_id,
        "button" => "Check Reports"
    ],
    [
        "no" => 5,
        "title" => "Export Dashboard Word",
        "purpose" => "Export the course report dashboard as an accreditation-ready Word report.",
        "status" => $totalReports > 0 ? "Available" : "Waiting",
        "url" => "export_course_report_dashboard_word.php?program_specification_id=" . $program_specification_id,
        "button" => "Export Dashboard"
    ],
    [
        "no" => 6,
        "title" => "Review Individual Reports",
        "purpose" => "Open individual course reports and export their full Word report with linked specification data.",
        "status" => $linkedReports > 0 ? "Available" : "Waiting",
        "url" => "course_reports.php",
        "button" => "Open Reports"
    ]
];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Report Workflow</title>
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

        .workflow-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(260px, 1fr));
            gap: 16px;
        }

        .step-card {
            background: #ffffff;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 16px;
        }

        .step-number {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            background: #0d6efd;
            color: white;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            font-weight: bold;
            margin-right: 8px;
        }

        .step-title {
            font-size: 18px;
            font-weight: bold;
            color: #1f4e79;
            margin-bottom: 8px;
        }

        .step-purpose {
            color: #555;
            font-size: 14px;
            min-height: 52px;
            margin-bottom: 12px;
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

        @media (max-width: 1100px) {
            .summary-grid,
            .workflow-grid {
                grid-template-columns: repeat(2, minmax(130px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .summary-grid,
            .workflow-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Course Report Workflow</h1>
        <p>Guided workflow for creating, linking, completing, checking, and exporting course reports.</p>
    </div>

    <div class="actions">
        <a href="program_specification_dashboard.php?id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Program Spec Dashboard
        </a>

        <a href="course_reports.php" class="btn">
            Course Reports
        </a>

        <a href="course_report_dashboard.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Course Report Dashboard
        </a>

        <a href="course_specification_workflow.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Course Spec Workflow
        </a>
	<a href="bulk_generate_course_report_details.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
    Bulk Generate Report Details
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
        <h2>Recommended Next Step</h2>

        <p><strong><?php echo safeText($recommendedNextStep); ?></strong></p>

        <a href="<?php echo safeText($recommendedNextUrl); ?>" class="btn btn-purple">
            Continue Workflow
        </a>
    </div>

    <div class="panel">
        <h2>Workflow Progress</h2>

        <p><strong>Linkage coverage</strong></p>
        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText(min($linkCoveragePercent, 100)); ?>%;">
                <?php echo safeText($linkCoveragePercent); ?>%
            </div>
        </div>

        <p><strong>Completion coverage</strong></p>
        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText(min($completionCoveragePercent, 100)); ?>%;">
                <?php echo safeText($completionCoveragePercent); ?>%
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
            <div class="summary-number"><?php echo safeText($missingReportsForSpecs); ?></div>
            <div class="summary-label">Missing Reports</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($completeReports); ?></div>
            <div class="summary-label">Complete Reports</div>
        </div>
    </div>

    <div class="panel">
        <h2>Workflow Steps</h2>

        <div class="workflow-grid">
            <?php foreach ($steps as $step): ?>
                <div class="step-card">
                    <div class="step-title">
                        <span class="step-number"><?php echo safeText($step["no"]); ?></span>
                        <?php echo safeText($step["title"]); ?>
                    </div>

                    <div class="step-purpose">
                        <?php echo safeText($step["purpose"]); ?>
                    </div>

                    <p>
                        <span class="<?php echo safeText(badgeClass($step["status"])); ?>">
                            <?php echo safeText($step["status"]); ?>
                        </span>
                    </p>

                    <a href="<?php echo safeText($step["url"]); ?>" class="btn btn-purple">
                        <?php echo safeText($step["button"]); ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="panel">
        <h2>Main Actions</h2>

        <div class="actions">
            <a href="bulk_create_course_reports_from_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
                Bulk Create Course Reports
            </a>

            <a href="link_course_reports_to_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
                Link Reports to Specs
            </a>

            <a href="course_report_dashboard.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
                Course Report Dashboard
            </a>

            <a href="export_course_report_dashboard_word.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
                Export Dashboard Word
            </a>
        </div>
    </div>

</div>

</body>
</html>