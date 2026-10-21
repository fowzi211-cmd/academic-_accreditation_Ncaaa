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

function getFileCounts($conn, $courseSpecId) {
    $counts = [
        "total" => 0,
        "docx" => 0,
        "pdf" => 0,
        "doc" => 0
    ];

    if (!tableExists($conn, "course_specification_files")) {
        return $counts;
    }

    if (!columnExists($conn, "course_specification_files", "course_specification_id")) {
        return $counts;
    }

    $stmt = $conn->prepare("
        SELECT file_type, COUNT(*) AS total
        FROM course_specification_files
        WHERE course_specification_id = :course_specification_id
        GROUP BY file_type
    ");
    $stmt->execute([":course_specification_id" => $courseSpecId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $type = strtolower(trim((string)($row["file_type"] ?? "")));
        $count = (int)($row["total"] ?? 0);

        $counts["total"] += $count;

        if ($type === "docx") {
            $counts["docx"] += $count;
        } elseif ($type === "pdf") {
            $counts["pdf"] += $count;
        } elseif ($type === "doc") {
            $counts["doc"] += $count;
        }
    }

    return $counts;
}

function issuesToText($issues, $warnings) {
    $text = "";

    foreach ($issues as $issue) {
        $text .= "Issue: " . $issue . "\n";
    }

    foreach ($warnings as $warning) {
        $text .= "Warning: " . $warning . "\n";
    }

    if ($text === "") {
        $text = "OK";
    }

    return $text;
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
    Load study-plan courses.
*/
$programCourses = [];

if (tableExists($conn, "program_specification_courses")) {
    $stmt = $conn->prepare("
        SELECT *
        FROM program_specification_courses
        WHERE program_specification_id = :program_specification_id
        ORDER BY level_no ASC, course_code ASC, id ASC
    ");
    $stmt->execute([":program_specification_id" => $program_specification_id]);
    $programCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$programCoursesByCode = [];

foreach ($programCourses as $course) {
    $code = strtoupper(trim((string)($course["course_code"] ?? "")));

    if ($code !== "") {
        $programCoursesByCode[$code] = $course;
    }
}

/*
    Load course specification records.
*/
$stmt = $conn->prepare("
    SELECT *
    FROM course_specifications
    WHERE program_specification_id = :program_specification_id
    ORDER BY course_code ASC, id ASC
");
$stmt->execute([":program_specification_id" => $program_specification_id]);
$courseSpecs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$courseSpecsByCode = [];

foreach ($courseSpecs as $cs) {
    $code = strtoupper(trim((string)($cs["course_code"] ?? "")));

    if ($code !== "") {
        $courseSpecsByCode[$code] = $cs;
    }
}

$validationRows = [];

/*
    Validate study-plan courses.
*/
foreach ($programCourses as $programCourse) {
    $courseCode = strtoupper(trim((string)($programCourse["course_code"] ?? "")));
    $courseTitle = $programCourse["course_title"] ?? "";

    $courseSpec = $courseSpecsByCode[$courseCode] ?? null;
    $courseSpecId = $courseSpec ? (int)$courseSpec["id"] : 0;

    $issues = [];
    $warnings = [];
    $passedChecks = 0;
    $totalChecks = 12;

    if ($courseSpecId > 0) {
        $passedChecks++;
    } else {
        $issues[] = "No course specification record linked to this study-plan course.";
    }

    if ($courseSpecId > 0 && trim((string)($courseSpec["course_code"] ?? "")) !== "") {
        $passedChecks++;
    } else {
        $issues[] = "Course code is missing.";
    }

    if ($courseSpecId > 0 && trim((string)($courseSpec["course_title"] ?? "")) !== "") {
        $passedChecks++;
    } else {
        $issues[] = "Course title is missing.";
    }

    $fileCounts = $courseSpecId > 0
        ? getFileCounts($conn, $courseSpecId)
        : ["total" => 0, "docx" => 0, "pdf" => 0, "doc" => 0];

    if ($fileCounts["total"] > 0) {
        $passedChecks++;
    } else {
        $issues[] = "No uploaded course specification file.";
    }

    if ($fileCounts["docx"] > 0) {
        $passedChecks++;
    } else {
        $warnings[] = "No DOCX file available for structured import.";
    }

    $cloCount = $courseSpecId > 0 ? countRowsForCourseSpec($conn, "course_specification_clos", $courseSpecId) : 0;
    $topicCount = $courseSpecId > 0 ? countRowsForCourseSpec($conn, "course_specification_topics", $courseSpecId) : 0;
    $assessmentCount = $courseSpecId > 0 ? countRowsForCourseSpec($conn, "course_specification_assessments", $courseSpecId) : 0;
    $resourceCount = $courseSpecId > 0 ? countRowsForCourseSpec($conn, "course_specification_resources", $courseSpecId) : 0;
    $facilityCount = $courseSpecId > 0 ? countRowsForCourseSpec($conn, "course_specification_facilities", $courseSpecId) : 0;
    $qualityCount = $courseSpecId > 0 ? countRowsForCourseSpec($conn, "course_specification_quality", $courseSpecId) : 0;
    $approvalCount = $courseSpecId > 0 ? countRowsForCourseSpec($conn, "course_specification_approval", $courseSpecId) : 0;

    $topicHours = $courseSpecId > 0 ? sumColumnForCourseSpec($conn, "course_specification_topics", "contact_hours", $courseSpecId) : 0;
    $assessmentPercent = $courseSpecId > 0 ? sumColumnForCourseSpec($conn, "course_specification_assessments", "percentage", $courseSpecId) : 0;

    if ($cloCount > 0) {
        $passedChecks++;
    } else {
        $issues[] = "No CLOs imported.";
    }

    if ($topicCount > 0) {
        $passedChecks++;
    } else {
        $issues[] = "No course topics imported.";
    }

    if ($topicHours > 0) {
        $passedChecks++;
    } else {
        $issues[] = "Topic contact hours are missing or zero.";
    }

    if ($assessmentCount > 0) {
        $passedChecks++;
    } else {
        $issues[] = "No assessment activities imported.";
    }

    if (abs($assessmentPercent - 100) <= 0.01) {
        $passedChecks++;
    } else {
        $issues[] = "Assessment total is not 100%. Current total: " . $assessmentPercent . "%.";
    }

    if ($resourceCount > 0) {
        $passedChecks++;
    } else {
        $warnings[] = "No learning resources imported.";
    }

    if ($facilityCount > 0) {
        $passedChecks++;
    } else {
        $warnings[] = "No facilities/equipment data imported.";
    }

    if ($qualityCount === 0) {
        $warnings[] = "No course quality assessment rows imported.";
    }

    if ($approvalCount === 0) {
        $warnings[] = "No approval data imported.";
    }

    $readinessPercent = $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 100, 1) : 0;

    $status = "Fail";

    if (count($issues) === 0 && count($warnings) === 0) {
        $status = "Pass";
    } elseif (count($issues) === 0 && count($warnings) > 0) {
        $status = "Pass with Warnings";
    } elseif ($readinessPercent >= 60) {
        $status = "Needs Correction";
    }

    $validationRows[] = [
        "source" => "Study Plan",
        "level_no" => $programCourse["level_no"] ?? "",
        "course_code" => $programCourse["course_code"] ?? "",
        "course_title" => $courseTitle,
        "credit_hours" => $programCourse["credit_hours"] ?? "",
        "course_specification_id" => $courseSpecId,
        "file_total" => $fileCounts["total"],
        "docx_count" => $fileCounts["docx"],
        "pdf_count" => $fileCounts["pdf"],
        "clo_count" => $cloCount,
        "topic_count" => $topicCount,
        "topic_hours" => $topicHours,
        "assessment_count" => $assessmentCount,
        "assessment_percent" => $assessmentPercent,
        "resource_count" => $resourceCount,
        "facility_count" => $facilityCount,
        "quality_count" => $qualityCount,
        "approval_count" => $approvalCount,
        "passed_checks" => $passedChecks,
        "total_checks" => $totalChecks,
        "readiness_percent" => $readinessPercent,
        "status" => $status,
        "issues" => $issues,
        "warnings" => $warnings
    ];
}

/*
    Add extra course specification records not in study plan.
*/
foreach ($courseSpecs as $cs) {
    $code = strtoupper(trim((string)($cs["course_code"] ?? "")));

    if ($code !== "" && isset($programCoursesByCode[$code])) {
        continue;
    }

    $courseSpecId = (int)$cs["id"];
    $fileCounts = getFileCounts($conn, $courseSpecId);

    $cloCount = countRowsForCourseSpec($conn, "course_specification_clos", $courseSpecId);
    $topicCount = countRowsForCourseSpec($conn, "course_specification_topics", $courseSpecId);
    $assessmentCount = countRowsForCourseSpec($conn, "course_specification_assessments", $courseSpecId);
    $resourceCount = countRowsForCourseSpec($conn, "course_specification_resources", $courseSpecId);
    $facilityCount = countRowsForCourseSpec($conn, "course_specification_facilities", $courseSpecId);
    $qualityCount = countRowsForCourseSpec($conn, "course_specification_quality", $courseSpecId);
    $approvalCount = countRowsForCourseSpec($conn, "course_specification_approval", $courseSpecId);
    $topicHours = sumColumnForCourseSpec($conn, "course_specification_topics", "contact_hours", $courseSpecId);
    $assessmentPercent = sumColumnForCourseSpec($conn, "course_specification_assessments", "percentage", $courseSpecId);

    $issues = ["Course specification record is not found in the imported program course list."];
    $warnings = [];

    if ($fileCounts["total"] === 0) {
        $warnings[] = "No uploaded file.";
    }

    if ($cloCount === 0) {
        $warnings[] = "No CLOs imported.";
    }

    if ($topicCount === 0) {
        $warnings[] = "No topics imported.";
    }

    if ($assessmentCount === 0) {
        $warnings[] = "No assessments imported.";
    }

    $validationRows[] = [
        "source" => "Extra Record",
        "level_no" => "",
        "course_code" => $cs["course_code"] ?? "",
        "course_title" => $cs["course_title"] ?? "",
        "credit_hours" => $cs["credit_hours"] ?? "",
        "course_specification_id" => $courseSpecId,
        "file_total" => $fileCounts["total"],
        "docx_count" => $fileCounts["docx"],
        "pdf_count" => $fileCounts["pdf"],
        "clo_count" => $cloCount,
        "topic_count" => $topicCount,
        "topic_hours" => $topicHours,
        "assessment_count" => $assessmentCount,
        "assessment_percent" => $assessmentPercent,
        "resource_count" => $resourceCount,
        "facility_count" => $facilityCount,
        "quality_count" => $qualityCount,
        "approval_count" => $approvalCount,
        "passed_checks" => 0,
        "total_checks" => 12,
        "readiness_percent" => 0,
        "status" => "Review",
        "issues" => $issues,
        "warnings" => $warnings
    ];
}

/*
    Summary.
*/
$totalRows = count($validationRows);
$passCount = 0;
$warningCount = 0;
$needsCorrectionCount = 0;
$failCount = 0;
$reviewCount = 0;

foreach ($validationRows as $row) {
    if ($row["status"] === "Pass") {
        $passCount++;
    } elseif ($row["status"] === "Pass with Warnings") {
        $warningCount++;
    } elseif ($row["status"] === "Needs Correction") {
        $needsCorrectionCount++;
    } elseif ($row["status"] === "Review") {
        $reviewCount++;
    } else {
        $failCount++;
    }
}

$passPercent = $totalRows > 0 ? round((($passCount + $warningCount) / $totalRows) * 100, 1) : 0;

$overallDecision = "Not Ready";

if ($totalRows > 0 && $failCount === 0 && $needsCorrectionCount === 0 && $reviewCount === 0) {
    $overallDecision = "Ready";
} elseif (($passCount + $warningCount) > 0) {
    $overallDecision = "Partially Ready";
}

$programCode = $programSpec["program_code"] ?? "program";
$programCode = preg_replace('/[^A-Za-z0-9_\-]/', '_', $programCode);

$filename = "course_specification_validation_" . $programCode . "_" . date("Y-m-d_H-i-s") . ".doc";

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
    <title>Course Specification Validation Report</title>

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

        .pass {
            background: #d1e7dd;
            color: #0f5132;
            font-weight: bold;
        }

        .warning {
            background: #fff3cd;
            color: #664d03;
            font-weight: bold;
        }

        .fail {
            background: #f8d7da;
            color: #842029;
            font-weight: bold;
        }

        .review {
            background: #e2e3e5;
            color: #41464b;
            font-weight: bold;
        }

        .small {
            font-size: 9pt;
        }
    </style>
</head>

<body>

<div class="cover">
    <h1>Course Specification Validation Report</h1>
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

<h2>2. Validation Summary</h2>

<table>
    <tr>
        <th>Total Courses Checked</th>
        <th>Pass</th>
        <th>Pass with Warnings</th>
        <th>Needs Correction</th>
        <th>Fail</th>
        <th>Review</th>
        <th>Pass Rate</th>
        <th>Overall Decision</th>
    </tr>
    <tr>
        <td class="center"><?php echo safeText($totalRows); ?></td>
        <td class="center pass"><?php echo safeText($passCount); ?></td>
        <td class="center warning"><?php echo safeText($warningCount); ?></td>
        <td class="center warning"><?php echo safeText($needsCorrectionCount); ?></td>
        <td class="center fail"><?php echo safeText($failCount); ?></td>
        <td class="center review"><?php echo safeText($reviewCount); ?></td>
        <td class="center"><?php echo safeText($passPercent); ?>%</td>
        <td class="center"><strong><?php echo safeText($overallDecision); ?></strong></td>
    </tr>
</table>

<h2>3. Validation Rules Applied</h2>

<table>
    <tr>
        <th>Rule</th>
        <th>Expected Evidence</th>
    </tr>
    <tr>
        <td>Course record exists</td>
        <td>Every study-plan course should have a course specification record.</td>
    </tr>
    <tr>
        <td>Uploaded file exists</td>
        <td>Each course specification should have an official Word/PDF attachment.</td>
    </tr>
    <tr>
        <td>DOCX file exists</td>
        <td>DOCX is required for structured extraction into database tables.</td>
    </tr>
    <tr>
        <td>CLOs imported</td>
        <td>Course learning outcomes should be available and mapped to PLOs.</td>
    </tr>
    <tr>
        <td>Topics imported</td>
        <td>Course content and contact hours should be available.</td>
    </tr>
    <tr>
        <td>Assessments imported</td>
        <td>Assessment activities and percentages should be available.</td>
    </tr>
    <tr>
        <td>Assessment total equals 100%</td>
        <td>Assessment activities should total exactly 100%.</td>
    </tr>
    <tr>
        <td>Resources, facilities, quality, approval</td>
        <td>These sections complete the course specification package.</td>
    </tr>
</table>

<h2>4. Detailed Course-Level Validation</h2>

<table>
    <tr>
        <th>No.</th>
        <th>Source</th>
        <th>Level</th>
        <th>Course Code</th>
        <th>Course Title</th>
        <th>Credits</th>
        <th>Spec ID</th>
        <th>Files</th>
        <th>DOCX</th>
        <th>PDF</th>
        <th>CLOs</th>
        <th>Topics</th>
        <th>Topic Hours</th>
        <th>Assessments</th>
        <th>Assessment %</th>
        <th>Resources</th>
        <th>Facilities</th>
        <th>Quality</th>
        <th>Approval</th>
        <th>Readiness</th>
        <th>Status</th>
        <th>Issues / Warnings</th>
    </tr>

    <?php if (count($validationRows) > 0): ?>
        <?php $i = 1; ?>
        <?php foreach ($validationRows as $row): ?>
            <?php
            $statusClass = "fail";

            if ($row["status"] === "Pass") {
                $statusClass = "pass";
            } elseif ($row["status"] === "Pass with Warnings" || $row["status"] === "Needs Correction") {
                $statusClass = "warning";
            } elseif ($row["status"] === "Review") {
                $statusClass = "review";
            }

            $issuesText = issuesToText($row["issues"], $row["warnings"]);
            ?>
            <tr>
                <td class="center"><?php echo safeText($i); ?></td>
                <td><?php echo safeText($row["source"]); ?></td>
                <td><?php echo safeText($row["level_no"]); ?></td>
                <td><strong><?php echo safeText($row["course_code"]); ?></strong></td>
                <td><?php echo safeText($row["course_title"]); ?></td>
                <td class="center"><?php echo safeText($row["credit_hours"]); ?></td>
                <td class="center"><?php echo $row["course_specification_id"] > 0 ? safeText($row["course_specification_id"]) : "-"; ?></td>
                <td class="center"><?php echo safeText($row["file_total"]); ?></td>
                <td class="center"><?php echo safeText($row["docx_count"]); ?></td>
                <td class="center"><?php echo safeText($row["pdf_count"]); ?></td>
                <td class="center"><?php echo safeText($row["clo_count"]); ?></td>
                <td class="center"><?php echo safeText($row["topic_count"]); ?></td>
                <td class="center"><?php echo safeText($row["topic_hours"]); ?></td>
                <td class="center"><?php echo safeText($row["assessment_count"]); ?></td>
                <td class="center"><?php echo safeText($row["assessment_percent"]); ?>%</td>
                <td class="center"><?php echo safeText($row["resource_count"]); ?></td>
                <td class="center"><?php echo safeText($row["facility_count"]); ?></td>
                <td class="center"><?php echo safeText($row["quality_count"]); ?></td>
                <td class="center"><?php echo safeText($row["approval_count"]); ?></td>
                <td class="center">
                    <?php echo safeText($row["passed_checks"]); ?> /
                    <?php echo safeText($row["total_checks"]); ?><br>
                    <?php echo safeText($row["readiness_percent"]); ?>%
                </td>
                <td class="<?php echo safeText($statusClass); ?>"><?php echo safeText($row["status"]); ?></td>
                <td><?php echo wordText($issuesText); ?></td>
            </tr>
            <?php $i++; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="22" class="fail">No validation records found.</td>
        </tr>
    <?php endif; ?>
</table>

<h2>5. Recommended Actions</h2>

<table>
    <tr>
        <th>Area</th>
        <th>Recommendation</th>
    </tr>
    <tr>
        <td>Failed courses</td>
        <td>Upload the official DOCX course specification files and run the bulk DOCX import again.</td>
    </tr>
    <tr>
        <td>Needs correction</td>
        <td>Review missing sections, especially CLOs, topics, assessments, and assessment totals.</td>
    </tr>
    <tr>
        <td>Pass with warnings</td>
        <td>Review non-critical missing sections such as resources, facilities, quality assessment, and approval data.</td>
    </tr>
    <tr>
        <td>Final package</td>
        <td>After validation is ready, export the course specification ZIP package and create a final archive record.</td>
    </tr>
</table>

</body>
</html>