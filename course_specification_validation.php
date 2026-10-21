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
    Load program course master list.
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
    Validate against the master study-plan course list.
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

    if ($courseSpecId <= 0) {
        $issues[] = "No course specification record linked to this study-plan course.";
    } else {
        $passedChecks++;
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

    $fileCounts = $courseSpecId > 0 ? getFileCounts($conn, $courseSpecId) : ["total" => 0, "docx" => 0, "pdf" => 0, "doc" => 0];

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
    Add course specification records not found in the study-plan list.
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
$overallClass = "badge-danger";

if ($totalRows > 0 && $failCount === 0 && $needsCorrectionCount === 0 && $reviewCount === 0) {
    $overallDecision = "Ready";
    $overallClass = "badge-ok";
} elseif (($passCount + $warningCount) > 0) {
    $overallDecision = "Partially Ready";
    $overallClass = "badge-warning";
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Specification Validation</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
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

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .wide-table {
            min-width: 1750px;
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

        .badge-review {
            background: #e2e3e5;
            color: #41464b;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }

        .issues {
            font-size: 12px;
            color: #842029;
            line-height: 1.4;
        }

        .warnings {
            font-size: 12px;
            color: #664d03;
            line-height: 1.4;
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
        <h1>Course Specification Validation</h1>
        <p>Validates uploaded and imported course specifications against the program study plan and required NCAAA-style sections.</p>
    </div>

    <div class="actions">
	<a href="course_specification_package_dashboard.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
    Next Step: Package Dashboard
</a>

<a href="course_specification_workflow.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
    Back to Workflow
</a>
        <a href="program_specification_dashboard.php?id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Program Spec Dashboard
        </a>

        <a href="course_specification_package_dashboard.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Package Dashboard
        </a>

        <a href="course_specification_import_status.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Import Status
        </a>

        <a href="course_specification_export_center.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Export Center
        </a>

        <a href="bulk_import_course_specifications_from_docx.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Bulk Import
        </a>

        <a href="course_specifications.php" class="btn btn-secondary">
            Course Specifications
        </a>
		<a href="export_course_specification_validation_word.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
    Export Validation Report
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
            <tr>
                <th>Overall Validation Decision</th>
                <td colspan="3">
                    <span class="<?php echo safeText($overallClass); ?>">
                        <?php echo safeText($overallDecision); ?>
                    </span>
                </td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Validation Pass Rate</h2>

        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText($passPercent); ?>%;">
                <?php echo safeText($passPercent); ?>%
            </div>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalRows); ?></div>
            <div class="summary-label">Validated Courses</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($passCount); ?></div>
            <div class="summary-label">Pass</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($warningCount); ?></div>
            <div class="summary-label">Pass with Warnings</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($needsCorrectionCount); ?></div>
            <div class="summary-label">Needs Correction</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($failCount); ?></div>
            <div class="summary-label">Fail</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($reviewCount); ?></div>
            <div class="summary-label">Review</div>
        </div>
    </div>

    <div class="panel">
        <h2>Validation Rules</h2>

        <table>
            <tr>
                <th>Rule</th>
                <th>Meaning</th>
            </tr>
            <tr>
                <td>Course record exists</td>
                <td>Every study-plan course should have a corresponding course specification record.</td>
            </tr>
            <tr>
                <td>Uploaded file exists</td>
                <td>Each course specification should have at least one attached official Word/PDF file.</td>
            </tr>
            <tr>
                <td>DOCX exists</td>
                <td>DOCX is required for structured import. PDF can be stored as evidence, but is not reliable for extraction.</td>
            </tr>
            <tr>
                <td>CLOs, topics, assessments</td>
                <td>Core course specification sections must be imported.</td>
            </tr>
            <tr>
                <td>Assessment total = 100%</td>
                <td>Assessment activities should total exactly 100%.</td>
            </tr>
            <tr>
                <td>Resources, facilities, quality, approval</td>
                <td>These complete the course specification package for archive/export readiness.</td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Course Specification Validation Details</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Source</th>
                    <th>Level</th>
                    <th>Course Code</th>
                    <th>Course Title</th>
                    <th>Credits</th>
                    <th>Course Spec ID</th>
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
                    <th>Actions</th>
                </tr>

                <?php if (count($validationRows) > 0): ?>
                    <?php foreach ($validationRows as $row): ?>
                        <?php
                        $badgeClass = "badge-danger";

                        if ($row["status"] === "Pass") {
                            $badgeClass = "badge-ok";
                        } elseif ($row["status"] === "Pass with Warnings" || $row["status"] === "Needs Correction") {
                            $badgeClass = "badge-warning";
                        } elseif ($row["status"] === "Review") {
                            $badgeClass = "badge-review";
                        }

                        $assessmentOk = abs((float)$row["assessment_percent"] - 100) <= 0.01;
                        ?>
                        <tr>
                            <td><?php echo safeText($row["source"]); ?></td>
                            <td><?php echo safeText($row["level_no"]); ?></td>
                            <td><strong><?php echo safeText($row["course_code"]); ?></strong></td>
                            <td><?php echo safeText($row["course_title"]); ?></td>
                            <td><?php echo safeText($row["credit_hours"]); ?></td>
                            <td><?php echo $row["course_specification_id"] > 0 ? safeText($row["course_specification_id"]) : "-"; ?></td>
                            <td><?php echo safeText($row["file_total"]); ?></td>
                            <td><?php echo safeText($row["docx_count"]); ?></td>
                            <td><?php echo safeText($row["pdf_count"]); ?></td>
                            <td><?php echo safeText($row["clo_count"]); ?></td>
                            <td><?php echo safeText($row["topic_count"]); ?></td>
                            <td><?php echo safeText($row["topic_hours"]); ?></td>
                            <td><?php echo safeText($row["assessment_count"]); ?></td>
                            <td>
                                <span class="<?php echo $assessmentOk ? 'ok-text' : 'bad-text'; ?>">
                                    <?php echo safeText($row["assessment_percent"]); ?>%
                                </span>
                            </td>
                            <td><?php echo safeText($row["resource_count"]); ?></td>
                            <td><?php echo safeText($row["facility_count"]); ?></td>
                            <td><?php echo safeText($row["quality_count"]); ?></td>
                            <td><?php echo safeText($row["approval_count"]); ?></td>
                            <td>
                                <?php echo safeText($row["passed_checks"]); ?> /
                                <?php echo safeText($row["total_checks"]); ?>
                                <br>
                                <strong><?php echo safeText($row["readiness_percent"]); ?>%</strong>
                            </td>
                            <td>
                                <span class="<?php echo safeText($badgeClass); ?>">
                                    <?php echo safeText($row["status"]); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (count($row["issues"]) > 0): ?>
                                    <div class="issues">
                                        <?php foreach ($row["issues"] as $issue): ?>
                                            • <?php echo safeText($issue); ?><br>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (count($row["warnings"]) > 0): ?>
                                    <div class="warnings">
                                        <?php foreach ($row["warnings"] as $warning): ?>
                                            • <?php echo safeText($warning); ?><br>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (count($row["issues"]) === 0 && count($row["warnings"]) === 0): ?>
                                    <span class="ok-text">OK</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row["course_specification_id"] > 0): ?>
                                    <a href="course_specification_profile.php?id=<?php echo safeText($row["course_specification_id"]); ?>" class="btn">
                                        Profile
                                    </a>

                                    <a href="export_course_specification_word.php?id=<?php echo safeText($row["course_specification_id"]); ?>" class="btn btn-purple">
                                        Export
                                    </a>
                                <?php endif; ?>

                                <a href="bulk_import_course_specifications_from_docx.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
                                    Import
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="22">No validation records found.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>