<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid course report ID.");
}

$course_report_id = (int)$_GET["id"];

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

function getValue($row, $fields, $default = "") {
    foreach ($fields as $field) {
        if (isset($row[$field]) && trim((string)$row[$field]) !== "") {
            return $row[$field];
        }
    }

    return $default;
}

function loadSpecRows($conn, $tableName, $course_specification_id, $orderBy = "id ASC") {
    if (!tableExists($conn, $tableName)) {
        return [];
    }

    if (!columnExists($conn, $tableName, "course_specification_id")) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM `$tableName`
        WHERE course_specification_id = :id
        ORDER BY $orderBy
    ");
    $stmt->execute([":id" => $course_specification_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function loadReportRows($conn, $tableName, $course_report_id, $orderBy = "id ASC") {
    if (!tableExists($conn, $tableName)) {
        return [];
    }

    $fk = null;

    if (columnExists($conn, $tableName, "course_report_id")) {
        $fk = "course_report_id";
    } elseif (columnExists($conn, $tableName, "report_id")) {
        $fk = "report_id";
    }

    if ($fk === null) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM `$tableName`
        WHERE `$fk` = :report_id
        ORDER BY $orderBy
    ");
    $stmt->execute([":report_id" => $course_report_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sumSpecColumn($conn, $tableName, $columnName, $course_specification_id) {
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
        SELECT COALESCE(SUM(`$columnName`), 0)
        FROM `$tableName`
        WHERE course_specification_id = :id
    ");
    $stmt->execute([":id" => $course_specification_id]);

    return (float)$stmt->fetchColumn();
}

function renderGenericRowsTable($rows) {
    if (count($rows) === 0) {
        echo "<p>No rows found.</p>";
        return;
    }

    $columns = array_keys($rows[0]);

    echo "<table>";
    echo "<tr>";
    foreach ($columns as $col) {
        echo "<th>" . safeText($col) . "</th>";
    }
    echo "</tr>";

    foreach ($rows as $row) {
        echo "<tr>";
        foreach ($columns as $col) {
            echo "<td>" . wordText($row[$col] ?? "") . "</td>";
        }
        echo "</tr>";
    }

    echo "</table>";
}

function calculatePassRate($passed, $total) {
    $passed = (int)$passed;
    $total = (int)$total;

    if ($total <= 0) {
        return "N/A";
    }

    return round(($passed / $total) * 100, 1) . "%";
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

/*
    Load linked course specification.
*/
$course_specification_id = isset($report["course_specification_id"]) && is_numeric($report["course_specification_id"])
    ? (int)$report["course_specification_id"]
    : 0;

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

/*
    Linked specification child data.
*/
$clos = [];
$topics = [];
$assessments = [];
$resources = [];
$facilities = [];
$qualityRows = [];
$approvalRows = [];

$totalTopicHours = 0;
$totalAssessmentPercentage = 0;

if ($courseSpec) {
    $clos = loadSpecRows($conn, "course_specification_clos", $course_specification_id, "display_order ASC, id ASC");
    $topics = loadSpecRows($conn, "course_specification_topics", $course_specification_id, "display_order ASC, topic_no ASC, id ASC");
    $assessments = loadSpecRows($conn, "course_specification_assessments", $course_specification_id, "display_order ASC, assessment_no ASC, id ASC");
    $resources = loadSpecRows($conn, "course_specification_resources", $course_specification_id, "display_order ASC, id ASC");
    $facilities = loadSpecRows($conn, "course_specification_facilities", $course_specification_id, "display_order ASC, id ASC");
    $qualityRows = loadSpecRows($conn, "course_specification_quality", $course_specification_id, "display_order ASC, id ASC");
    $approvalRows = loadSpecRows($conn, "course_specification_approval", $course_specification_id, "id ASC");

    $totalTopicHours = sumSpecColumn($conn, "course_specification_topics", "contact_hours", $course_specification_id);
    $totalAssessmentPercentage = sumSpecColumn($conn, "course_specification_assessments", "percentage", $course_specification_id);
}

/*
    Course report detail tables.
*/
$gradeDistributionRows = loadReportRows($conn, "course_report_grade_distribution", $course_report_id, "id ASC");
$cloResultRows = loadReportRows($conn, "course_report_clo_results", $course_report_id, "id ASC");
$examAlignmentRows = loadReportRows($conn, "course_report_exam_alignment", $course_report_id, "id ASC");
$topicsNotCoveredRows = loadReportRows($conn, "course_report_topics_not_covered", $course_report_id, "id ASC");
$previousActionsRows = loadReportRows($conn, "course_report_previous_improvement_actions", $course_report_id, "id ASC");
$improvementPlanRows = loadReportRows($conn, "course_report_improvement_plan", $course_report_id, "id ASC");

/*
    Imported student results.
*/
$studentResultRows = [];

if (tableExists($conn, "course_report_student_results")) {
    $studentStmt = $conn->prepare("
        SELECT *
        FROM course_report_student_results
        WHERE course_report_id = :course_report_id
        ORDER BY total_mark DESC, student_name ASC
    ");
    $studentStmt->execute([":course_report_id" => $course_report_id]);
    $studentResultRows = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
    Student results summary from imported table.
*/
$importedTotalStudents = count($studentResultRows);
$importedPassed = 0;
$importedFailed = 0;

foreach ($studentResultRows as $student) {
    if (($student["result_status"] ?? "") === "Passed") {
        $importedPassed++;
    } elseif (($student["result_status"] ?? "") === "Failed") {
        $importedFailed++;
    }
}

$gradeOrder = ["A+", "A", "B+", "B", "C+", "C", "D+", "D", "F"];
$gradeSummary = [];

foreach ($gradeOrder as $grade) {
    $gradeSummary[$grade] = [
        "grade_label" => $grade,
        "student_count" => 0,
        "percentage" => 0
    ];
}

foreach ($gradeDistributionRows as $row) {
    $grade = trim((string)($row["grade_label"] ?? ""));

    if (isset($gradeSummary[$grade])) {
        $gradeSummary[$grade]["student_count"] = (int)($row["student_count"] ?? 0);
        $gradeSummary[$grade]["percentage"] = (float)($row["percentage"] ?? 0);
    }
}

/*
    Main report values.
*/
$reportCourseCode = getValue($report, ["course_code", "code"]);
$reportCourseTitle = getValue($report, ["course_title", "title", "report_title", "course_name", "name"]);
$reportAcademicYear = getValue($report, ["academic_year", "year", "report_year"]);
$reportSemester = getValue($report, ["semester", "term", "report_semester"]);

$filenameBase = $reportCourseCode . "_" . $reportCourseTitle . "_Course_Report";
$filenameBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filenameBase);
$filenameBase = preg_replace('/_+/', '_', $filenameBase);
$filenameBase = trim(substr($filenameBase, 0, 120), "_");
$filename = $filenameBase . "_" . date("Y-m-d_H-i-s") . ".doc";

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
    <title>Course Report</title>

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

        .section-note {
            background: #fff3cd;
            border: 1px solid #e6cf85;
            padding: 8px;
            margin-bottom: 12px;
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

        .domain-row {
            background: #e9ecef;
            font-weight: bold;
        }

        .total-row {
            background: #f2f2f2;
            font-weight: bold;
        }

        .small {
            font-size: 9pt;
        }
    </style>
</head>

<body>

<div class="cover">
    <h1>Course Report</h1>
    <p><strong><?php echo safeText($reportCourseCode); ?> - <?php echo safeText($reportCourseTitle); ?></strong></p>
    <p><?php echo safeText($report["college_name"] ?? ($courseSpec["college_name"] ?? "")); ?></p>
    <p><?php echo safeText($report["institution_name"] ?? ($courseSpec["institution_name"] ?? "")); ?></p>
    <p>Generated on: <?php echo date("Y-m-d H:i"); ?></p>
</div>

<h2>1. Course Report Identity</h2>

<table>
    <tr>
        <th>Course Report ID</th>
        <td><?php echo safeText($course_report_id); ?></td>
        <th>Report Status</th>
        <td><?php echo safeText($report["status"] ?? ""); ?></td>
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
        <th>Course Coordinator</th>
        <td><?php echo safeText($report["course_coordinator"] ?? ""); ?></td>
        <th>Report Date</th>
        <td><?php echo safeText($report["report_date"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Report Version</th>
        <td><?php echo safeText($report["report_version"] ?? ""); ?></td>
        <th>Linked Course Specification ID</th>
        <td><?php echo $course_specification_id > 0 ? safeText($course_specification_id) : "Not linked"; ?></td>
    </tr>
</table>

<h2>2. Official Header Details</h2>

<table>
    <tr>
        <th>Program</th>
        <td><?php echo safeText($report["program_name"] ?? ($courseSpec["program_name"] ?? "")); ?></td>
        <th>Department</th>
        <td><?php echo safeText($report["department_name"] ?? ($courseSpec["department_name"] ?? "")); ?></td>
    </tr>
    <tr>
        <th>College</th>
        <td><?php echo safeText($report["college_name"] ?? ($courseSpec["college_name"] ?? "")); ?></td>
        <th>Institution</th>
        <td><?php echo safeText($report["institution_name"] ?? ($courseSpec["institution_name"] ?? "")); ?></td>
    </tr>
    <tr>
        <th>Course Instructors</th>
        <td><?php echo wordText($report["course_instructors"] ?? ""); ?></td>
        <th>Location</th>
        <td><?php echo safeText($report["location"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Number of Sections</th>
        <td><?php echo safeText($report["number_of_sections"] ?? ""); ?></td>
        <th>Created / Updated</th>
        <td><?php echo safeText($report["created_at"] ?? ""); ?> / <?php echo safeText($report["updated_at"] ?? ""); ?></td>
    </tr>
</table>

<h2>3. Linked Course Specification Source Data</h2>

<?php if (!$courseSpec): ?>
    <div class="section-note">
        This course report is not linked to a course specification. Link it first to pull CLOs, topics, assessments, resources, facilities, quality, and approval data.
    </div>
<?php else: ?>

    <h3>3.1 Course Specification Identity</h3>

    <table>
        <tr>
            <th>Specification ID</th>
            <td><?php echo safeText($course_specification_id); ?></td>
            <th>Specification Status</th>
            <td><?php echo safeText($courseSpec["status"] ?? ""); ?></td>
        </tr>
        <tr>
            <th>Course Code</th>
            <td><?php echo safeText($courseSpec["course_code"] ?? ""); ?></td>
            <th>Course Title</th>
            <td><?php echo safeText($courseSpec["course_title"] ?? ""); ?></td>
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
            <th>Last Revision</th>
            <td><?php echo safeText($courseSpec["last_revision_date_text"] ?? ($courseSpec["last_revision_date"] ?? "")); ?></td>
        </tr>
    </table>

    <h3>3.2 Course General Description</h3>
    <p><?php echo wordText($courseSpec["course_general_description"] ?? ""); ?></p>

    <h3>3.3 Course Main Objective(s)</h3>
    <p><?php echo wordText($courseSpec["course_main_objectives"] ?? ""); ?></p>

    <h3>3.4 Course Learning Outcomes, Teaching Strategies and Assessment Methods</h3>

    <table>
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
                    <td class="center"><?php echo safeText($clo["clo_code"] ?? ""); ?></td>
                    <td><?php echo safeText($clo["clo_domain"] ?? ""); ?></td>
                    <td><?php echo wordText($clo["clo_text"] ?? ""); ?></td>
                    <td class="center"><?php echo safeText($clo["aligned_plo_code"] ?? ""); ?></td>
                    <td><?php echo wordText($clo["teaching_strategies"] ?? ""); ?></td>
                    <td><?php echo wordText($clo["assessment_methods"] ?? ""); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="6">No CLOs imported in the linked course specification.</td>
            </tr>
        <?php endif; ?>
    </table>

    <h3>3.5 Course Content / Topics</h3>

    <table>
        <tr>
            <th>No.</th>
            <th>Topic</th>
            <th>Contact Hours</th>
        </tr>

        <?php if (count($topics) > 0): ?>
            <?php foreach ($topics as $topic): ?>
                <tr>
                    <td class="center"><?php echo safeText($topic["topic_no"] ?? ""); ?></td>
                    <td><?php echo wordText($topic["topic_title"] ?? ""); ?></td>
                    <td class="center"><?php echo safeText($topic["contact_hours"] ?? ""); ?></td>
                </tr>
            <?php endforeach; ?>

            <tr class="total-row">
                <td colspan="2">Total</td>
                <td class="center"><?php echo safeText($totalTopicHours); ?></td>
            </tr>
        <?php else: ?>
            <tr>
                <td colspan="3">No topics imported in the linked course specification.</td>
            </tr>
        <?php endif; ?>
    </table>

    <h3>3.6 Student Assessment Activities from Specification</h3>

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
                    <td class="center"><?php echo safeText($assessment["assessment_no"] ?? ""); ?></td>
                    <td><?php echo safeText($assessment["assessment_activity"] ?? ""); ?></td>
                    <td class="center"><?php echo safeText($assessment["assessment_timing"] ?? ""); ?></td>
                    <td class="center"><?php echo safeText($assessment["percentage"] ?? ""); ?>%</td>
                </tr>
            <?php endforeach; ?>

            <tr class="total-row">
                <td colspan="3">Total</td>
                <td class="center"><?php echo safeText($totalAssessmentPercentage); ?>%</td>
            </tr>
        <?php else: ?>
            <tr>
                <td colspan="4">No assessment activities imported in the linked course specification.</td>
            </tr>
        <?php endif; ?>
    </table>

    <h3>3.7 Learning Resources</h3>

    <table>
        <tr>
            <th>Resource Category</th>
            <th>Resource Details</th>
        </tr>

        <?php if (count($resources) > 0): ?>
            <?php foreach ($resources as $resource): ?>
                <tr>
                    <td><?php echo safeText($resource["resource_category"] ?? ($resource["resource_type"] ?? "")); ?></td>
                    <td><?php echo wordText($resource["resource_details"] ?? ""); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="2">No learning resources imported.</td>
            </tr>
        <?php endif; ?>
    </table>

    <h3>3.8 Facilities and Equipment</h3>

    <table>
        <tr>
            <th>Item</th>
            <th>Required Resources</th>
        </tr>

        <?php if (count($facilities) > 0): ?>
            <?php foreach ($facilities as $facility): ?>
                <tr>
                    <td><?php echo safeText($facility["facility_category"] ?? ($facility["facility_item"] ?? "")); ?></td>
                    <td><?php echo wordText($facility["required_resources"] ?? ""); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="2">No facilities imported.</td>
            </tr>
        <?php endif; ?>
    </table>

    <h3>3.9 Course Quality Assessment</h3>

    <table>
        <tr>
            <th>Assessment Area / Issue</th>
            <th>Assessor</th>
            <th>Assessment Method</th>
        </tr>

        <?php if (count($qualityRows) > 0): ?>
            <?php foreach ($qualityRows as $quality): ?>
                <tr>
                    <td><?php echo wordText($quality["assessment_area"] ?? ""); ?></td>
                    <td><?php echo wordText($quality["assessor"] ?? ""); ?></td>
                    <td><?php echo wordText($quality["assessment_method"] ?? ""); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="3">No quality rows imported.</td>
            </tr>
        <?php endif; ?>
    </table>

    <h3>3.10 Specification Approval</h3>

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
                    <td><?php echo wordText($approval["notes"] ?? ""); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="4">No approval data imported.</td>
            </tr>
        <?php endif; ?>
    </table>

<?php endif; ?>

<h2>4. Student Results Summary</h2>

<table>
    <tr>
        <th>Students Enrolled</th>
        <td class="center"><?php echo safeText($report["students_enrolled"] ?? "0"); ?></td>
        <th>Students Starting</th>
        <td class="center"><?php echo safeText($report["students_starting"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Students Completed</th>
        <td class="center"><?php echo safeText($report["students_completed"] ?? ""); ?></td>
        <th>Students Passed</th>
        <td class="center"><?php echo safeText($report["students_passed"] ?? "0"); ?></td>
    </tr>
    <tr>
        <th>Students Failed</th>
        <td class="center"><?php echo safeText($report["students_failed"] ?? "0"); ?></td>
        <th>Pass Rate</th>
        <td class="center">
            <?php echo safeText(calculatePassRate($report["students_passed"] ?? 0, $report["students_enrolled"] ?? 0)); ?>
        </td>
    </tr>
</table>

<h2>5. Grade Distribution Generated from Student Results</h2>

<table>
    <tr>
        <th>Grade</th>
        <th>Student Count</th>
        <th>Percentage</th>
    </tr>

    <?php foreach ($gradeSummary as $grade => $row): ?>
        <tr>
            <td class="center"><strong><?php echo safeText($grade); ?></strong></td>
            <td class="center"><?php echo safeText($row["student_count"]); ?></td>
            <td class="center"><?php echo safeText($row["percentage"]); ?>%</td>
        </tr>
    <?php endforeach; ?>
</table>

<h2>6. Imported Student Results Details</h2>

<?php if (count($studentResultRows) > 0): ?>
    <table>
        <tr>
            <th>No.</th>
            <th>Course Code</th>
            <th>Coordinator</th>
            <th>Student ID</th>
            <th>Student Name</th>
            <th>Coursework</th>
            <th>Quiz Exam</th>
            <th>Midterm</th>
            <th>Final Exam</th>
            <th>Total Mark</th>
            <th>Grade</th>
            <th>Status</th>
        </tr>

        <?php $i = 1; ?>
        <?php foreach ($studentResultRows as $student): ?>
            <tr>
                <td class="center"><?php echo safeText($i); ?></td>
                <td><?php echo safeText($student["course_code"] ?? ""); ?></td>
                <td><?php echo safeText($student["course_coordinator"] ?? ""); ?></td>
                <td><?php echo safeText($student["student_id"] ?? ""); ?></td>
                <td><?php echo safeText($student["student_name"] ?? ""); ?></td>
                <td class="center"><?php echo safeText($student["coursework"] ?? ""); ?></td>
                <td class="center"><?php echo safeText($student["quiz_exam"] ?? ""); ?></td>
                <td class="center"><?php echo safeText($student["midterm"] ?? ""); ?></td>
                <td class="center"><?php echo safeText($student["final_exam"] ?? ""); ?></td>
                <td class="center"><?php echo safeText($student["total_mark"] ?? ""); ?></td>
                <td class="center"><strong><?php echo safeText($student["grade_label"] ?? ""); ?></strong></td>
                <td class="center"><?php echo safeText($student["result_status"] ?? ""); ?></td>
            </tr>
            <?php $i++; ?>
        <?php endforeach; ?>
    </table>
<?php else: ?>
    <p>No imported student result rows found for this course report.</p>
<?php endif; ?>

<h2>7. Course Report Narrative</h2>

<table>
    <tr>
        <th>CLO Achievement Summary</th>
        <td><?php echo wordText($report["clo_achievement_summary"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Assessment Summary</th>
        <td><?php echo wordText($report["assessment_summary"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Teaching Summary</th>
        <td><?php echo wordText($report["teaching_summary"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Student Feedback Summary</th>
        <td><?php echo wordText($report["student_feedback_summary"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Course Improvement Actions</th>
        <td><?php echo wordText($report["course_improvement_actions"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>General Notes</th>
        <td><?php echo wordText($report["general_notes"] ?? ""); ?></td>
    </tr>
</table>

<h2>8. Course Report Detail Tables</h2>

<h3>8.1 Grade Distribution Table</h3>
<?php renderGenericRowsTable($gradeDistributionRows); ?>

<h3>8.2 CLO Actual Achievement Results</h3>
<?php renderGenericRowsTable($cloResultRows); ?>

<h3>8.3 Final Exam Alignment</h3>
<?php renderGenericRowsTable($examAlignmentRows); ?>

<h3>8.4 Topics Not Covered</h3>
<?php renderGenericRowsTable($topicsNotCoveredRows); ?>

<h3>8.5 Previous Improvement Actions</h3>
<?php renderGenericRowsTable($previousActionsRows); ?>

<h3>8.6 Course Improvement Plan</h3>
<?php renderGenericRowsTable($improvementPlanRows); ?>

<h2>9. Report Interpretation</h2>

<table>
    <tr>
        <th>Linked Specification Status</th>
        <td>
            <?php if ($courseSpec): ?>
                This course report is linked to the official imported course specification and can pull specification-based CLOs, topics, assessments, resources, facilities, quality, and approval data.
            <?php else: ?>
                This course report is not linked to a course specification. Linkage is required for complete evidence.
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th>Student Results Import Status</th>
        <td>
            <?php if (count($studentResultRows) > 0): ?>
                Student results were imported and used to generate the grade distribution and pass/fail summary.
            <?php else: ?>
                No student results have been imported for this report.
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th>Generated By</th>
        <td>Quality System - Course Reports Module</td>
    </tr>
</table>

</body>
</html>