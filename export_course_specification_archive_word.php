<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

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

function countRowsForCourseSpec($conn, $tableName, $courseSpecId) {
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
    $stmt->execute([":id" => $courseSpecId]);

    return (int)$stmt->fetchColumn();
}

function sumColumnForCourseSpec($conn, $tableName, $columnName, $courseSpecId) {
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
    $stmt->execute([":id" => $courseSpecId]);

    return (float)$stmt->fetchColumn();
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "course_specification_archive")) {
    die("course_specification_archive table does not exist.");
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

$archiveStmt = $conn->prepare("
    SELECT *
    FROM course_specification_archive
    WHERE program_specification_id = :program_specification_id
    ORDER BY archive_date DESC, id DESC
");
$archiveStmt->execute([":program_specification_id" => $program_specification_id]);
$archiveRows = $archiveStmt->fetchAll(PDO::FETCH_ASSOC);

/*
    Current live package status.
*/
$totalProgramCourses = 0;

if (tableExists($conn, "program_specification_courses")) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM program_specification_courses
        WHERE program_specification_id = :program_specification_id
    ");
    $stmt->execute([":program_specification_id" => $program_specification_id]);
    $totalProgramCourses = (int)$stmt->fetchColumn();
}

$courseSpecs = [];

if (tableExists($conn, "course_specifications")) {
    $stmt = $conn->prepare("
        SELECT *
        FROM course_specifications
        WHERE program_specification_id = :program_specification_id
        ORDER BY course_code ASC, id ASC
    ");
    $stmt->execute([":program_specification_id" => $program_specification_id]);
    $courseSpecs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalCourseSpecifications = count($courseSpecs);

$uploadedFilesCount = 0;
$docxFilesCount = 0;
$pdfFilesCount = 0;

if (tableExists($conn, "course_specification_files")) {
    $stmt = $conn->prepare("
        SELECT file_type, COUNT(*) AS total
        FROM course_specification_files
        WHERE program_specification_id = :program_specification_id
        GROUP BY file_type
    ");
    $stmt->execute([":program_specification_id" => $program_specification_id]);
    $fileRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($fileRows as $fileRow) {
        $type = strtolower(trim((string)($fileRow["file_type"] ?? "")));
        $count = (int)($fileRow["total"] ?? 0);

        $uploadedFilesCount += $count;

        if ($type === "docx") {
            $docxFilesCount += $count;
        } elseif ($type === "pdf") {
            $pdfFilesCount += $count;
        }
    }
}

$courseStatusRows = [];
$importedCoursesCount = 0;
$readyForExportCount = 0;

foreach ($courseSpecs as $cs) {
    $courseSpecId = (int)$cs["id"];

    $cloCount = countRowsForCourseSpec($conn, "course_specification_clos", $courseSpecId);
    $topicCount = countRowsForCourseSpec($conn, "course_specification_topics", $courseSpecId);
    $assessmentCount = countRowsForCourseSpec($conn, "course_specification_assessments", $courseSpecId);
    $resourceCount = countRowsForCourseSpec($conn, "course_specification_resources", $courseSpecId);
    $facilityCount = countRowsForCourseSpec($conn, "course_specification_facilities", $courseSpecId);
    $qualityCount = countRowsForCourseSpec($conn, "course_specification_quality", $courseSpecId);
    $approvalCount = countRowsForCourseSpec($conn, "course_specification_approval", $courseSpecId);
    $assessmentPercent = sumColumnForCourseSpec($conn, "course_specification_assessments", "percentage", $courseSpecId);

    $coreImported = (
        $cloCount > 0 &&
        $topicCount > 0 &&
        $assessmentCount > 0
    );

    $ready = (
        $coreImported &&
        abs($assessmentPercent - 100) <= 0.01 &&
        $resourceCount > 0 &&
        $facilityCount > 0 &&
        $qualityCount > 0 &&
        $approvalCount > 0
    );

    if ($coreImported) {
        $importedCoursesCount++;
    }

    if ($ready) {
        $readyForExportCount++;
    }

    $status = "Incomplete";

    if ($ready) {
        $status = "Ready";
    } elseif ($coreImported) {
        $status = "Core Imported";
    }

    $courseStatusRows[] = [
        "id" => $courseSpecId,
        "course_code" => $cs["course_code"] ?? "",
        "course_title" => $cs["course_title"] ?? "",
        "credit_hours" => $cs["credit_hours"] ?? "",
        "level_year" => $cs["course_level_year"] ?? "",
        "clo_count" => $cloCount,
        "topic_count" => $topicCount,
        "assessment_count" => $assessmentCount,
        "resource_count" => $resourceCount,
        "facility_count" => $facilityCount,
        "quality_count" => $qualityCount,
        "approval_count" => $approvalCount,
        "assessment_percent" => $assessmentPercent,
        "status" => $status
    ];
}

$incompleteCoursesCount = max($totalProgramCourses - $readyForExportCount, 0);
$totalArchives = count($archiveRows);
$finalArchives = 0;

foreach ($archiveRows as $row) {
    if (strtolower(trim((string)($row["package_status"] ?? ""))) === "final") {
        $finalArchives++;
    }
}

$programCode = $programSpec["program_code"] ?? "program";
$programCode = preg_replace('/[^A-Za-z0-9_\-]/', '_', $programCode);

$filename = "course_specification_archive_" . $programCode . "_" . date("Y-m-d_H-i-s") . ".doc";

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
    <title>Course Specification Archive Report</title>

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

        .complete {
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
    <h1>Course Specification Archive Report</h1>
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

<h2>2. Current Course Specification Package Summary</h2>

<table>
    <tr>
        <th>Total Program Courses</th>
        <th>Course Specification Records</th>
        <th>Uploaded Files</th>
        <th>DOCX Files</th>
        <th>PDF Files</th>
        <th>Imported Courses</th>
        <th>Ready for Export</th>
        <th>Incomplete</th>
    </tr>
    <tr>
        <td class="center"><?php echo safeText($totalProgramCourses); ?></td>
        <td class="center"><?php echo safeText($totalCourseSpecifications); ?></td>
        <td class="center"><?php echo safeText($uploadedFilesCount); ?></td>
        <td class="center"><?php echo safeText($docxFilesCount); ?></td>
        <td class="center"><?php echo safeText($pdfFilesCount); ?></td>
        <td class="center warning"><?php echo safeText($importedCoursesCount); ?></td>
        <td class="center complete"><?php echo safeText($readyForExportCount); ?></td>
        <td class="center danger"><?php echo safeText($incompleteCoursesCount); ?></td>
    </tr>
</table>

<h2>3. Archive Summary</h2>

<table>
    <tr>
        <th>Total Archive Records</th>
        <th>Final Archive Records</th>
        <th>Latest Export Report Date</th>
    </tr>
    <tr>
        <td class="center"><?php echo safeText($totalArchives); ?></td>
        <td class="center complete"><?php echo safeText($finalArchives); ?></td>
        <td class="center"><?php echo date("Y-m-d H:i"); ?></td>
    </tr>
</table>

<h2>4. Archive Records</h2>

<table>
    <tr>
        <th>No.</th>
        <th>Archive Title</th>
        <th>Version</th>
        <th>Archive Date</th>
        <th>Package Status</th>
        <th>Program Courses</th>
        <th>Course Specs</th>
        <th>Files</th>
        <th>Imported</th>
        <th>Ready</th>
        <th>Incomplete</th>
        <th>ZIP Exported</th>
        <th>ZIP Date</th>
        <th>Review Status</th>
        <th>Approval Status</th>
        <th>Notes</th>
    </tr>

    <?php if (count($archiveRows) > 0): ?>
        <?php $i = 1; ?>
        <?php foreach ($archiveRows as $row): ?>
            <?php
            $statusClass = "warning";
            if (($row["package_status"] ?? "") === "Final" || ($row["approval_status"] ?? "") === "Approved") {
                $statusClass = "complete";
            }
            ?>
            <tr>
                <td class="center"><?php echo safeText($i); ?></td>
                <td><?php echo safeText($row["archive_title"] ?? ""); ?></td>
                <td><?php echo safeText($row["archive_version"] ?? ""); ?></td>
                <td><?php echo safeText($row["archive_date"] ?? ""); ?></td>
                <td class="<?php echo safeText($statusClass); ?>"><?php echo safeText($row["package_status"] ?? ""); ?></td>
                <td class="center"><?php echo safeText($row["total_program_courses"] ?? "0"); ?></td>
                <td class="center"><?php echo safeText($row["total_course_specifications"] ?? "0"); ?></td>
                <td class="center">
                    <?php echo safeText($row["uploaded_files_count"] ?? "0"); ?><br>
                    <span class="small">
                        DOCX: <?php echo safeText($row["docx_files_count"] ?? "0"); ?> /
                        PDF: <?php echo safeText($row["pdf_files_count"] ?? "0"); ?>
                    </span>
                </td>
                <td class="center"><?php echo safeText($row["imported_courses_count"] ?? "0"); ?></td>
                <td class="center complete"><?php echo safeText($row["ready_for_export_count"] ?? "0"); ?></td>
                <td class="center danger"><?php echo safeText($row["incomplete_courses_count"] ?? "0"); ?></td>
                <td class="center"><?php echo (int)($row["bulk_zip_exported"] ?? 0) === 1 ? "Yes" : "No"; ?></td>
                <td><?php echo safeText($row["bulk_zip_export_date"] ?? ""); ?></td>
                <td><?php echo safeText($row["review_status"] ?? ""); ?></td>
                <td><?php echo safeText($row["approval_status"] ?? ""); ?></td>
                <td><?php echo wordText($row["archive_notes"] ?? ""); ?></td>
            </tr>
            <?php $i++; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="16" class="danger">
                No course specification archive records have been created yet.
            </td>
        </tr>
    <?php endif; ?>
</table>

<h2>5. Current Course-Level Readiness</h2>

<table>
    <tr>
        <th>No.</th>
        <th>Course Code</th>
        <th>Course Title</th>
        <th>Credit Hours</th>
        <th>Level / Year</th>
        <th>CLOs</th>
        <th>Topics</th>
        <th>Assessments</th>
        <th>Assessment %</th>
        <th>Resources</th>
        <th>Facilities</th>
        <th>Quality</th>
        <th>Approval</th>
        <th>Status</th>
    </tr>

    <?php if (count($courseStatusRows) > 0): ?>
        <?php $i = 1; ?>
        <?php foreach ($courseStatusRows as $course): ?>
            <?php
            $courseClass = "danger";
            if ($course["status"] === "Ready") {
                $courseClass = "complete";
            } elseif ($course["status"] === "Core Imported") {
                $courseClass = "warning";
            }
            ?>
            <tr>
                <td class="center"><?php echo safeText($i); ?></td>
                <td><?php echo safeText($course["course_code"]); ?></td>
                <td><?php echo safeText($course["course_title"]); ?></td>
                <td class="center"><?php echo safeText($course["credit_hours"]); ?></td>
                <td><?php echo safeText($course["level_year"]); ?></td>
                <td class="center"><?php echo safeText($course["clo_count"]); ?></td>
                <td class="center"><?php echo safeText($course["topic_count"]); ?></td>
                <td class="center"><?php echo safeText($course["assessment_count"]); ?></td>
                <td class="center"><?php echo safeText($course["assessment_percent"]); ?>%</td>
                <td class="center"><?php echo safeText($course["resource_count"]); ?></td>
                <td class="center"><?php echo safeText($course["facility_count"]); ?></td>
                <td class="center"><?php echo safeText($course["quality_count"]); ?></td>
                <td class="center"><?php echo safeText($course["approval_count"]); ?></td>
                <td class="<?php echo safeText($courseClass); ?>"><?php echo safeText($course["status"]); ?></td>
            </tr>
            <?php $i++; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="14" class="danger">
                No course specification records found.
            </td>
        </tr>
    <?php endif; ?>
</table>

<h2>6. Archive Interpretation</h2>

<table>
    <tr>
        <th>Current Package Status</th>
        <td>
            <?php if ($readyForExportCount > 0): ?>
                The course specification package contains course specifications ready for export. The package should be reviewed before being marked as final.
            <?php else: ?>
                No course specifications are currently ready for export. Upload and import DOCX course specifications first.
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th>Recommended Action</th>
        <td>
            <?php if ($incompleteCoursesCount > 0): ?>
                Complete the missing or incomplete course specifications, confirm assessment totals equal 100%, then export the ZIP package and create a final archive record.
            <?php else: ?>
                Export the final ZIP package and mark the archive record as Final / Approved.
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th>Generated By</th>
        <td>Quality System - Course Specification Module</td>
    </tr>
</table>

</body>
</html>