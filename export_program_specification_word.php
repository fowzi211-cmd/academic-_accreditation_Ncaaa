<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid program specification ID.");
}

$spec_id = (int)$_GET["id"];

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

function valueOrDash($value) {
    $value = trim((string)($value ?? ""));
    return $value === "" ? "-" : safeText($value);
}

function sectionText($title, $content) {
    echo "<h3>" . safeText($title) . "</h3>";
    if (trim((string)($content ?? "")) === "") {
        echo "<p>-</p>";
    } else {
        echo "<p>" . wordText($content) . "</p>";
    }
}

function loadRows($conn, $tableName, $spec_id, $orderBy = "id ASC") {
    if (!tableExists($conn, $tableName)) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM $tableName
        WHERE program_specification_id = :id
        ORDER BY $orderBy
    ");
    $stmt->execute([":id" => $spec_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

$hasStudyPlans = tableExists($conn, "study_plans");
$hasPlos = tableExists($conn, "program_specification_plos");
$hasCourses = tableExists($conn, "program_specification_courses");
$hasMappings = tableExists($conn, "program_specification_plo_course_mapping");
$hasKpis = tableExists($conn, "program_specification_kpis");
$hasCurriculum = tableExists($conn, "program_specification_curriculum_structure");
$hasEvaluation = tableExists($conn, "program_specification_evaluation_matrix");
$hasTracks = tableExists($conn, "program_specification_tracks");
$hasExitPoints = tableExists($conn, "program_specification_exit_points");
$hasTeaching = tableExists($conn, "program_specification_teaching_strategies");
$hasAssessment = tableExists($conn, "program_specification_assessment_methods");
$hasMarks = tableExists($conn, "program_specification_mark_distributions");
$hasStaffing = tableExists($conn, "program_specification_staffing_needs");

$hasApprovalStatus = columnExists($conn, "program_specifications", "approval_status");
$hasStatus = columnExists($conn, "program_specifications", "status");

$statusSelect = "NULL AS approval_status_value";
if ($hasApprovalStatus) {
    $statusSelect = "ps.approval_status AS approval_status_value";
} elseif ($hasStatus) {
    $statusSelect = "ps.status AS approval_status_value";
}

$studyPlanSelect = "
    NULL AS study_plan_title,
    NULL AS study_plan_version,
    NULL AS study_plan_academic_year
";

$studyPlanJoin = "";

if ($hasStudyPlans) {
    $studyPlanSelect = "
        sp.plan_title AS study_plan_title,
        sp.plan_version AS study_plan_version,
        sp.academic_year AS study_plan_academic_year
    ";

    $studyPlanJoin = "
        LEFT JOIN study_plans sp ON ps.study_plan_id = sp.id
    ";
}

$stmt = $conn->prepare("
    SELECT
        ps.*,
        $statusSelect,
        $studyPlanSelect
    FROM program_specifications ps
    $studyPlanJoin
    WHERE ps.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $spec_id]);
$spec = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$spec) {
    die("Program specification not found.");
}

/* ===============================
   Load related records
   =============================== */

$plos = [];
if ($hasPlos) {
    $stmt = $conn->prepare("
        SELECT *
        FROM program_specification_plos
        WHERE program_specification_id = :id
        ORDER BY
            CASE
                WHEN plo_domain = 'Knowledge and Understanding' THEN 1
                WHEN plo_domain = 'Skills' THEN 2
                WHEN plo_domain = 'Values, Autonomy, and Responsibility' THEN 3
                ELSE 4
            END,
            display_order ASC,
            plo_code ASC,
            id ASC
    ");
    $stmt->execute([":id" => $spec_id]);
    $plos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$curriculumRows = [];
if ($hasCurriculum) {
    $curriculumRows = loadRows(
        $conn,
        "program_specification_curriculum_structure",
        $spec_id,
        "
        CASE
            WHEN structure_category LIKE '%Institution%' THEN 1
            WHEN structure_category LIKE '%University%' THEN 1
            WHEN structure_category LIKE '%College%' THEN 2
            WHEN structure_category LIKE '%Program%' THEN 3
            WHEN structure_category LIKE '%Capstone%' THEN 4
            WHEN structure_category LIKE '%Project%' THEN 4
            WHEN structure_category LIKE '%Internship%' THEN 5
            WHEN structure_category LIKE '%Total%' THEN 9
            ELSE 8
        END,
        id ASC
        "
    );
}

$courses = [];
if ($hasCourses) {
    $courses = loadRows($conn, "program_specification_courses", $spec_id, "level_no ASC, course_code ASC, id ASC");
}

$mappings = [];
if ($hasMappings) {
    $mappingRows = loadRows($conn, "program_specification_plo_course_mapping", $spec_id, "course_code ASC, plo_code ASC");

    foreach ($mappingRows as $row) {
        $courseCode = strtoupper(trim((string)($row["course_code"] ?? "")));
        $ploCode = strtoupper(trim((string)($row["plo_code"] ?? "")));

        if ($courseCode !== "" && $ploCode !== "") {
            $mappings[$courseCode][$ploCode] = $row["performance_level"] ?? "";
        }
    }
}

$kpis = [];
if ($hasKpis) {
    $kpis = loadRows($conn, "program_specification_kpis", $spec_id, "kpi_type ASC, kpi_no ASC, kpi_code ASC, id ASC");
}

$evaluationRows = [];
if ($hasEvaluation) {
    $evaluationRows = loadRows($conn, "program_specification_evaluation_matrix", $spec_id, "id ASC");
}

$tracks = [];
if ($hasTracks) {
    $tracks = loadRows($conn, "program_specification_tracks", $spec_id, "id ASC");
}

$exitPoints = [];
if ($hasExitPoints) {
    $exitPoints = loadRows($conn, "program_specification_exit_points", $spec_id, "credit_hours ASC, id ASC");
}

$teachingRows = [];
if ($hasTeaching) {
    $teachingRows = loadRows($conn, "program_specification_teaching_strategies", $spec_id, "strategy_name ASC, id ASC");
}

$assessmentRows = [];
if ($hasAssessment) {
    $assessmentRows = loadRows($conn, "program_specification_assessment_methods", $spec_id, "assessment_type ASC, assessment_method ASC, id ASC");
}

$markRows = [];
if ($hasMarks) {
    $markRows = loadRows($conn, "program_specification_mark_distributions", $spec_id, "course_type ASC, display_order ASC, id ASC");
}

$staffingRows = [];
if ($hasStaffing) {
    $staffingRows = loadRows(
        $conn,
        "program_specification_staffing_needs",
        $spec_id,
        "
        CASE
            WHEN academic_rank LIKE '%Professor%' THEN 1
            WHEN academic_rank LIKE '%Associate%' THEN 2
            WHEN academic_rank LIKE '%Assistant%' THEN 3
            WHEN academic_rank LIKE '%Lecturer%' THEN 4
            WHEN academic_rank LIKE '%Teaching Assistant%' THEN 5
            WHEN academic_rank LIKE '%Technician%' THEN 6
            WHEN academic_rank LIKE '%Administrative%' THEN 7
            ELSE 8
        END,
        id ASC
        "
    );
}

/* ===============================
   Totals
   =============================== */

$totalCourseCredits = 0;
foreach ($courses as $course) {
    $totalCourseCredits += (float)($course["credit_hours"] ?? 0);
}

$totalStaffMale = 0;
$totalStaffFemale = 0;
$totalStaffRequired = 0;

foreach ($staffingRows as $row) {
    $totalStaffMale += (int)($row["required_male"] ?? 0);
    $totalStaffFemale += (int)($row["required_female"] ?? 0);
    $totalStaffRequired += (int)($row["required_total"] ?? 0);
}

$markTotals = [];
foreach ($markRows as $row) {
    $type = $row["course_type"] ?? "Other";
    if (!isset($markTotals[$type])) {
        $markTotals[$type] = 0;
    }
    $markTotals[$type] += (float)($row["marks"] ?? 0);
}

/* ===============================
   Output headers
   =============================== */

$filenameBase = $spec["program_code"] ?? "program_specification";
$filenameBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filenameBase);
$filename = "program_specification_" . $filenameBase . "_" . date("Y-m-d_H-i-s") . ".doc";

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
    <title>Program Specification Export</title>

    <style>
        body {
            font-family: Arial, Tahoma, sans-serif;
            font-size: 10.5pt;
            line-height: 1.45;
            color: #111;
        }

        h1 {
            text-align: center;
            font-size: 20pt;
            margin-bottom: 5px;
        }

        h2 {
            background: #d9eaf7;
            border: 1px solid #9dbbd4;
            padding: 8px;
            font-size: 15pt;
            margin-top: 25px;
        }

        h3 {
            font-size: 13pt;
            margin-top: 18px;
            margin-bottom: 6px;
            color: #1f4e79;
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

        .small {
            font-size: 9pt;
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

        .page-break {
            page-break-before: always;
        }

        .summary-box {
            border: 1px solid #999;
            background: #fafafa;
            padding: 10px;
            margin-bottom: 15px;
        }

        .total-row {
            background: #f8f9fa;
            font-weight: bold;
        }
    </style>
</head>

<body>

<div class="cover">
    <h1>Program Specification</h1>
    <p><strong><?php echo valueOrDash($spec["program_name"] ?? ""); ?></strong></p>
    <p><?php echo valueOrDash($spec["college_name"] ?? ""); ?></p>
    <p><?php echo valueOrDash($spec["institution_name"] ?? ""); ?></p>
    <p>Exported on: <?php echo date("Y-m-d H:i"); ?></p>
</div>

<h2>A. Program Identification and General Information</h2>

<table>
    <tr>
        <th>Specification Title</th>
        <td colspan="3"><?php echo valueOrDash($spec["specification_title"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Program Name</th>
        <td><?php echo valueOrDash($spec["program_name"] ?? ""); ?></td>
        <th>Program Code</th>
        <td><?php echo valueOrDash($spec["program_code"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Qualification Level</th>
        <td><?php echo valueOrDash($spec["qualification_level"] ?? ""); ?></td>
        <th>Total Credit Hours</th>
        <td><?php echo valueOrDash($spec["total_credit_hours"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Department</th>
        <td><?php echo valueOrDash($spec["department_name"] ?? ""); ?></td>
        <th>College</th>
        <td><?php echo valueOrDash($spec["college_name"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Institution</th>
        <td><?php echo valueOrDash($spec["institution_name"] ?? ""); ?></td>
        <th>Program Status</th>
        <td><?php echo valueOrDash($spec["approval_status_value"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Specification Version</th>
        <td><?php echo valueOrDash($spec["specification_version"] ?? ""); ?></td>
        <th>Academic Year</th>
        <td><?php echo valueOrDash($spec["academic_year"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Specification Type</th>
        <td><?php echo valueOrDash($spec["specification_type"] ?? ""); ?></td>
        <th>Last Review Date</th>
        <td><?php echo valueOrDash($spec["last_review_date"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Linked Study Plan</th>
        <td colspan="3">
            <?php echo valueOrDash($spec["study_plan_title"] ?? ""); ?>
            <?php if (!empty($spec["study_plan_version"]) || !empty($spec["study_plan_academic_year"])): ?>
                <br>
                Version: <?php echo valueOrDash($spec["study_plan_version"] ?? ""); ?>
                |
                Academic Year: <?php echo valueOrDash($spec["study_plan_academic_year"] ?? ""); ?>
            <?php endif; ?>
        </td>
    </tr>
</table>

<?php sectionText("Main Location", $spec["main_location"] ?? ""); ?>
<?php sectionText("Branches Offering the Program", $spec["branches"] ?? ""); ?>
<?php sectionText("Partnerships", $spec["partnerships"] ?? ""); ?>
<?php sectionText("Professions / Jobs for Which Students Are Qualified", $spec["qualified_jobs"] ?? ""); ?>
<?php sectionText("Relevant Occupational / Professional Sectors", $spec["occupational_sectors"] ?? ""); ?>

<h3>Tracks / Pathways</h3>
<table>
    <tr>
        <th>Track Name</th>
        <th>Credit Hours</th>
        <th>Professions / Jobs</th>
        <th>Notes</th>
    </tr>
    <?php if (count($tracks) > 0): ?>
        <?php foreach ($tracks as $row): ?>
            <tr>
                <td><?php echo valueOrDash($row["track_name"] ?? ""); ?></td>
                <td><?php echo valueOrDash($row["credit_hours"] ?? ""); ?></td>
                <td><?php echo wordText($row["professions_jobs"] ?? ""); ?></td>
                <td><?php echo wordText($row["notes"] ?? ""); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="4">No tracks recorded.</td></tr>
    <?php endif; ?>
</table>

<h3>Exit Points / Awarded Degrees</h3>
<table>
    <tr>
        <th>Exit Point / Awarded Degree</th>
        <th>Credit Hours</th>
        <th>Notes</th>
    </tr>
    <?php if (count($exitPoints) > 0): ?>
        <?php foreach ($exitPoints as $row): ?>
            <tr>
                <td><?php echo valueOrDash($row["exit_point_name"] ?? ""); ?></td>
                <td><?php echo valueOrDash($row["credit_hours"] ?? ""); ?></td>
                <td><?php echo wordText($row["notes"] ?? ""); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="3">No exit points recorded.</td></tr>
    <?php endif; ?>
</table>

<h2>B. Mission, Objectives, and Program Learning Outcomes</h2>

<?php sectionText("Program Mission", $spec["program_mission"] ?? ""); ?>
<?php sectionText("Program Goals", $spec["program_goals"] ?? ""); ?>
<?php sectionText("Graduate Attributes", $spec["graduate_attributes"] ?? ""); ?>

<h3>Program Learning Outcomes</h3>
<table>
    <tr>
        <th>Code</th>
        <th>Domain</th>
        <th>Program Learning Outcome</th>
        <th>NQF Level</th>
    </tr>

    <?php if (count($plos) > 0): ?>
        <?php foreach ($plos as $plo): ?>
            <tr>
                <td><?php echo valueOrDash($plo["plo_code"] ?? ""); ?></td>
                <td><?php echo valueOrDash($plo["plo_domain"] ?? ""); ?></td>
                <td><?php echo wordText($plo["plo_text"] ?? ""); ?></td>
                <td><?php echo valueOrDash($plo["nqf_level"] ?? ""); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="4">No PLOs recorded.</td></tr>
    <?php endif; ?>
</table>

<h2>C. Curriculum</h2>

<h3>Curriculum Structure</h3>
<table>
    <tr>
        <th>Category</th>
        <th>Required / Elective</th>
        <th>No. of Courses</th>
        <th>Credit Hours</th>
        <th>Percentage</th>
        <th>Notes</th>
    </tr>

    <?php if (count($curriculumRows) > 0): ?>
        <?php foreach ($curriculumRows as $row): ?>
            <tr>
                <td><?php echo valueOrDash($row["structure_category"] ?? ""); ?></td>
                <td><?php echo valueOrDash($row["requirement_type"] ?? ""); ?></td>
                <td><?php echo valueOrDash($row["number_of_courses"] ?? ""); ?></td>
                <td><?php echo valueOrDash($row["credit_hours"] ?? ""); ?></td>
                <td><?php echo valueOrDash($row["percentage"] ?? ""); ?>%</td>
                <td><?php echo wordText($row["notes"] ?? ""); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="6">No curriculum structure rows recorded.</td></tr>
    <?php endif; ?>
</table>

<div class="summary-box">
    <strong>Imported Program Courses:</strong> <?php echo count($courses); ?><br>
    <strong>Total Imported Course Credits:</strong> <?php echo safeText($totalCourseCredits); ?><br>
    <strong>Declared Program Credits:</strong> <?php echo valueOrDash($spec["total_credit_hours"] ?? ""); ?>
</div>

<h3>Program Courses</h3>
<table>
    <tr>
        <th>Level</th>
        <th>Course Code</th>
        <th>Course Title</th>
        <th>Required / Elective</th>
        <th>Prerequisites</th>
        <th>Credit Hours</th>
        <th>Requirement Type</th>
    </tr>

    <?php if (count($courses) > 0): ?>
        <?php foreach ($courses as $course): ?>
            <tr>
                <td><?php echo valueOrDash($course["level_no"] ?? ""); ?></td>
                <td><?php echo valueOrDash($course["course_code"] ?? ""); ?></td>
                <td><?php echo valueOrDash($course["course_title"] ?? ""); ?></td>
                <td><?php echo valueOrDash($course["required_or_elective"] ?? ""); ?></td>
                <td><?php echo wordText($course["prerequisite_courses"] ?? ""); ?></td>
                <td><?php echo valueOrDash($course["credit_hours"] ?? ""); ?></td>
                <td><?php echo valueOrDash($course["requirement_type"] ?? ""); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="7">No program courses imported.</td></tr>
    <?php endif; ?>
</table>

<h3>PLO-Course Mapping Matrix</h3>
<p><strong>I</strong> = Introduced, <strong>P</strong> = Practiced, <strong>M</strong> = Mastered</p>

<table>
    <tr>
        <th>Level</th>
        <th>Course Code</th>
        <th>Course Title</th>
        <?php foreach ($plos as $plo): ?>
            <th><?php echo valueOrDash($plo["plo_code"] ?? ""); ?></th>
        <?php endforeach; ?>
    </tr>

    <?php if (count($courses) > 0 && count($plos) > 0): ?>
        <?php foreach ($courses as $course): ?>
            <?php $courseCode = strtoupper(trim((string)($course["course_code"] ?? ""))); ?>
            <tr>
                <td><?php echo valueOrDash($course["level_no"] ?? ""); ?></td>
                <td><?php echo valueOrDash($course["course_code"] ?? ""); ?></td>
                <td><?php echo valueOrDash($course["course_title"] ?? ""); ?></td>

                <?php foreach ($plos as $plo): ?>
                    <?php
                    $ploCode = strtoupper(trim((string)($plo["plo_code"] ?? "")));
                    $level = $mappings[$courseCode][$ploCode] ?? "";
                    ?>
                    <td class="center"><?php echo valueOrDash($level); ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="<?php echo 3 + count($plos); ?>">No mapping matrix available.</td>
        </tr>
    <?php endif; ?>
</table>

<h3>Teaching Strategies</h3>
<table>
    <tr>
        <th>Strategy</th>
        <th>Description</th>
        <th>Related PLOs</th>
        <th>Curricular / Extra</th>
        <th>Notes</th>
    </tr>

    <?php if (count($teachingRows) > 0): ?>
        <?php foreach ($teachingRows as $row): ?>
            <tr>
                <td><?php echo valueOrDash($row["strategy_name"] ?? ""); ?></td>
                <td><?php echo wordText($row["strategy_description"] ?? ""); ?></td>
                <td><?php echo valueOrDash($row["related_plo_codes"] ?? ""); ?></td>
                <td><?php echo valueOrDash($row["curricular_or_extra"] ?? ""); ?></td>
                <td><?php echo wordText($row["notes"] ?? ""); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="5">No teaching strategies recorded.</td></tr>
    <?php endif; ?>
</table>

<h3>Assessment Methods</h3>
<table>
    <tr>
        <th>Assessment Method</th>
        <th>Assessment Type</th>
        <th>Description</th>
        <th>Related PLOs</th>
        <th>Notes</th>
    </tr>

    <?php if (count($assessmentRows) > 0): ?>
        <?php foreach ($assessmentRows as $row): ?>
            <tr>
                <td><?php echo valueOrDash($row["assessment_method"] ?? ""); ?></td>
                <td><?php echo valueOrDash($row["assessment_type"] ?? ""); ?></td>
                <td><?php echo wordText($row["assessment_description"] ?? ""); ?></td>
                <td><?php echo valueOrDash($row["related_plo_codes"] ?? ""); ?></td>
                <td><?php echo wordText($row["notes"] ?? ""); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="5">No assessment methods recorded.</td></tr>
    <?php endif; ?>
</table>

<h3>Mark Distribution</h3>
<table>
    <tr>
        <th>Course Type</th>
        <th>Assessment Method</th>
        <th>Marks</th>
        <th>Percentage</th>
        <th>Notes</th>
    </tr>

    <?php if (count($markRows) > 0): ?>
        <?php
        $currentType = "";
        ?>
        <?php foreach ($markRows as $row): ?>
            <?php if ($currentType !== ($row["course_type"] ?? "")): ?>
                <?php
                $currentType = $row["course_type"] ?? "";
                $typeTotal = $markTotals[$currentType] ?? 0;
                ?>
                <tr class="total-row">
                    <td colspan="5"><?php echo valueOrDash($currentType); ?> | Total Marks: <?php echo safeText($typeTotal); ?></td>
                </tr>
            <?php endif; ?>

            <tr>
                <td><?php echo valueOrDash($row["course_type"] ?? ""); ?></td>
                <td><?php echo valueOrDash($row["assessment_method"] ?? ""); ?></td>
                <td><?php echo valueOrDash($row["marks"] ?? ""); ?></td>
                <td><?php echo valueOrDash($row["percentage"] ?? ""); ?>%</td>
                <td><?php echo wordText($row["notes"] ?? ""); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="5">No mark distribution rows recorded.</td></tr>
    <?php endif; ?>
</table>

<?php sectionText("Teaching and Learning Strategies Narrative", $spec["teaching_learning_strategy"] ?? ""); ?>
<?php sectionText("Assessment Strategy Narrative", $spec["assessment_strategy"] ?? ""); ?>

<h2>D. Student Admission and Support</h2>

<?php sectionText("Admission Requirements", $spec["admission_requirements"] ?? ""); ?>
<?php sectionText("Graduation Requirements", $spec["graduation_requirements"] ?? ""); ?>
<?php sectionText("Guidance and Orientation Programs", $spec["guidance_orientation"] ?? ""); ?>
<?php sectionText("Student Counseling Services", $spec["counseling_services"] ?? ""); ?>
<?php sectionText("Special Support", $spec["special_support"] ?? ""); ?>

<h2>E. Teaching Staff and Administrative Staff</h2>

<h3>Staffing Needs</h3>
<table>
    <tr>
        <th>Academic Rank / Role</th>
        <th>General Specialty</th>
        <th>Specific Specialty</th>
        <th>Special Requirements</th>
        <th>Male</th>
        <th>Female</th>
        <th>Total</th>
        <th>Notes</th>
    </tr>

    <?php if (count($staffingRows) > 0): ?>
        <?php foreach ($staffingRows as $row): ?>
            <tr>
                <td><?php echo valueOrDash($row["academic_rank"] ?? ""); ?></td>
                <td><?php echo wordText($row["general_specialty"] ?? ""); ?></td>
                <td><?php echo wordText($row["specific_specialty"] ?? ""); ?></td>
                <td><?php echo wordText($row["special_requirements"] ?? ""); ?></td>
                <td class="center"><?php echo valueOrDash($row["required_male"] ?? "0"); ?></td>
                <td class="center"><?php echo valueOrDash($row["required_female"] ?? "0"); ?></td>
                <td class="center"><?php echo valueOrDash($row["required_total"] ?? "0"); ?></td>
                <td><?php echo wordText($row["notes"] ?? ""); ?></td>
            </tr>
        <?php endforeach; ?>

        <tr class="total-row">
            <td colspan="4">Total Required Staff</td>
            <td class="center"><?php echo safeText($totalStaffMale); ?></td>
            <td class="center"><?php echo safeText($totalStaffFemale); ?></td>
            <td class="center"><?php echo safeText($totalStaffRequired); ?></td>
            <td></td>
        </tr>
    <?php else: ?>
        <tr><td colspan="8">No staffing needs recorded.</td></tr>
    <?php endif; ?>
</table>

<h2>F. Learning Resources, Facilities, and Equipment</h2>

<?php sectionText("Learning Resources", $spec["learning_resources"] ?? ""); ?>
<?php sectionText("Facilities and Equipment", $spec["facilities_equipment"] ?? ""); ?>
<?php sectionText("Healthy and Safe Learning Environment", $spec["healthy_safe_environment"] ?? ""); ?>

<h2>G. Program Quality Assurance</h2>

<?php sectionText("Quality Assurance Arrangements", $spec["quality_assurance_arrangements"] ?? ""); ?>
<?php sectionText("Quality Assurance System", $spec["quality_assurance_system"] ?? ""); ?>
<?php sectionText("Procedures to Monitor Courses Taught by Other Departments", $spec["quality_courses_other_departments"] ?? ""); ?>
<?php sectionText("Consistency Between Main Campus and Sections", $spec["consistency_between_sections"] ?? ""); ?>
<?php sectionText("PLO Assessment Plan", $spec["plo_assessment_plan"] ?? ""); ?>

<table>
    <tr>
        <th>Quality Manual Reference</th>
        <td><?php echo valueOrDash($spec["quality_manual_reference"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>PLO Assessment Plan Reference</th>
        <td><?php echo valueOrDash($spec["plo_assessment_plan_reference"] ?? ""); ?></td>
    </tr>
</table>

<h3>Program Evaluation Matrix</h3>
<table>
    <tr>
        <th>Evaluation Area</th>
        <th>Evaluation Sources</th>
        <th>Evaluation Methods</th>
        <th>Evaluation Time</th>
        <th>Notes</th>
    </tr>

    <?php if (count($evaluationRows) > 0): ?>
        <?php foreach ($evaluationRows as $row): ?>
            <tr>
                <td><?php echo valueOrDash($row["evaluation_area"] ?? ""); ?></td>
                <td><?php echo wordText($row["evaluation_sources"] ?? ""); ?></td>
                <td><?php echo wordText($row["evaluation_methods"] ?? ""); ?></td>
                <td><?php echo valueOrDash($row["evaluation_time"] ?? ""); ?></td>
                <td><?php echo wordText($row["notes"] ?? ""); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="5">No evaluation matrix rows recorded.</td></tr>
    <?php endif; ?>
</table>

<h3>Program KPIs</h3>
<table>
    <tr>
        <th>No.</th>
        <th>Code</th>
        <th>KPI</th>
        <th>Target</th>
        <th>Measurement Methods</th>
        <th>Measurement Time</th>
        <th>Type</th>
    </tr>

    <?php if (count($kpis) > 0): ?>
        <?php foreach ($kpis as $kpi): ?>
            <tr>
                <td><?php echo valueOrDash($kpi["kpi_no"] ?? ""); ?></td>
                <td><?php echo valueOrDash($kpi["kpi_code"] ?? ""); ?></td>
                <td><?php echo valueOrDash($kpi["kpi_name"] ?? ""); ?></td>
                <td><?php echo valueOrDash($kpi["target_value"] ?? ""); ?></td>
                <td><?php echo wordText($kpi["measurement_methods"] ?? ""); ?></td>
                <td><?php echo valueOrDash($kpi["measurement_time"] ?? ""); ?></td>
                <td><?php echo valueOrDash($kpi["kpi_type"] ?? ""); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="7">No KPIs recorded.</td></tr>
    <?php endif; ?>
</table>

<h2>H. Specification Approval Data</h2>

<table>
    <tr>
        <th>Approval Council / Committee</th>
        <td><?php echo valueOrDash($spec["approval_council_committee"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Approval Reference No.</th>
        <td><?php echo valueOrDash($spec["approval_reference_no"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Approval Date Text</th>
        <td><?php echo valueOrDash($spec["approval_date_text"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Approval Date</th>
        <td><?php echo valueOrDash($spec["approval_date"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Previous Version File</th>
        <td><?php echo valueOrDash($spec["previous_version_file"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Status</th>
        <td><?php echo valueOrDash($spec["approval_status_value"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Notes</th>
        <td><?php echo wordText($spec["notes"] ?? ""); ?></td>
    </tr>
</table>

</body>
</html>