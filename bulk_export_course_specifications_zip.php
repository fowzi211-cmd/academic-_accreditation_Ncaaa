<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

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

function cleanExportFilename($filename) {
    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);
    $filename = preg_replace('/_+/', '_', $filename);
    return trim(substr($filename, 0, 130), "_");
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

function buildCourseSpecificationWordHtml($conn, $courseSpec) {
    $course_specification_id = (int)$courseSpec["id"];

    $clos = loadRows($conn, "course_specification_clos", $course_specification_id, "display_order ASC, id ASC");
    $topics = loadRows($conn, "course_specification_topics", $course_specification_id, "display_order ASC, topic_no ASC, id ASC");
    $assessments = loadRows($conn, "course_specification_assessments", $course_specification_id, "display_order ASC, assessment_no ASC, id ASC");
    $resources = loadRows($conn, "course_specification_resources", $course_specification_id, "display_order ASC, id ASC");
    $facilities = loadRows($conn, "course_specification_facilities", $course_specification_id, "display_order ASC, id ASC");
    $qualityRows = loadRows($conn, "course_specification_quality", $course_specification_id, "display_order ASC, id ASC");
    $approvalRows = loadRows($conn, "course_specification_approval", $course_specification_id, "id ASC");

    $totalTopicHours = sumColumn($conn, "course_specification_topics", "contact_hours", $course_specification_id);
    $totalAssessmentPercentage = sumColumn($conn, "course_specification_assessments", "percentage", $course_specification_id);

    ob_start();
    echo "\xEF\xBB\xBF";
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Specification</title>

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
    <h1>Course Specification</h1>
    <p><strong><?php echo safeText($courseSpec["course_code"] ?? ""); ?> - <?php echo safeText($courseSpec["course_title"] ?? ""); ?></strong></p>
    <p><?php echo safeText($courseSpec["college_name"] ?? ""); ?></p>
    <p><?php echo safeText($courseSpec["institution_name"] ?? ""); ?></p>
    <p>Generated on: <?php echo date("Y-m-d H:i"); ?></p>
</div>

<h2>Course Identification</h2>

<table>
    <tr>
        <th>Course Title</th>
        <td><?php echo safeText($courseSpec["course_title"] ?? ""); ?></td>
        <th>Course Code</th>
        <td><?php echo safeText($courseSpec["course_code"] ?? ""); ?></td>
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
        <th>Version</th>
        <td><?php echo safeText($courseSpec["version_no"] ?? ""); ?></td>
        <th>Last Revision Date</th>
        <td><?php echo safeText($courseSpec["last_revision_date_text"] ?? ($courseSpec["last_revision_date"] ?? "")); ?></td>
    </tr>
</table>

<h2>A. General Information About the Course</h2>

<h3>1. Course Identification</h3>

<table>
    <tr>
        <th>Credit Hours</th>
        <td><?php echo safeText($courseSpec["credit_hours"] ?? ""); ?></td>
        <th>Theoretical Hours</th>
        <td><?php echo safeText($courseSpec["theoretical_hours"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Level / Year</th>
        <td><?php echo safeText($courseSpec["course_level_year"] ?? ""); ?></td>
        <th>Course Type</th>
        <td><?php echo safeText($courseSpec["course_type_scope"] ?? ""); ?> / <?php echo safeText($courseSpec["requirement_type"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Pre-requisites</th>
        <td><?php echo wordText($courseSpec["pre_requisites"] ?? ""); ?></td>
        <th>Co-requisites</th>
        <td><?php echo wordText($courseSpec["co_requisites"] ?? ""); ?></td>
    </tr>
</table>

<h3>Course General Description</h3>
<p><?php echo wordText($courseSpec["course_general_description"] ?? ""); ?></p>

<h3>Course Main Objective(s)</h3>
<p><?php echo wordText($courseSpec["course_main_objectives"] ?? ""); ?></p>

<h3>2. Contact Hours</h3>

<table>
    <tr>
        <th>Activity</th>
        <th>Contact Hours</th>
    </tr>
    <tr>
        <td>Lectures</td>
        <td class="center"><?php echo safeText($courseSpec["contact_lectures"] ?? "0"); ?></td>
    </tr>
    <tr>
        <td>Laboratory / Studio</td>
        <td class="center"><?php echo safeText($courseSpec["contact_laboratory"] ?? "0"); ?></td>
    </tr>
    <tr>
        <td>Field</td>
        <td class="center"><?php echo safeText($courseSpec["contact_field"] ?? "0"); ?></td>
    </tr>
    <tr>
        <td>Tutorial</td>
        <td class="center"><?php echo safeText($courseSpec["contact_tutorial"] ?? "0"); ?></td>
    </tr>
    <tr>
        <td>Others</td>
        <td class="center"><?php echo safeText($courseSpec["contact_others"] ?? "0"); ?></td>
    </tr>
    <tr class="total-row">
        <td>Total</td>
        <td class="center"><?php echo safeText($courseSpec["contact_total"] ?? "0"); ?></td>
    </tr>
</table>

<h2>B. Course Learning Outcomes, Teaching Strategies and Assessment Methods</h2>

<table>
    <tr>
        <th>Code</th>
        <th>Course Learning Outcomes</th>
        <th>Code of PLOs Aligned with the Program</th>
        <th>Teaching Strategies</th>
        <th>Assessment Methods</th>
    </tr>

    <?php if (count($clos) > 0): ?>
        <?php $currentDomain = ""; ?>
        <?php foreach ($clos as $clo): ?>
            <?php
            $domain = trim((string)($clo["clo_domain"] ?? ""));
            if ($domain !== "" && $domain !== $currentDomain):
                $currentDomain = $domain;
            ?>
                <tr class="domain-row">
                    <td colspan="5"><?php echo safeText($currentDomain); ?></td>
                </tr>
            <?php endif; ?>

            <tr>
                <td class="center"><?php echo safeText($clo["clo_code"] ?? ""); ?></td>
                <td><?php echo wordText($clo["clo_text"] ?? ""); ?></td>
                <td class="center"><?php echo safeText($clo["aligned_plo_code"] ?? ""); ?></td>
                <td><?php echo wordText($clo["teaching_strategies"] ?? ""); ?></td>
                <td><?php echo wordText($clo["assessment_methods"] ?? ""); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="5">No CLOs imported.</td>
        </tr>
    <?php endif; ?>
</table>

<h2>C. Course Content</h2>

<table>
    <tr>
        <th>No.</th>
        <th>List of Topics</th>
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
            <td colspan="3">No course topics imported.</td>
        </tr>
    <?php endif; ?>
</table>

<h2>D. Students Assessment Activities</h2>

<table>
    <tr>
        <th>No.</th>
        <th>Assessment Activities</th>
        <th>Assessment Timing<br><span class="small">(in week no)</span></th>
        <th>Percentage of Total Assessment Score</th>
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
            <td colspan="4">No assessment activities imported.</td>
        </tr>
    <?php endif; ?>
</table>

<h2>E. Learning Resources and Facilities</h2>

<h3>1. References and Learning Resources</h3>

<table>
    <tr>
        <th>Resource Category</th>
        <th>Resource Details</th>
    </tr>

    <?php if (count($resources) > 0): ?>
        <?php foreach ($resources as $resource): ?>
            <tr>
                <td>
                    <?php
                    $resourceCategory = $resource["resource_category"] ?? ($resource["resource_type"] ?? "");
                    echo safeText($resourceCategory);
                    ?>
                </td>
                <td><?php echo wordText($resource["resource_details"] ?? ""); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="2">No learning resources imported.</td>
        </tr>
    <?php endif; ?>
</table>

<h3>2. Required Facilities and Equipment</h3>

<table>
    <tr>
        <th>Items</th>
        <th>Resources</th>
    </tr>

    <?php if (count($facilities) > 0): ?>
        <?php foreach ($facilities as $facility): ?>
            <tr>
                <td>
                    <?php
                    $facilityCategory = $facility["facility_category"] ?? ($facility["facility_item"] ?? "");
                    echo safeText($facilityCategory);
                    ?>
                </td>
                <td><?php echo wordText($facility["required_resources"] ?? ""); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="2">No facilities imported.</td>
        </tr>
    <?php endif; ?>
</table>

<h2>F. Assessment of Course Quality</h2>

<table>
    <tr>
        <th>Assessment Areas / Issues</th>
        <th>Assessor</th>
        <th>Assessment Methods</th>
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
            <td colspan="3">No course quality assessment rows imported.</td>
        </tr>
    <?php endif; ?>
</table>

<h2>G. Specification Approval</h2>

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

</body>
</html>
    <?php
    return ob_get_clean();
}

if (!class_exists("ZipArchive")) {
    die("ZipArchive is not enabled in PHP. Enable zip extension in php.ini.");
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "course_specifications")) {
    die("course_specifications table does not exist.");
}

$programStmt = $conn->prepare("
    SELECT *
    FROM program_specifications
    WHERE id = :id
    LIMIT 1
");
$programStmt->execute([":id" => $program_specification_id]);
$programSpec = $programStmt->fetch(PDO::FETCH_ASSOC);

if (!$programSpec) {
    die("Program specification not found.");
}

/*
    Include only core-ready courses:
    CLOs > 0, topics > 0, assessments > 0, and assessment total = 100%.
*/
$courseStmt = $conn->prepare("
    SELECT *
    FROM course_specifications
    WHERE program_specification_id = :program_specification_id
    ORDER BY course_code ASC, id ASC
");
$courseStmt->execute([":program_specification_id" => $program_specification_id]);
$courseSpecs = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

$readyCourses = [];

foreach ($courseSpecs as $courseSpec) {
    $courseSpecId = (int)$courseSpec["id"];

    $cloCount = countRows($conn, "course_specification_clos", $courseSpecId);
    $topicCount = countRows($conn, "course_specification_topics", $courseSpecId);
    $assessmentCount = countRows($conn, "course_specification_assessments", $courseSpecId);
    $assessmentTotal = sumColumn($conn, "course_specification_assessments", "percentage", $courseSpecId);

    if ($cloCount > 0 && $topicCount > 0 && $assessmentCount > 0 && abs($assessmentTotal - 100) <= 0.01) {
        $readyCourses[] = $courseSpec;
    }
}

if (count($readyCourses) === 0) {
    die("No course specifications are ready for ZIP export. Import DOCX data first and make sure assessments total 100%.");
}

$tmpDir = ensureStorageDirectory('temp_exports');

$programCode = $programSpec["program_code"] ?? "program";
$programCode = cleanExportFilename($programCode);

$zipFilename = "course_specifications_package_" . $programCode . "_" . date("Y-m-d_H-i-s") . ".zip";
$zipPath = $tmpDir . DIRECTORY_SEPARATOR . $zipFilename;

$zip = new ZipArchive();

if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Could not create ZIP file.");
}

$exportedCount = 0;

foreach ($readyCourses as $courseSpec) {
    $courseCode = $courseSpec["course_code"] ?? "course";
    $courseTitle = $courseSpec["course_title"] ?? "course_specification";

    $docFilenameBase = cleanExportFilename($courseCode . "_" . $courseTitle);
    $docFilename = $docFilenameBase . ".doc";

    $html = buildCourseSpecificationWordHtml($conn, $courseSpec);

    $zip->addFromString($docFilename, $html);
    $exportedCount++;
}

$manifest = "Course Specifications ZIP Package\n";
$manifest .= "Program Specification ID: " . $program_specification_id . "\n";
$manifest .= "Program: " . ($programSpec["program_name"] ?? "") . "\n";
$manifest .= "Program Code: " . ($programSpec["program_code"] ?? "") . "\n";
$manifest .= "Generated: " . date("Y-m-d H:i:s") . "\n";
$manifest .= "Exported Courses: " . $exportedCount . "\n\n";
$manifest .= "Files included:\n";

foreach ($readyCourses as $courseSpec) {
    $manifest .= "- " . ($courseSpec["course_code"] ?? "") . " - " . ($courseSpec["course_title"] ?? "") . "\n";
}

$zip->addFromString("README_export_manifest.txt", $manifest);
$zip->close();

logActivitySafe(
    $conn,
    "Bulk Export",
    "Course Specifications ZIP",
    $program_specification_id,
    "Exported " . $exportedCount . " course specifications as ZIP package."
);

header("Content-Type: application/zip");
header("Content-Disposition: attachment; filename=\"$zipFilename\"");
header("Content-Length: " . filesize($zipPath));
header("Pragma: no-cache");
header("Expires: 0");

readfile($zipPath);

/*
    Clean up generated zip after sending.
*/
@unlink($zipPath);
exit;
