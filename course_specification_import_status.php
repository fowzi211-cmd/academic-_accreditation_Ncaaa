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
    Load program courses as the master list.
*/
$programCourses = [];

if (tableExists($conn, "program_specification_courses")) {
    $programCourseStmt = $conn->prepare("
        SELECT *
        FROM program_specification_courses
        WHERE program_specification_id = :program_specification_id
        ORDER BY level_no ASC, course_code ASC, id ASC
    ");
    $programCourseStmt->execute([":program_specification_id" => $program_specification_id]);
    $programCourses = $programCourseStmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
    Load course specifications.
*/
$courseSpecStmt = $conn->prepare("
    SELECT *
    FROM course_specifications
    WHERE program_specification_id = :program_specification_id
    ORDER BY course_code ASC, id ASC
");
$courseSpecStmt->execute([":program_specification_id" => $program_specification_id]);
$courseSpecs = $courseSpecStmt->fetchAll(PDO::FETCH_ASSOC);

$courseSpecsByCode = [];
foreach ($courseSpecs as $cs) {
    $code = strtoupper(trim((string)($cs["course_code"] ?? "")));
    if ($code !== "") {
        $courseSpecsByCode[$code] = $cs;
    }
}

/*
    Load uploaded files.
*/
$filesByCode = [];
$filesByCourseSpecId = [];

if (tableExists($conn, "course_specification_files")) {
    $filesStmt = $conn->prepare("
        SELECT *
        FROM course_specification_files
        WHERE program_specification_id = :program_specification_id
        ORDER BY upload_date DESC, id DESC
    ");
    $filesStmt->execute([":program_specification_id" => $program_specification_id]);
    $allFiles = $filesStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allFiles as $file) {
        $code = strtoupper(trim((string)($file["course_code"] ?? "")));
        $csid = (int)($file["course_specification_id"] ?? 0);

        if ($code !== "") {
            if (!isset($filesByCode[$code])) {
                $filesByCode[$code] = [];
            }
            $filesByCode[$code][] = $file;
        }

        if ($csid > 0) {
            if (!isset($filesByCourseSpecId[$csid])) {
                $filesByCourseSpecId[$csid] = [];
            }
            $filesByCourseSpecId[$csid][] = $file;
        }
    }
}

$rows = [];

foreach ($programCourses as $course) {
    $code = strtoupper(trim((string)($course["course_code"] ?? "")));
    $courseSpec = $courseSpecsByCode[$code] ?? null;

    $courseSpecId = $courseSpec ? (int)$courseSpec["id"] : 0;

    $files = [];

    if ($courseSpecId > 0 && isset($filesByCourseSpecId[$courseSpecId])) {
        $files = $filesByCourseSpecId[$courseSpecId];
    } elseif ($code !== "" && isset($filesByCode[$code])) {
        $files = $filesByCode[$code];
    }

    $fileCount = count($files);
    $docxCount = 0;
    $pdfCount = 0;

    foreach ($files as $file) {
        $fileType = strtolower(trim((string)($file["file_type"] ?? "")));
        if ($fileType === "docx") {
            $docxCount++;
        } elseif ($fileType === "pdf") {
            $pdfCount++;
        }
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

    $hasMain = $courseSpecId > 0;
    $hasFile = $fileCount > 0;
    $hasDocx = $docxCount > 0;
    $hasCoreImport = ($cloCount > 0 && $topicCount > 0 && $assessmentCount > 0);
    $hasFullImport = (
        $hasCoreImport &&
        $resourceCount > 0 &&
        $facilityCount > 0 &&
        $qualityCount > 0 &&
        $approvalCount > 0
    );

    $issues = [];

    if (!$hasMain) {
        $issues[] = "No course specification record";
    }

    if (!$hasFile) {
        $issues[] = "No uploaded file";
    }

    if ($hasFile && !$hasDocx) {
        $issues[] = "No DOCX file for structured import";
    }

    if ($hasMain && $cloCount === 0) {
        $issues[] = "No CLOs";
    }

    if ($hasMain && $topicCount === 0) {
        $issues[] = "No topics";
    }

    if ($hasMain && $assessmentCount === 0) {
        $issues[] = "No assessments";
    }

    if ($hasMain && $topicHours <= 0) {
        $issues[] = "Topic hours missing";
    }

    if ($hasMain && abs($assessmentPercent - 100) > 0.01) {
        $issues[] = "Assessment total not 100%";
    }

    $status = "Missing";
    if ($hasFullImport) {
        $status = "Complete";
    } elseif ($hasCoreImport) {
        $status = "Core Imported";
    } elseif ($hasFile) {
        $status = "Uploaded Only";
    } elseif ($hasMain) {
        $status = "Record Only";
    }

    $rows[] = [
        "program_course_id" => $course["id"] ?? "",
        "level_no" => $course["level_no"] ?? "",
        "course_code" => $course["course_code"] ?? "",
        "course_title" => $course["course_title"] ?? "",
        "credit_hours" => $course["credit_hours"] ?? "",
        "course_specification_id" => $courseSpecId,
        "course_spec_title" => $courseSpec["course_title"] ?? "",
        "file_count" => $fileCount,
        "docx_count" => $docxCount,
        "pdf_count" => $pdfCount,
        "clo_count" => $cloCount,
        "topic_count" => $topicCount,
        "assessment_count" => $assessmentCount,
        "resource_count" => $resourceCount,
        "facility_count" => $facilityCount,
        "quality_count" => $qualityCount,
        "approval_count" => $approvalCount,
        "topic_hours" => $topicHours,
        "assessment_percent" => $assessmentPercent,
        "status" => $status,
        "issues" => $issues
    ];
}

/*
    Include course specifications that are not in program course list.
*/
foreach ($courseSpecs as $cs) {
    $code = strtoupper(trim((string)($cs["course_code"] ?? "")));

    $foundInMaster = false;
    foreach ($programCourses as $pc) {
        if (strtoupper(trim((string)($pc["course_code"] ?? ""))) === $code) {
            $foundInMaster = true;
            break;
        }
    }

    if ($foundInMaster) {
        continue;
    }

    $courseSpecId = (int)$cs["id"];

    $fileCount = isset($filesByCourseSpecId[$courseSpecId]) ? count($filesByCourseSpecId[$courseSpecId]) : 0;
    $docxCount = 0;
    $pdfCount = 0;

    if (isset($filesByCourseSpecId[$courseSpecId])) {
        foreach ($filesByCourseSpecId[$courseSpecId] as $file) {
            $type = strtolower(trim((string)($file["file_type"] ?? "")));
            if ($type === "docx") {
                $docxCount++;
            } elseif ($type === "pdf") {
                $pdfCount++;
            }
        }
    }

    $cloCount = countRowsForCourseSpec($conn, "course_specification_clos", $courseSpecId);
    $topicCount = countRowsForCourseSpec($conn, "course_specification_topics", $courseSpecId);
    $assessmentCount = countRowsForCourseSpec($conn, "course_specification_assessments", $courseSpecId);
    $resourceCount = countRowsForCourseSpec($conn, "course_specification_resources", $courseSpecId);
    $facilityCount = countRowsForCourseSpec($conn, "course_specification_facilities", $courseSpecId);
    $qualityCount = countRowsForCourseSpec($conn, "course_specification_quality", $courseSpecId);
    $approvalCount = countRowsForCourseSpec($conn, "course_specification_approval", $courseSpecId);

    $topicHours = sumColumnForCourseSpec($conn, "course_specification_topics", "contact_hours", $courseSpecId);
    $assessmentPercent = sumColumnForCourseSpec($conn, "course_specification_assessments", "percentage", $courseSpecId);

    $issues = ["Not found in program course list"];

    if ($fileCount === 0) {
        $issues[] = "No uploaded file";
    }

    if ($cloCount === 0) {
        $issues[] = "No CLOs";
    }

    if ($topicCount === 0) {
        $issues[] = "No topics";
    }

    if ($assessmentCount === 0) {
        $issues[] = "No assessments";
    }

    $hasFullImport = (
        $cloCount > 0 &&
        $topicCount > 0 &&
        $assessmentCount > 0 &&
        $resourceCount > 0 &&
        $facilityCount > 0 &&
        $qualityCount > 0 &&
        $approvalCount > 0
    );

    $status = $hasFullImport ? "Complete" : "Review";

    $rows[] = [
        "program_course_id" => "",
        "level_no" => "",
        "course_code" => $cs["course_code"] ?? "",
        "course_title" => $cs["course_title"] ?? "",
        "credit_hours" => $cs["credit_hours"] ?? "",
        "course_specification_id" => $courseSpecId,
        "course_spec_title" => $cs["course_title"] ?? "",
        "file_count" => $fileCount,
        "docx_count" => $docxCount,
        "pdf_count" => $pdfCount,
        "clo_count" => $cloCount,
        "topic_count" => $topicCount,
        "assessment_count" => $assessmentCount,
        "resource_count" => $resourceCount,
        "facility_count" => $facilityCount,
        "quality_count" => $qualityCount,
        "approval_count" => $approvalCount,
        "topic_hours" => $topicHours,
        "assessment_percent" => $assessmentPercent,
        "status" => $status,
        "issues" => $issues
    ];
}

/*
    Summary.
*/
$totalCourses = count($rows);
$completeCount = 0;
$coreImportedCount = 0;
$uploadedOnlyCount = 0;
$missingCount = 0;
$recordOnlyCount = 0;
$reviewCount = 0;

foreach ($rows as $row) {
    if ($row["status"] === "Complete") {
        $completeCount++;
    } elseif ($row["status"] === "Core Imported") {
        $coreImportedCount++;
    } elseif ($row["status"] === "Uploaded Only") {
        $uploadedOnlyCount++;
    } elseif ($row["status"] === "Record Only") {
        $recordOnlyCount++;
    } elseif ($row["status"] === "Missing") {
        $missingCount++;
    } else {
        $reviewCount++;
    }
}

$completionPercent = $totalCourses > 0 ? round(($completeCount / $totalCourses) * 100, 1) : 0;
$corePercent = $totalCourses > 0 ? round((($completeCount + $coreImportedCount) / $totalCourses) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Specification Import Status</title>
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

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .wide-table {
            min-width: 1550px;
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

        .badge-core {
            background: #cff4fc;
            color: #055160;
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

        .badge-neutral {
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

        .ok-text {
            color: #0f5132;
            font-weight: bold;
        }

        .bad-text {
            color: #842029;
            font-weight: bold;
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

        @media (max-width: 1200px) {
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
        <h1>Course Specification Import Status</h1>
        <p>Tracks upload and structured import coverage for all course specifications linked to Program Specification ID <?php echo safeText($program_specification_id); ?>.</p>
    </div>

    <div class="actions">
        <a href="program_specification_dashboard.php?id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Program Spec Dashboard
        </a>

        <a href="bulk_upload_course_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Bulk Upload
        </a>

        <a href="bulk_import_course_specifications_from_docx.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Bulk DOCX Import
        </a>

        <a href="upload_course_specification_file.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Single Upload
        </a>

        <a href="import_course_specification_from_docx.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Single DOCX Import
        </a>

        <a href="course_specifications.php" class="btn btn-secondary">
            Course Specifications
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
        <h2>Completion</h2>

        <p><strong>Full completion:</strong> complete imported records with CLOs, topics, assessments, resources, facilities, quality, and approval.</p>

        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText($completionPercent); ?>%;">
                <?php echo safeText($completionPercent); ?>%
            </div>
        </div>

        <p><strong>Core import:</strong> complete + core imported records with at least CLOs, topics, and assessments.</p>

        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText($corePercent); ?>%;">
                <?php echo safeText($corePercent); ?>%
            </div>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalCourses); ?></div>
            <div class="summary-label">Total Courses</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($completeCount); ?></div>
            <div class="summary-label">Complete</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($coreImportedCount); ?></div>
            <div class="summary-label">Core Imported</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($uploadedOnlyCount); ?></div>
            <div class="summary-label">Uploaded Only</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($recordOnlyCount); ?></div>
            <div class="summary-label">Record Only</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($missingCount); ?></div>
            <div class="summary-label">Missing</div>
        </div>
    </div>

    <div class="panel">
        <h2>Detailed Import Status</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
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
                    <th>Status</th>
                    <th>Issues</th>
                    <th>Actions</th>
                </tr>

                <?php if (count($rows) > 0): ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $status = $row["status"];
                        $badgeClass = "badge-neutral";

                        if ($status === "Complete") {
                            $badgeClass = "badge-ok";
                        } elseif ($status === "Core Imported") {
                            $badgeClass = "badge-core";
                        } elseif ($status === "Uploaded Only" || $status === "Record Only" || $status === "Review") {
                            $badgeClass = "badge-warning";
                        } elseif ($status === "Missing") {
                            $badgeClass = "badge-danger";
                        }

                        $assessmentOk = abs((float)$row["assessment_percent"] - 100) <= 0.01;
                        ?>
                        <tr>
                            <td><?php echo safeText($row["level_no"]); ?></td>
                            <td><strong><?php echo safeText($row["course_code"]); ?></strong></td>
                            <td><?php echo safeText($row["course_title"]); ?></td>
                            <td><?php echo safeText($row["credit_hours"]); ?></td>
                            <td><?php echo $row["course_specification_id"] > 0 ? safeText($row["course_specification_id"]) : "-"; ?></td>
                            <td><?php echo safeText($row["file_count"]); ?></td>
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
                                <span class="<?php echo safeText($badgeClass); ?>">
                                    <?php echo safeText($status); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (count($row["issues"]) > 0): ?>
                                    <div class="issues">
                                        <?php foreach ($row["issues"] as $issue): ?>
                                            • <?php echo safeText($issue); ?><br>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
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

                                <?php if ($row["docx_count"] > 0): ?>
                                    <a href="bulk_import_course_specifications_from_docx.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
                                        Import
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="20">No course records found.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>