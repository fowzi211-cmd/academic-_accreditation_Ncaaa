<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

$program_specification_id = isset($_GET["program_specification_id"]) && is_numeric($_GET["program_specification_id"])
    ? (int)$_GET["program_specification_id"]
    : 3;

$error = "";
$success = "";
$results = [];

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

function addColumnIfMissing($conn, $tableName, $columnName, $definition) {
    if (!columnExists($conn, $tableName, $columnName)) {
        requireSchemaColumn($conn, $tableName, $columnName);
    }
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

function ensureDetailTables($conn) {
    requireSchemaTable($conn, "course_report_clo_results");

    addColumnIfMissing($conn, "course_report_clo_results", "course_report_id", "INT NOT NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "clo_code", "VARCHAR(50) NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "clo_text", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "target_score", "DECIMAL(6,2) NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "actual_score", "DECIMAL(6,2) NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "achievement_percentage", "DECIMAL(6,2) NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "status", "VARCHAR(100) NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "comments", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

    requireSchemaTable($conn, "course_report_exam_alignment");

    addColumnIfMissing($conn, "course_report_exam_alignment", "course_report_id", "INT NOT NULL");
    addColumnIfMissing($conn, "course_report_exam_alignment", "assessment_component", "VARCHAR(255) NULL");
    addColumnIfMissing($conn, "course_report_exam_alignment", "related_clo", "VARCHAR(255) NULL");
    addColumnIfMissing($conn, "course_report_exam_alignment", "max_score", "DECIMAL(8,2) NULL");
    addColumnIfMissing($conn, "course_report_exam_alignment", "average_score", "DECIMAL(8,2) NULL");
    addColumnIfMissing($conn, "course_report_exam_alignment", "alignment_notes", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_exam_alignment", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

    requireSchemaTable($conn, "course_report_topics_not_covered");

    addColumnIfMissing($conn, "course_report_topics_not_covered", "course_report_id", "INT NOT NULL");
    addColumnIfMissing($conn, "course_report_topics_not_covered", "topic_title", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_topics_not_covered", "reason_not_covered", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_topics_not_covered", "corrective_action", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_topics_not_covered", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

function rowExistsByField($conn, $tableName, $reportId, $fieldName, $fieldValue) {
    if (!tableExists($conn, $tableName)) {
        return false;
    }

    if (!columnExists($conn, $tableName, $fieldName)) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM `$tableName`
        WHERE course_report_id = :course_report_id
          AND `$fieldName` = :field_value
    ");
    $stmt->execute([
        ":course_report_id" => $reportId,
        ":field_value" => $fieldValue
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function countRowsByReport($conn, $tableName, $reportId) {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    if (!columnExists($conn, $tableName, "course_report_id")) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM `$tableName`
        WHERE course_report_id = :course_report_id
    ");
    $stmt->execute([":course_report_id" => $reportId]);

    return (int)$stmt->fetchColumn();
}

if (!tableExists($conn, "course_reports")) {
    die("course_reports table does not exist.");
}

if (!tableExists($conn, "course_specifications")) {
    die("course_specifications table does not exist.");
}

ensureDetailTables($conn);

/*
    Program specification.
*/
$programSpec = null;

if (tableExists($conn, "program_specifications")) {
    $stmt = $conn->prepare("
        SELECT *
        FROM program_specifications
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([":id" => $program_specification_id]);
    $programSpec = $stmt->fetch(PDO::FETCH_ASSOC);
}

/*
    Load linked reports.
*/
$stmt = $conn->prepare("
    SELECT
        cr.id AS report_id,
        cr.course_code,
        cr.course_title,
        cr.academic_year,
        cr.semester,
        cr.course_specification_id,
        cs.id AS spec_id,
        cs.course_code AS spec_course_code,
        cs.course_title AS spec_course_title
    FROM course_reports cr
    INNER JOIN course_specifications cs
        ON cs.id = cr.course_specification_id
    WHERE cs.program_specification_id = :program_specification_id
    ORDER BY cr.course_code ASC, cr.id ASC
");
$stmt->execute([":program_specification_id" => $program_specification_id]);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalReports = count($reports);

$totalExistingCloRows = 0;
$totalExistingAssessmentRows = 0;
$totalExistingTopicsCoveredRows = 0;

foreach ($reports as $report) {
    $reportId = (int)$report["report_id"];
    $totalExistingCloRows += countRowsByReport($conn, "course_report_clo_results", $reportId);
    $totalExistingAssessmentRows += countRowsByReport($conn, "course_report_exam_alignment", $reportId);
    $totalExistingTopicsCoveredRows += countRowsByReport($conn, "course_report_topics_not_covered", $reportId);
}

/*
    POST generation.
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $generateClos = isset($_POST["generate_clos"]);
    $generateAssessments = isset($_POST["generate_assessments"]);
    $markTopicsCovered = isset($_POST["mark_topics_covered"]);

    if (!$generateClos && !$generateAssessments && !$markTopicsCovered) {
        $error = "Select at least one generation option.";
    } else {
        try {
            $conn->beginTransaction();

            $createdCloRows = 0;
            $skippedCloRows = 0;
            $createdAssessmentRows = 0;
            $skippedAssessmentRows = 0;
            $createdTopicRows = 0;
            $skippedTopicRows = 0;

            $insertCloStmt = $conn->prepare("
                INSERT INTO course_report_clo_results
                (
                    course_report_id,
                    clo_code,
                    clo_text,
                    target_score,
                    actual_score,
                    achievement_percentage,
                    status,
                    comments
                )
                VALUES
                (
                    :course_report_id,
                    :clo_code,
                    :clo_text,
                    NULL,
                    NULL,
                    NULL,
                    'Pending',
                    'Generated from linked course specification.'
                )
            ");

            $insertAssessmentStmt = $conn->prepare("
                INSERT INTO course_report_exam_alignment
                (
                    course_report_id,
                    assessment_component,
                    related_clo,
                    max_score,
                    average_score,
                    alignment_notes
                )
                VALUES
                (
                    :course_report_id,
                    :assessment_component,
                    NULL,
                    NULL,
                    NULL,
                    'Generated from linked course specification assessment plan.'
                )
            ");

            $insertTopicCoveredStmt = $conn->prepare("
                INSERT INTO course_report_topics_not_covered
                (
                    course_report_id,
                    topic_title,
                    reason_not_covered,
                    corrective_action
                )
                VALUES
                (
                    :course_report_id,
                    'All planned topics were covered',
                    'Not applicable',
                    'No corrective action required'
                )
            ");

            foreach ($reports as $report) {
                $reportId = (int)$report["report_id"];
                $specId = (int)$report["spec_id"];
                $courseCode = $report["course_code"] ?? "";
                $courseTitle = $report["course_title"] ?? "";

                $reportResult = [
                    "report_id" => $reportId,
                    "course_code" => $courseCode,
                    "course_title" => $courseTitle,
                    "clo_created" => 0,
                    "clo_skipped" => 0,
                    "assessment_created" => 0,
                    "assessment_skipped" => 0,
                    "topic_created" => 0,
                    "topic_skipped" => 0
                ];

                if ($generateClos) {
                    $cloStmt = $conn->prepare("
                        SELECT clo_code, clo_text
                        FROM course_specification_clos
                        WHERE course_specification_id = :course_specification_id
                        ORDER BY display_order ASC, id ASC
                    ");
                    $cloStmt->execute([":course_specification_id" => $specId]);
                    $clos = $cloStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($clos as $clo) {
                        $cloCode = trim((string)($clo["clo_code"] ?? ""));
                        $cloText = trim((string)($clo["clo_text"] ?? ""));

                        if ($cloCode === "") {
                            continue;
                        }

                        if (rowExistsByField($conn, "course_report_clo_results", $reportId, "clo_code", $cloCode)) {
                            $skippedCloRows++;
                            $reportResult["clo_skipped"]++;
                            continue;
                        }

                        $insertCloStmt->execute([
                            ":course_report_id" => $reportId,
                            ":clo_code" => $cloCode,
                            ":clo_text" => $cloText
                        ]);

                        $createdCloRows++;
                        $reportResult["clo_created"]++;
                    }
                }

                if ($generateAssessments) {
                    $assessmentStmt = $conn->prepare("
                        SELECT assessment_activity
                        FROM course_specification_assessments
                        WHERE course_specification_id = :course_specification_id
                        ORDER BY display_order ASC, id ASC
                    ");
                    $assessmentStmt->execute([":course_specification_id" => $specId]);
                    $assessments = $assessmentStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($assessments as $assessment) {
                        $activity = trim((string)($assessment["assessment_activity"] ?? ""));

                        if ($activity === "") {
                            continue;
                        }

                        if (rowExistsByField($conn, "course_report_exam_alignment", $reportId, "assessment_component", $activity)) {
                            $skippedAssessmentRows++;
                            $reportResult["assessment_skipped"]++;
                            continue;
                        }

                        $insertAssessmentStmt->execute([
                            ":course_report_id" => $reportId,
                            ":assessment_component" => $activity
                        ]);

                        $createdAssessmentRows++;
                        $reportResult["assessment_created"]++;
                    }
                }

                if ($markTopicsCovered) {
                    if (rowExistsByField($conn, "course_report_topics_not_covered", $reportId, "topic_title", "All planned topics were covered")) {
                        $skippedTopicRows++;
                        $reportResult["topic_skipped"]++;
                    } else {
                        $insertTopicCoveredStmt->execute([
                            ":course_report_id" => $reportId
                        ]);

                        $createdTopicRows++;
                        $reportResult["topic_created"]++;
                    }
                }

                $results[] = $reportResult;
            }

            $conn->commit();

            logActivitySafe(
                $conn,
                "Bulk Generate",
                "Course Report Details",
                0,
                "Generated course report detail rows for program specification ID " . $program_specification_id
            );

            $success = "Bulk generation completed. CLO rows created: $createdCloRows, assessment rows created: $createdAssessmentRows, topics-covered rows created: $createdTopicRows.";

            /*
                Refresh summary after generation.
            */
            $totalExistingCloRows = 0;
            $totalExistingAssessmentRows = 0;
            $totalExistingTopicsCoveredRows = 0;

            foreach ($reports as $report) {
                $reportId = (int)$report["report_id"];
                $totalExistingCloRows += countRowsByReport($conn, "course_report_clo_results", $reportId);
                $totalExistingAssessmentRows += countRowsByReport($conn, "course_report_exam_alignment", $reportId);
                $totalExistingTopicsCoveredRows += countRowsByReport($conn, "course_report_topics_not_covered", $reportId);
            }

        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $error = "Bulk generation failed: " . $e->getMessage();
        }
    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Bulk Generate Course Report Details</title>
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

        .actions a,
        .actions button {
            display: inline-block;
            width: auto;
            text-align: center;
            margin-bottom: 0;
        }

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .alert-success {
            background: #d1e7dd;
            border: 1px solid #badbcc;
            color: #0f5132;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .option-box {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 12px;
        }

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .wide-table {
            min-width: 1100px;
            width: 100%;
            border-collapse: collapse;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        @media (max-width: 900px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(150px, 1fr));
            }
        }

        @media (max-width: 650px) {
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
        <h1>Bulk Generate Course Report Details</h1>
        <p>Generate CLO result rows, assessment alignment rows, and topics-covered rows for all linked course reports.</p>
    </div>

    <div class="actions">
        <a href="course_report_workflow.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Course Report Workflow
        </a>

        <a href="course_report_dashboard.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Course Report Dashboard
        </a>

        <a href="course_reports.php" class="btn btn-secondary">
            Course Reports
        </a>

        <a href="program_specification_dashboard.php?id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Program Spec Dashboard
        </a>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error"><?php echo safeText($error); ?></div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="alert-success"><?php echo safeText($success); ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Program</h2>

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

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalReports); ?></div>
            <div class="summary-label">Linked Course Reports</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalExistingCloRows); ?></div>
            <div class="summary-label">Existing CLO Result Rows</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalExistingAssessmentRows); ?></div>
            <div class="summary-label">Existing Assessment Rows</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalExistingTopicsCoveredRows); ?></div>
            <div class="summary-label">Topics Covered Rows</div>
        </div>
    </div>

    <div class="panel">
        <h2>Generation Options</h2>

        <form method="POST" action="bulk_generate_course_report_details.php?program_specification_id=<?php echo safeText($program_specification_id); ?>">

            <div class="option-box">
                <label>
                    <input type="checkbox" name="generate_clos" value="1" checked>
                    Generate CLO result rows from linked course specification CLOs
                </label>
                <div class="small-text">
                    Creates pending CLO achievement rows for every report. Existing CLO rows are skipped.
                </div>
            </div>

            <div class="option-box">
                <label>
                    <input type="checkbox" name="generate_assessments" value="1" checked>
                    Generate assessment alignment rows from linked course specification assessments
                </label>
                <div class="small-text">
                    Creates assessment/exam alignment rows from specification assessment plan. Existing rows are skipped.
                </div>
            </div>

            <div class="option-box">
                <label>
                    <input type="checkbox" name="mark_topics_covered" value="1" checked>
                    Mark all planned topics as covered
                </label>
                <div class="small-text">
                    Adds one row saying all planned topics were covered. You can delete or edit later if some topics were not covered.
                </div>
            </div>

            <button type="submit"
                    class="btn btn-purple"
                    onclick="return confirm('Generate detail rows for all linked course reports? Existing rows will be skipped.');">
                Generate Detail Rows
            </button>
        </form>
    </div>

    <?php if (count($results) > 0): ?>
        <div class="panel">
            <h2>Generation Results</h2>

            <div class="wide-table-wrapper">
                <table class="wide-table">
                    <tr>
                        <th>Report ID</th>
                        <th>Course Code</th>
                        <th>Course Title</th>
                        <th>CLO Created</th>
                        <th>CLO Skipped</th>
                        <th>Assessment Created</th>
                        <th>Assessment Skipped</th>
                        <th>Topic Row Created</th>
                        <th>Topic Row Skipped</th>
                        <th>Open</th>
                    </tr>

                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?php echo safeText($row["report_id"]); ?></td>
                            <td><strong><?php echo safeText($row["course_code"]); ?></strong></td>
                            <td><?php echo safeText($row["course_title"]); ?></td>
                            <td><?php echo safeText($row["clo_created"]); ?></td>
                            <td><?php echo safeText($row["clo_skipped"]); ?></td>
                            <td><?php echo safeText($row["assessment_created"]); ?></td>
                            <td><?php echo safeText($row["assessment_skipped"]); ?></td>
                            <td><?php echo safeText($row["topic_created"]); ?></td>
                            <td><?php echo safeText($row["topic_skipped"]); ?></td>
                            <td>
                                <a href="manage_course_report_details.php?id=<?php echo safeText($row["report_id"]); ?>" class="btn">
                                    Manage
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Next Step</h2>

        <div class="actions">
            <a href="course_report_dashboard.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
                Open Course Report Dashboard
            </a>

            <a href="export_course_report_dashboard_word.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
                Export Dashboard Word
            </a>
        </div>
    </div>

</div>

</body>
</html>
