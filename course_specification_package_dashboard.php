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
    Program courses count.
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

/*
    Course specification records.
*/
$courseStmt = $conn->prepare("
    SELECT *
    FROM course_specifications
    WHERE program_specification_id = :program_specification_id
    ORDER BY course_code ASC, id ASC
");
$courseStmt->execute([":program_specification_id" => $program_specification_id]);
$courseSpecs = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCourseSpecifications = count($courseSpecs);

/*
    File counts.
*/
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

/*
    Course readiness.
*/
$courseRows = [];
$importedCoursesCount = 0;
$coreReadyCount = 0;
$readyForExportCount = 0;
$notReadyCount = 0;

$totalCLOs = 0;
$totalTopics = 0;
$totalAssessments = 0;
$totalResources = 0;
$totalFacilities = 0;
$totalQualityRows = 0;
$totalApprovalRows = 0;

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
    $topicHours = sumColumnForCourseSpec($conn, "course_specification_topics", "contact_hours", $courseSpecId);

    $fileCount = 0;
    $docxCount = 0;
    $pdfCount = 0;

    if (tableExists($conn, "course_specification_files")) {
        $fileStmt = $conn->prepare("
            SELECT file_type, COUNT(*) AS total
            FROM course_specification_files
            WHERE course_specification_id = :course_specification_id
            GROUP BY file_type
        ");
        $fileStmt->execute([":course_specification_id" => $courseSpecId]);
        $files = $fileStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($files as $file) {
            $type = strtolower(trim((string)($file["file_type"] ?? "")));
            $count = (int)($file["total"] ?? 0);

            $fileCount += $count;

            if ($type === "docx") {
                $docxCount += $count;
            } elseif ($type === "pdf") {
                $pdfCount += $count;
            }
        }
    }

    $coreImported = (
        $cloCount > 0 &&
        $topicCount > 0 &&
        $assessmentCount > 0
    );

    $coreReady = (
        $coreImported &&
        abs($assessmentPercent - 100) <= 0.01
    );

    $ready = (
        $coreReady &&
        $resourceCount > 0 &&
        $facilityCount > 0 &&
        $qualityCount > 0 &&
        $approvalCount > 0
    );

    $status = "Not Ready";

    if ($ready) {
        $status = "Ready";
        $readyForExportCount++;
    } elseif ($coreReady) {
        $status = "Core Ready";
        $coreReadyCount++;
    } elseif ($coreImported) {
        $status = "Imported - Check";
        $importedCoursesCount++;
    } else {
        $notReadyCount++;
    }

    if ($coreImported) {
        $importedCoursesCount++;
    }

    $issues = [];

    if ($fileCount === 0) {
        $issues[] = "No uploaded file";
    }

    if ($docxCount === 0) {
        $issues[] = "No DOCX file";
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

    if ($assessmentCount > 0 && abs($assessmentPercent - 100) > 0.01) {
        $issues[] = "Assessment total not 100%";
    }

    if ($resourceCount === 0) {
        $issues[] = "No resources";
    }

    if ($facilityCount === 0) {
        $issues[] = "No facilities";
    }

    if ($qualityCount === 0) {
        $issues[] = "No quality rows";
    }

    if ($approvalCount === 0) {
        $issues[] = "No approval data";
    }

    $totalCLOs += $cloCount;
    $totalTopics += $topicCount;
    $totalAssessments += $assessmentCount;
    $totalResources += $resourceCount;
    $totalFacilities += $facilityCount;
    $totalQualityRows += $qualityCount;
    $totalApprovalRows += $approvalCount;

    $courseRows[] = [
        "id" => $courseSpecId,
        "course_code" => $cs["course_code"] ?? "",
        "course_title" => $cs["course_title"] ?? "",
        "credit_hours" => $cs["credit_hours"] ?? "",
        "level_year" => $cs["course_level_year"] ?? "",
        "status" => $status,
        "file_count" => $fileCount,
        "docx_count" => $docxCount,
        "pdf_count" => $pdfCount,
        "clo_count" => $cloCount,
        "topic_count" => $topicCount,
        "topic_hours" => $topicHours,
        "assessment_count" => $assessmentCount,
        "assessment_percent" => $assessmentPercent,
        "resource_count" => $resourceCount,
        "facility_count" => $facilityCount,
        "quality_count" => $qualityCount,
        "approval_count" => $approvalCount,
        "issues" => $issues
    ];
}

$missingCourseSpecCount = max($totalProgramCourses - $totalCourseSpecifications, 0);
$fullReadyPercent = $totalProgramCourses > 0 ? round(($readyForExportCount / $totalProgramCourses) * 100, 1) : 0;
$coreReadyPercent = $totalProgramCourses > 0 ? round((($readyForExportCount + $coreReadyCount) / $totalProgramCourses) * 100, 1) : 0;
$uploadCoveragePercent = $totalProgramCourses > 0 ? round(($uploadedFilesCount / $totalProgramCourses) * 100, 1) : 0;

/*
    Archive summary.
*/
$archiveCount = 0;
$finalArchiveCount = 0;
$latestArchive = null;

if (tableExists($conn, "course_specification_archive")) {
    $archiveStmt = $conn->prepare("
        SELECT *
        FROM course_specification_archive
        WHERE program_specification_id = :program_specification_id
        ORDER BY archive_date DESC, id DESC
    ");
    $archiveStmt->execute([":program_specification_id" => $program_specification_id]);
    $archives = $archiveStmt->fetchAll(PDO::FETCH_ASSOC);

    $archiveCount = count($archives);
    $latestArchive = $archives[0] ?? null;

    foreach ($archives as $archive) {
        if (strtolower(trim((string)($archive["package_status"] ?? ""))) === "final") {
            $finalArchiveCount++;
        }
    }
}

/*
    Overall dashboard decision.
*/
$dashboardDecision = "Not Ready";
$dashboardDecisionClass = "badge-danger";

if ($readyForExportCount >= $totalProgramCourses && $totalProgramCourses > 0) {
    $dashboardDecision = "Ready for Final Package";
    $dashboardDecisionClass = "badge-ok";
} elseif (($readyForExportCount + $coreReadyCount) > 0) {
    $dashboardDecision = "In Progress";
    $dashboardDecisionClass = "badge-warning";
}

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Specification Package Dashboard</title>
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

        .badge-core {
            background: #cff4fc;
            color: #055160;
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
	<a href="bulk_export_course_specifications_zip.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
    Next Step: Export ZIP
</a>

<a href="course_specification_archive.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
    Archive Package
</a>

<a href="course_specification_workflow.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
    Back to Workflow
</a>
    <div class="page-header">
        <h1>Course Specification Package Dashboard</h1>
        <p>Executive dashboard for course specification upload, import, export, and archive readiness.</p>
    </div>

    <div class="actions">
        <a href="program_specification_dashboard.php?id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Program Spec Dashboard
        </a>

        <a href="course_specification_import_status.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Import Status
        </a>

        <a href="course_specification_export_center.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Export Center
        </a>

        <a href="course_specification_archive.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Archive
        </a>

        <a href="bulk_upload_course_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Bulk Upload
        </a>

        <a href="bulk_import_course_specifications_from_docx.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Bulk Import
        </a>

        <a href="bulk_export_course_specifications_zip.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Export ZIP
        </a>

        <a href="course_specifications.php" class="btn btn-secondary">
            Course Specifications
        </a>
	<a href="course_specification_validation.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
    Course Spec Validation
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
                <th>Package Decision</th>
                <td colspan="3">
                    <span class="<?php echo safeText($dashboardDecisionClass); ?>">
                        <?php echo safeText($dashboardDecision); ?>
                    </span>
                </td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Readiness Progress</h2>

        <p><strong>Full readiness:</strong> complete course specifications with CLOs, topics, assessments, resources, facilities, quality assessment, approval data, and assessment total = 100%.</p>

        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText($fullReadyPercent); ?>%;">
                <?php echo safeText($fullReadyPercent); ?>%
            </div>
        </div>

        <p><strong>Core readiness:</strong> full ready + core ready records with CLOs, topics, assessments, and assessment total = 100%.</p>

        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText($coreReadyPercent); ?>%;">
                <?php echo safeText($coreReadyPercent); ?>%
            </div>
        </div>

        <p><strong>Upload coverage:</strong> uploaded files compared with number of courses in the program study plan.</p>

        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText(min($uploadCoveragePercent, 100)); ?>%;">
                <?php echo safeText($uploadCoveragePercent); ?>%
            </div>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalProgramCourses); ?></div>
            <div class="summary-label">Program Courses</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalCourseSpecifications); ?></div>
            <div class="summary-label">Course Spec Records</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($missingCourseSpecCount); ?></div>
            <div class="summary-label">Missing Records</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($uploadedFilesCount); ?></div>
            <div class="summary-label">Uploaded Files</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($docxFilesCount); ?></div>
            <div class="summary-label">DOCX Files</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($pdfFilesCount); ?></div>
            <div class="summary-label">PDF Files</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($readyForExportCount); ?></div>
            <div class="summary-label">Ready for Export</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($coreReadyCount); ?></div>
            <div class="summary-label">Core Ready</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($notReadyCount); ?></div>
            <div class="summary-label">Not Ready</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($archiveCount); ?></div>
            <div class="summary-label">Archive Records</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($finalArchiveCount); ?></div>
            <div class="summary-label">Final Archives</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($fullReadyPercent); ?>%</div>
            <div class="summary-label">Full Readiness</div>
        </div>
    </div>

    <div class="panel">
        <h2>Extracted Data Totals</h2>

        <table>
            <tr>
                <th>Total CLOs</th>
                <th>Total Topics</th>
                <th>Total Assessments</th>
                <th>Total Resources</th>
                <th>Total Facilities</th>
                <th>Total Quality Rows</th>
                <th>Total Approval Rows</th>
            </tr>
            <tr>
                <td><?php echo safeText($totalCLOs); ?></td>
                <td><?php echo safeText($totalTopics); ?></td>
                <td><?php echo safeText($totalAssessments); ?></td>
                <td><?php echo safeText($totalResources); ?></td>
                <td><?php echo safeText($totalFacilities); ?></td>
                <td><?php echo safeText($totalQualityRows); ?></td>
                <td><?php echo safeText($totalApprovalRows); ?></td>
            </tr>
        </table>
    </div>

    <?php if ($latestArchive): ?>
        <div class="panel">
            <h2>Latest Archive Record</h2>

            <table>
                <tr>
                    <th>Archive Title</th>
                    <td><?php echo safeText($latestArchive["archive_title"] ?? ""); ?></td>
                    <th>Version</th>
                    <td><?php echo safeText($latestArchive["archive_version"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>Archive Date</th>
                    <td><?php echo safeText($latestArchive["archive_date"] ?? ""); ?></td>
                    <th>Package Status</th>
                    <td><?php echo safeText($latestArchive["package_status"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>Review Status</th>
                    <td><?php echo safeText($latestArchive["review_status"] ?? ""); ?></td>
                    <th>Approval Status</th>
                    <td><?php echo safeText($latestArchive["approval_status"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>ZIP Exported</th>
                    <td><?php echo (int)($latestArchive["bulk_zip_exported"] ?? 0) === 1 ? "Yes" : "No"; ?></td>
                    <th>ZIP Export Date</th>
                    <td><?php echo safeText($latestArchive["bulk_zip_export_date"] ?? ""); ?></td>
                </tr>
            </table>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Course-Level Package Status</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>ID</th>
                    <th>Course Code</th>
                    <th>Course Title</th>
                    <th>Credits</th>
                    <th>Level / Year</th>
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

                <?php if (count($courseRows) > 0): ?>
                    <?php foreach ($courseRows as $row): ?>
                        <?php
                        $badgeClass = "badge-danger";

                        if ($row["status"] === "Ready") {
                            $badgeClass = "badge-ok";
                        } elseif ($row["status"] === "Core Ready") {
                            $badgeClass = "badge-core";
                        } elseif ($row["status"] === "Imported - Check") {
                            $badgeClass = "badge-warning";
                        }

                        $assessmentOk = abs((float)$row["assessment_percent"] - 100) <= 0.01;
                        ?>
                        <tr>
                            <td><?php echo safeText($row["id"]); ?></td>
                            <td><strong><?php echo safeText($row["course_code"]); ?></strong></td>
                            <td><?php echo safeText($row["course_title"]); ?></td>
                            <td><?php echo safeText($row["credit_hours"]); ?></td>
                            <td><?php echo safeText($row["level_year"]); ?></td>
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
                                <?php else: ?>
                                    <span class="ok-text">OK</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="course_specification_profile.php?id=<?php echo safeText($row["id"]); ?>" class="btn">
                                    Profile
                                </a>

                                <a href="export_course_specification_word.php?id=<?php echo safeText($row["id"]); ?>" class="btn btn-purple">
                                    Export
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="20">No course specification records found.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>