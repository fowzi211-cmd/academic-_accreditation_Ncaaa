<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid course report ID.");
}

$course_report_id = (int)$_GET["id"];
requireCourseReportAccess($conn, $course_report_id);

function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function nlText($text) {
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

function getValue($row, $fields, $default = "") {
    foreach ($fields as $field) {
        if (isset($row[$field]) && trim((string)$row[$field]) !== "") {
            return $row[$field];
        }
    }

    return $default;
}

function loadRows($conn, $tableName, $course_specification_id, $orderBy = "id ASC") {
    if (!tableExists($conn, $tableName)) {
        return [];
    }

    if (!columnExists($conn, $tableName, "course_specification_id")) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM $tableName
        WHERE course_specification_id = :id
        ORDER BY $orderBy
    ");
    $stmt->execute([":id" => $course_specification_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function countRows($conn, $tableName, $course_specification_id) {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    if (!columnExists($conn, $tableName, "course_specification_id")) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM $tableName
        WHERE course_specification_id = :id
    ");
    $stmt->execute([":id" => $course_specification_id]);

    return (int)$stmt->fetchColumn();
}

function sumColumn($conn, $tableName, $columnName, $course_specification_id) {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    if (!columnExists($conn, $tableName, "course_specification_id")) {
        return 0;
    }

    if (!columnExists($conn, $tableName, $columnName)) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM($columnName), 0)
        FROM $tableName
        WHERE course_specification_id = :id
    ");
    $stmt->execute([":id" => $course_specification_id]);

    return (float)$stmt->fetchColumn();
}

if (!tableExists($conn, "course_reports")) {
    die("course_reports table does not exist.");
}

/*
    Load course report.
*/
$stmt = $conn->prepare("
    SELECT *
    FROM course_reports
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $course_report_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die("Course report not found.");
}

$course_specification_id = 0;

if (isset($report["course_specification_id"]) && is_numeric($report["course_specification_id"])) {
    $course_specification_id = (int)$report["course_specification_id"];
}

/*
    Load linked course specification.
*/
$courseSpec = null;

if ($course_specification_id > 0 && tableExists($conn, "course_specifications")) {
    $specStmt = $conn->prepare("
        SELECT *
        FROM course_specifications
        WHERE id = :id
        LIMIT 1
    ");
    $specStmt->execute([":id" => $course_specification_id]);
    $courseSpec = $specStmt->fetch(PDO::FETCH_ASSOC);
}

$program_specification_id = 3;

if ($courseSpec && isset($courseSpec["program_specification_id"]) && is_numeric($courseSpec["program_specification_id"])) {
    $program_specification_id = (int)$courseSpec["program_specification_id"];
} elseif (isset($report["program_specification_id"]) && is_numeric($report["program_specification_id"])) {
    $program_specification_id = (int)$report["program_specification_id"];
}

/*
    Pull course specification child data.
*/
$clos = [];
$topics = [];
$assessments = [];
$resources = [];
$facilities = [];
$qualityRows = [];
$approvalRows = [];
$specFiles = [];

$totalTopicHours = 0;
$totalAssessmentPercentage = 0;

if ($courseSpec) {
    $clos = loadRows($conn, "course_specification_clos", $course_specification_id, "display_order ASC, id ASC");
    $topics = loadRows($conn, "course_specification_topics", $course_specification_id, "display_order ASC, topic_no ASC, id ASC");
    $assessments = loadRows($conn, "course_specification_assessments", $course_specification_id, "display_order ASC, assessment_no ASC, id ASC");
    $resources = loadRows($conn, "course_specification_resources", $course_specification_id, "display_order ASC, id ASC");
    $facilities = loadRows($conn, "course_specification_facilities", $course_specification_id, "display_order ASC, id ASC");
    $qualityRows = loadRows($conn, "course_specification_quality", $course_specification_id, "display_order ASC, id ASC");
    $approvalRows = loadRows($conn, "course_specification_approval", $course_specification_id, "id ASC");

    $totalTopicHours = sumColumn($conn, "course_specification_topics", "contact_hours", $course_specification_id);
    $totalAssessmentPercentage = sumColumn($conn, "course_specification_assessments", "percentage", $course_specification_id);

    if (tableExists($conn, "course_specification_files")) {
        $fileStmt = $conn->prepare("
            SELECT *
            FROM course_specification_files
            WHERE course_specification_id = :course_specification_id
            ORDER BY upload_date DESC, id DESC
        ");
        $fileStmt->execute([":course_specification_id" => $course_specification_id]);
        $specFiles = $fileStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/*
    Report display values.
*/
$reportCourseCode = getValue($report, ["course_code", "code"]);
$reportCourseTitle = getValue($report, ["course_title", "title", "report_title", "course_name", "name"]);
$reportAcademicYear = getValue($report, ["academic_year", "year", "report_year"]);
$reportSemester = getValue($report, ["semester", "term", "report_semester"]);
$reportStatus = getValue($report, ["status", "report_status"]);
$reportCreatedAt = getValue($report, ["created_at", "date_created"]);
$reportUpdatedAt = getValue($report, ["updated_at", "modified_at"]);

$linkedStatusText = $courseSpec ? "Linked" : "Not Linked";
$linkedStatusClass = $courseSpec ? "badge-ok" : "badge-danger";

$assessmentOk = abs($totalAssessmentPercentage - 100) <= 0.01;

$specReadiness = "Not Ready";
$specReadinessClass = "badge-danger";

if ($courseSpec) {
    if (
        count($clos) > 0 &&
        count($topics) > 0 &&
        count($assessments) > 0 &&
        $assessmentOk &&
        count($resources) > 0 &&
        count($facilities) > 0 &&
        count($qualityRows) > 0 &&
        count($approvalRows) > 0
    ) {
        $specReadiness = "Complete";
        $specReadinessClass = "badge-ok";
    } elseif (
        count($clos) > 0 &&
        count($topics) > 0 &&
        count($assessments) > 0
    ) {
        $specReadiness = "Core Imported";
        $specReadinessClass = "badge-warning";
    }
}

/*
    Optional report fields to display if they exist.
*/
$optionalReportSections = [
    "Course Report Notes" => ["notes", "report_notes"],
    "Teaching Effectiveness" => ["teaching_effectiveness", "teaching_evaluation", "teaching_notes"],
    "Assessment Analysis" => ["assessment_analysis", "assessment_notes", "exam_analysis"],
    "Student Results Summary" => ["student_results_summary", "results_summary", "student_performance"],
    "Course Improvement Plan" => ["improvement_plan", "action_plan", "course_improvement_plan"],
    "Recommendations" => ["recommendations", "report_recommendations"],
    "Challenges" => ["challenges", "difficulties"],
    "Corrective Actions" => ["corrective_actions", "actions_taken"]
];

$displayOptionalSections = [];

foreach ($optionalReportSections as $label => $fields) {
    $value = getValue($report, $fields);

    if (trim((string)$value) !== "") {
        $displayOptionalSections[] = [
            "label" => $label,
            "value" => $value
        ];
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>View Course Report</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(140px, 1fr));
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

        .section-title {
            background: #d9eaf7;
            border: 1px solid #9dbbd4;
            padding: 10px;
            font-size: 18px;
            font-weight: bold;
            margin-top: 22px;
            margin-bottom: 12px;
            color: #1f4e79;
        }

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .wide-table {
            min-width: 1200px;
            width: 100%;
            border-collapse: collapse;
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

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .ok-text {
            color: #0f5132;
            font-weight: bold;
        }

        .bad-text {
            color: #842029;
            font-weight: bold;
        }

        @media (max-width: 1100px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(140px, 1fr));
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
        <h1>View Course Report</h1>
        <p>Course report with linked course specification source data.</p>
    </div>

    <div class="actions">
        <a href="course_reports.php" class="btn btn-secondary">
            Course Reports
        </a>

        <?php if (tableExists($conn, "course_reports")): ?>
            <a href="edit_course_report.php?id=<?php echo safeText($course_report_id); ?>" class="btn btn-edit">
                Edit Report
            </a>
        <?php endif; ?>

        <a href="export_course_report_word.php?id=<?php echo safeText($course_report_id); ?>" class="btn btn-purple">
            Export Course Report
        </a>

        <a href="link_course_reports_to_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Link Reports to Specs
        </a>

        <?php if ($courseSpec): ?>
            <a href="course_specification_profile.php?id=<?php echo safeText($course_specification_id); ?>" class="btn">
                Linked Spec Profile
            </a>

            <a href="export_course_specification_word.php?id=<?php echo safeText($course_specification_id); ?>" class="btn btn-purple">
                Export Linked Spec
            </a>
		<a href="import_course_report_student_results.php?id=<?php echo safeText($course_report_id); ?>" class="btn btn-purple">
    Import Student Results
</a>
		<a href="manage_course_report_details.php?id=<?php echo safeText($course_report_id); ?>" class="btn btn-purple">
    Manage Report Details
</a>

<a href="import_course_report_student_results.php?id=<?php echo safeText($course_report_id); ?>" class="btn btn-purple">
    Import Student Results
</a>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Course Report Identity</h2>

        <table>
            <tr>
                <th>Course Report ID</th>
                <td><?php echo safeText($course_report_id); ?></td>
                <th>Linked Specification Status</th>
                <td>
                    <span class="<?php echo safeText($linkedStatusClass); ?>">
                        <?php echo safeText($linkedStatusText); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>Course Code</th>
                <td><?php echo safeText($reportCourseCode); ?></td>
                <th>Course Title</th>
                <td><?php echo safeText($reportCourseTitle); ?></td>
            </tr>
            <tr>
                <th>Academic Year</th>
                <td><?php echo safeText($reportAcademicYear); ?></td>
                <th>Semester</th>
                <td><?php echo safeText($reportSemester); ?></td>
            </tr>
            <tr>
                <th>Report Status</th>
                <td><?php echo safeText($reportStatus); ?></td>
                <th>Course Specification ID</th>
                <td><?php echo $course_specification_id > 0 ? safeText($course_specification_id) : "Not linked"; ?></td>
            </tr>
            <tr>
                <th>Created At</th>
                <td><?php echo safeText($reportCreatedAt); ?></td>
                <th>Updated At</th>
                <td><?php echo safeText($reportUpdatedAt); ?></td>
            </tr>
        </table>
    </div>

    <?php if (!$courseSpec): ?>
        <div class="panel">
            <h2>Linked Course Specification</h2>
            <p>
                <span class="badge-danger">Not linked</span>
            </p>
            <p>
                This course report is not linked to a course specification yet. Use the linking page to match reports by course code.
            </p>

            <a href="link_course_reports_to_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
                Link Course Report
            </a>
        </div>
    <?php else: ?>

        <div class="section-title">Linked Course Specification Source Data</div>

        <div class="panel">
            <h2>Specification Identity</h2>

            <table>
                <tr>
                    <th>Specification ID</th>
                    <td><?php echo safeText($course_specification_id); ?></td>
                    <th>Readiness</th>
                    <td>
                        <span class="<?php echo safeText($specReadinessClass); ?>">
                            <?php echo safeText($specReadiness); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Course Code</th>
                    <td><?php echo safeText($courseSpec["course_code"] ?? ""); ?></td>
                    <th>Course Title</th>
                    <td><?php echo safeText($courseSpec["course_title"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>Program</th>
                    <td><?php echo safeText($courseSpec["program_name"] ?? ""); ?></td>
                    <th>Department</th>
                    <td><?php echo safeText($courseSpec["department_name"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>College</th>
                    <td><?php echo safeText($courseSpec["college_name"] ?? ""); ?></td>
                    <th>Institution</th>
                    <td><?php echo safeText($courseSpec["institution_name"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>Credit Hours</th>
                    <td><?php echo safeText($courseSpec["credit_hours"] ?? ""); ?></td>
                    <th>Level / Year</th>
                    <td><?php echo safeText($courseSpec["course_level_year"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>Version</th>
                    <td><?php echo safeText($courseSpec["version_no"] ?? ""); ?></td>
                    <th>Last Revision Date</th>
                    <td><?php echo safeText($courseSpec["last_revision_date_text"] ?? ($courseSpec["last_revision_date"] ?? "")); ?></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td><?php echo safeText($courseSpec["status"] ?? ""); ?></td>
                    <th>Approval Status</th>
                    <td><?php echo safeText($courseSpec["approval_status"] ?? ""); ?></td>
                </tr>
            </table>

            <h3>Course General Description</h3>
            <p><?php echo nlText($courseSpec["course_general_description"] ?? ""); ?></p>

            <h3>Course Main Objective(s)</h3>
            <p><?php echo nlText($courseSpec["course_main_objectives"] ?? ""); ?></p>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-number"><?php echo safeText(count($clos)); ?></div>
                <div class="summary-label">Specification CLOs</div>
            </div>

            <div class="summary-card">
                <div class="summary-number"><?php echo safeText(count($topics)); ?></div>
                <div class="summary-label">Topics</div>
            </div>

            <div class="summary-card">
                <div class="summary-number"><?php echo safeText($totalTopicHours); ?></div>
                <div class="summary-label">Topic Contact Hours</div>
            </div>

            <div class="summary-card">
                <div class="summary-number"><?php echo safeText(count($assessments)); ?></div>
                <div class="summary-label">Assessment Activities</div>
            </div>

            <div class="summary-card">
                <div class="summary-number"><?php echo safeText($totalAssessmentPercentage); ?>%</div>
                <div class="summary-label">Assessment Total</div>
            </div>
        </div>

        <div class="panel">
            <h2>Specification CLOs, Teaching Strategies, and Assessment Methods</h2>

            <div class="wide-table-wrapper">
                <table class="wide-table">
                    <tr>
                        <th>Code</th>
                        <th>Domain</th>
                        <th>Course Learning Outcome</th>
                        <th>Aligned PLO</th>
                        <th>Teaching Strategies</th>
                        <th>Assessment Methods</th>
                    </tr>

                    <?php if (count($clos) > 0): ?>
                        <?php foreach ($clos as $clo): ?>
                            <tr>
                                <td><?php echo safeText($clo["clo_code"] ?? ""); ?></td>
                                <td><?php echo safeText($clo["clo_domain"] ?? ""); ?></td>
                                <td><?php echo nlText($clo["clo_text"] ?? ""); ?></td>
                                <td><?php echo safeText($clo["aligned_plo_code"] ?? ""); ?></td>
                                <td><?php echo nlText($clo["teaching_strategies"] ?? ""); ?></td>
                                <td><?php echo nlText($clo["assessment_methods"] ?? ""); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">No CLOs imported in the linked course specification.</td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <div class="panel">
            <h2>Specification Course Topics</h2>

            <table>
                <tr>
                    <th>No.</th>
                    <th>Topic</th>
                    <th>Contact Hours</th>
                </tr>

                <?php if (count($topics) > 0): ?>
                    <?php foreach ($topics as $topic): ?>
                        <tr>
                            <td><?php echo safeText($topic["topic_no"] ?? ""); ?></td>
                            <td><?php echo nlText($topic["topic_title"] ?? ""); ?></td>
                            <td><?php echo safeText($topic["contact_hours"] ?? ""); ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <tr>
                        <th colspan="2">Total</th>
                        <th><?php echo safeText($totalTopicHours); ?></th>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="3">No topics imported in the linked course specification.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="panel">
            <h2>Specification Assessment Activities</h2>

            <table>
                <tr>
                    <th>No.</th>
                    <th>Assessment Activity</th>
                    <th>Timing</th>
                    <th>Percentage</th>
                </tr>

                <?php if (count($assessments) > 0): ?>
                    <?php foreach ($assessments as $assessment): ?>
                        <tr>
                            <td><?php echo safeText($assessment["assessment_no"] ?? ""); ?></td>
                            <td><?php echo safeText($assessment["assessment_activity"] ?? ""); ?></td>
                            <td><?php echo safeText($assessment["assessment_timing"] ?? ""); ?></td>
                            <td><?php echo safeText($assessment["percentage"] ?? ""); ?>%</td>
                        </tr>
                    <?php endforeach; ?>

                    <tr>
                        <th colspan="3">Total</th>
                        <th>
                            <span class="<?php echo $assessmentOk ? 'ok-text' : 'bad-text'; ?>">
                                <?php echo safeText($totalAssessmentPercentage); ?>%
                            </span>
                        </th>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="4">No assessment activities imported in the linked course specification.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="panel">
            <h2>Learning Resources and Facilities from Specification</h2>

            <h3>Learning Resources</h3>

            <table>
                <tr>
                    <th>Resource Category</th>
                    <th>Resource Details</th>
                </tr>

                <?php if (count($resources) > 0): ?>
                    <?php foreach ($resources as $resource): ?>
                        <tr>
                            <td><?php echo safeText($resource["resource_category"] ?? ($resource["resource_type"] ?? "")); ?></td>
                            <td><?php echo nlText($resource["resource_details"] ?? ""); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="2">No learning resources imported.</td>
                    </tr>
                <?php endif; ?>
            </table>

            <h3>Facilities and Equipment</h3>

            <table>
                <tr>
                    <th>Item</th>
                    <th>Resources</th>
                </tr>

                <?php if (count($facilities) > 0): ?>
                    <?php foreach ($facilities as $facility): ?>
                        <tr>
                            <td><?php echo safeText($facility["facility_category"] ?? ($facility["facility_item"] ?? "")); ?></td>
                            <td><?php echo nlText($facility["required_resources"] ?? ""); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="2">No facilities imported.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="panel">
            <h2>Course Quality and Approval from Specification</h2>

            <h3>Course Quality Assessment</h3>

            <table>
                <tr>
                    <th>Assessment Area / Issue</th>
                    <th>Assessor</th>
                    <th>Assessment Method</th>
                </tr>

                <?php if (count($qualityRows) > 0): ?>
                    <?php foreach ($qualityRows as $quality): ?>
                        <tr>
                            <td><?php echo nlText($quality["assessment_area"] ?? ""); ?></td>
                            <td><?php echo nlText($quality["assessor"] ?? ""); ?></td>
                            <td><?php echo nlText($quality["assessment_method"] ?? ""); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3">No quality assessment rows imported.</td>
                    </tr>
                <?php endif; ?>
            </table>

            <h3>Specification Approval</h3>

            <table>
                <tr>
                    <th>Council / Committee</th>
                    <th>Reference No.</th>
                    <th>Date</th>
                    <th>Notes</th>
                </tr>

                <?php if (count($approvalRows) > 0): ?>
                    <?php foreach ($approvalRows as $approval): ?>
                        <tr>
                            <td><?php echo safeText($approval["council_committee"] ?? ""); ?></td>
                            <td><?php echo safeText($approval["reference_no"] ?? ""); ?></td>
                            <td><?php echo safeText($approval["approval_date_text"] ?? ($approval["approval_date"] ?? "")); ?></td>
                            <td><?php echo nlText($approval["notes"] ?? ""); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4">No approval data imported.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="panel">
            <h2>Attached Course Specification Files</h2>

            <table>
                <tr>
                    <th>Original File</th>
                    <th>Type</th>
                    <th>Size</th>
                    <th>Upload Date</th>
                    <th>Open</th>
                </tr>

                <?php if (count($specFiles) > 0): ?>
                    <?php foreach ($specFiles as $file): ?>
                        <tr>
                            <td><?php echo safeText($file["original_filename"] ?? ""); ?></td>
                            <td><?php echo safeText($file["file_type"] ?? ""); ?></td>
                            <td><?php echo safeText(round(((int)($file["file_size"] ?? 0)) / 1024, 1)); ?> KB</td>
                            <td><?php echo safeText($file["upload_date"] ?? ""); ?></td>
                            <td>
                                <?php if (!empty($file["file_path"])): ?>
                                    <a href="download_file.php?type=course_specification&amp;id=<?php echo urlencode((string)$file['id']); ?>" class="btn" target="_blank">
                                        Open
                                    </a>
                                <?php else: ?>
                                    <span class="small-text">No file path</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">No attached course specification file found.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>

    <?php endif; ?>

    <div class="section-title">Course Report Content</div>

    <?php if (count($displayOptionalSections) > 0): ?>
        <?php foreach ($displayOptionalSections as $section): ?>
            <div class="panel">
                <h2><?php echo safeText($section["label"]); ?></h2>
                <p><?php echo nlText($section["value"]); ?></p>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="panel">
            <h2>Report Content</h2>
            <p class="small-text">
                No extended report narrative fields were detected in this course report table, or they are empty.
            </p>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Raw Course Report Fields</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Field</th>
                    <th>Value</th>
                </tr>

                <?php foreach ($report as $field => $value): ?>
                    <tr>
                        <td><strong><?php echo safeText($field); ?></strong></td>
                        <td><?php echo nlText($value); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
