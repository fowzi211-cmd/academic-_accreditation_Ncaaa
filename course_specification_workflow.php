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

function countTableRows($conn, $tableName, $whereSql = "", $params = []) {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    $sql = "SELECT COUNT(*) FROM `$tableName` " . $whereSql;
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
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
        FROM `$tableName`
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
        SELECT COALESCE(SUM(`$columnName`), 0)
        FROM `$tableName`
        WHERE course_specification_id = :id
    ");
    $stmt->execute([":id" => $courseSpecId]);

    return (float)$stmt->fetchColumn();
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
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
    Current counts.
*/
$totalProgramCourses = 0;

if (tableExists($conn, "program_specification_courses")) {
    $totalProgramCourses = countTableRows(
        $conn,
        "program_specification_courses",
        "WHERE program_specification_id = :program_specification_id",
        [":program_specification_id" => $program_specification_id]
    );
}

$totalCourseSpecs = 0;
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

    $totalCourseSpecs = count($courseSpecs);
}

$totalFiles = 0;
$totalDocxFiles = 0;
$totalPdfFiles = 0;

if (tableExists($conn, "course_specification_files")) {
    $stmt = $conn->prepare("
        SELECT file_type, COUNT(*) AS total
        FROM course_specification_files
        WHERE program_specification_id = :program_specification_id
        GROUP BY file_type
    ");
    $stmt->execute([":program_specification_id" => $program_specification_id]);
    $fileRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($fileRows as $row) {
        $type = strtolower(trim((string)($row["file_type"] ?? "")));
        $count = (int)($row["total"] ?? 0);

        $totalFiles += $count;

        if ($type === "docx") {
            $totalDocxFiles += $count;
        } elseif ($type === "pdf") {
            $totalPdfFiles += $count;
        }
    }
}

$coreImportedCount = 0;
$fullReadyCount = 0;
$needsCorrectionCount = 0;

foreach ($courseSpecs as $cs) {
    $courseSpecId = (int)$cs["id"];

    $cloCount = countRowsForCourseSpec($conn, "course_specification_clos", $courseSpecId);
    $topicCount = countRowsForCourseSpec($conn, "course_specification_topics", $courseSpecId);
    $assessmentCount = countRowsForCourseSpec($conn, "course_specification_assessments", $courseSpecId);
    $resourceCount = countRowsForCourseSpec($conn, "course_specification_resources", $courseSpecId);
    $facilityCount = countRowsForCourseSpec($conn, "course_specification_facilities", $courseSpecId);
    $qualityCount = countRowsForCourseSpec($conn, "course_specification_quality", $courseSpecId);
    $approvalCount = countRowsForCourseSpec($conn, "course_specification_approval", $courseSpecId);

    $assessmentTotal = sumColumnForCourseSpec($conn, "course_specification_assessments", "percentage", $courseSpecId);

    $coreImported = (
        $cloCount > 0 &&
        $topicCount > 0 &&
        $assessmentCount > 0
    );

    $fullReady = (
        $coreImported &&
        abs($assessmentTotal - 100) <= 0.01 &&
        $resourceCount > 0 &&
        $facilityCount > 0 &&
        $qualityCount > 0 &&
        $approvalCount > 0
    );

    if ($coreImported) {
        $coreImportedCount++;
    }

    if ($fullReady) {
        $fullReadyCount++;
    } else {
        $needsCorrectionCount++;
    }
}

$archiveCount = 0;
$finalArchiveCount = 0;

if (tableExists($conn, "course_specification_archive")) {
    $archiveCount = countTableRows(
        $conn,
        "course_specification_archive",
        "WHERE program_specification_id = :program_specification_id",
        [":program_specification_id" => $program_specification_id]
    );

    $finalArchiveCount = countTableRows(
        $conn,
        "course_specification_archive",
        "WHERE program_specification_id = :program_specification_id AND package_status = 'Final'",
        [":program_specification_id" => $program_specification_id]
    );
}

$uploadCoverage = $totalProgramCourses > 0 ? round(($totalFiles / $totalProgramCourses) * 100, 1) : 0;
$importCoverage = $totalProgramCourses > 0 ? round(($coreImportedCount / $totalProgramCourses) * 100, 1) : 0;
$readyCoverage = $totalProgramCourses > 0 ? round(($fullReadyCount / $totalProgramCourses) * 100, 1) : 0;

/*
    Step status.
*/
$step1Status = $totalFiles > 0 ? "Started" : "Not Started";
$step2Status = $coreImportedCount > 0 ? "Started" : "Not Started";
$step3Status = $coreImportedCount > 0 ? "Available" : "Waiting";
$step4Status = $fullReadyCount > 0 ? "Started" : "Waiting";
$step5Status = $totalCourseSpecs > 0 ? "Available" : "Waiting";
$step6Status = $archiveCount > 0 ? "Started" : "Waiting";

function badgeClass($status) {
    if ($status === "Complete" || $status === "Available" || $status === "Started") {
        return "badge-ok";
    }

    if ($status === "Waiting") {
        return "badge-warning";
    }

    return "badge-danger";
}

$recommendedNextStep = "Bulk Upload Course Specifications";
$recommendedNextUrl = "bulk_upload_course_specifications.php?program_specification_id=" . $program_specification_id;

if ($totalFiles > 0 && $coreImportedCount === 0) {
    $recommendedNextStep = "Bulk Import DOCX";
    $recommendedNextUrl = "bulk_import_course_specifications_from_docx.php?program_specification_id=" . $program_specification_id;
} elseif ($coreImportedCount > 0 && $needsCorrectionCount > 0) {
    $recommendedNextStep = "Review Import Status / Validation";
    $recommendedNextUrl = "course_specification_validation.php?program_specification_id=" . $program_specification_id;
} elseif ($fullReadyCount > 0 && $finalArchiveCount === 0) {
    $recommendedNextStep = "Open Package Dashboard / Export ZIP";
    $recommendedNextUrl = "course_specification_package_dashboard.php?program_specification_id=" . $program_specification_id;
} elseif ($finalArchiveCount > 0) {
    $recommendedNextStep = "Final Archive Exists";
    $recommendedNextUrl = "course_specification_archive.php?program_specification_id=" . $program_specification_id;
}

$steps = [
    [
        "no" => 1,
        "title" => "Bulk Upload Course Specifications",
        "purpose" => "Upload the official DOCX/PDF course specification files.",
        "status" => $step1Status,
        "url" => "bulk_upload_course_specifications.php?program_specification_id=" . $program_specification_id,
        "button" => "Go to Bulk Upload"
    ],
    [
        "no" => 2,
        "title" => "Bulk Import DOCX",
        "purpose" => "Extract CLOs, topics, assessments, resources, facilities, quality rows, and approval data from uploaded DOCX files.",
        "status" => $step2Status,
        "url" => "bulk_import_course_specifications_from_docx.php?program_specification_id=" . $program_specification_id,
        "button" => "Go to Bulk Import"
    ],
    [
        "no" => 3,
        "title" => "Check Import Status",
        "purpose" => "Review which courses were uploaded/imported and which sections are missing.",
        "status" => $step3Status,
        "url" => "course_specification_import_status.php?program_specification_id=" . $program_specification_id,
        "button" => "Open Import Status"
    ],
    [
        "no" => 4,
        "title" => "Validate Course Specifications",
        "purpose" => "Validate assessment totals, CLOs, topics, resources, facilities, quality rows, approval data, and study-plan matching.",
        "status" => $step4Status,
        "url" => "course_specification_validation.php?program_specification_id=" . $program_specification_id,
        "button" => "Open Validation"
    ],
    [
        "no" => 5,
        "title" => "Open Package Dashboard",
        "purpose" => "Review package readiness and course-level status before exporting the package.",
        "status" => $step5Status,
        "url" => "course_specification_package_dashboard.php?program_specification_id=" . $program_specification_id,
        "button" => "Open Dashboard"
    ],
    [
        "no" => 6,
        "title" => "Export ZIP and Archive",
        "purpose" => "Export ready course specifications as ZIP and create archive/version-control record.",
        "status" => $step6Status,
        "url" => "course_specification_archive.php?program_specification_id=" . $program_specification_id,
        "button" => "Open Archive"
    ]
];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Specification Workflow</title>
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

        .workflow-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(260px, 1fr));
            gap: 16px;
        }

        .step-card {
            background: #ffffff;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 16px;
            position: relative;
        }

        .step-number {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            background: #0d6efd;
            color: white;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            font-weight: bold;
            margin-right: 8px;
        }

        .step-title {
            font-size: 18px;
            font-weight: bold;
            color: #1f4e79;
            margin-bottom: 8px;
        }

        .step-purpose {
            color: #555;
            font-size: 14px;
            min-height: 52px;
            margin-bottom: 12px;
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

        @media (max-width: 1100px) {
            .summary-grid,
            .workflow-grid {
                grid-template-columns: repeat(2, minmax(130px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .summary-grid,
            .workflow-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Course Specification Workflow</h1>
        <p>Start here and move step by step from upload to import, validation, package dashboard, ZIP export, and archive.</p>
    </div>

    <div class="actions">
        <a href="program_specification_dashboard.php?id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Program Spec Dashboard
        </a>

        <a href="course_specification_package_dashboard.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Package Dashboard
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
        <h2>Recommended Next Step</h2>

        <p>
            <strong><?php echo safeText($recommendedNextStep); ?></strong>
        </p>

        <a href="<?php echo safeText($recommendedNextUrl); ?>" class="btn btn-purple">
            Continue Workflow
        </a>
    </div>

    <div class="panel">
        <h2>Workflow Progress</h2>

        <p><strong>Upload coverage</strong></p>
        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText(min($uploadCoverage, 100)); ?>%;">
                <?php echo safeText($uploadCoverage); ?>%
            </div>
        </div>

        <p><strong>Import coverage</strong></p>
        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText(min($importCoverage, 100)); ?>%;">
                <?php echo safeText($importCoverage); ?>%
            </div>
        </div>

        <p><strong>Full readiness</strong></p>
        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText(min($readyCoverage, 100)); ?>%;">
                <?php echo safeText($readyCoverage); ?>%
            </div>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalProgramCourses); ?></div>
            <div class="summary-label">Program Courses</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalCourseSpecs); ?></div>
            <div class="summary-label">Course Spec Records</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalFiles); ?></div>
            <div class="summary-label">Uploaded Files</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalDocxFiles); ?></div>
            <div class="summary-label">DOCX Files</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($coreImportedCount); ?></div>
            <div class="summary-label">Core Imported</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($fullReadyCount); ?></div>
            <div class="summary-label">Full Ready</div>
        </div>
    </div>

    <div class="panel">
        <h2>Workflow Steps</h2>

        <div class="workflow-grid">
            <?php foreach ($steps as $step): ?>
                <div class="step-card">
                    <div class="step-title">
                        <span class="step-number"><?php echo safeText($step["no"]); ?></span>
                        <?php echo safeText($step["title"]); ?>
                    </div>

                    <div class="step-purpose">
                        <?php echo safeText($step["purpose"]); ?>
                    </div>

                    <p>
                        <span class="<?php echo safeText(badgeClass($step["status"])); ?>">
                            <?php echo safeText($step["status"]); ?>
                        </span>
                    </p>

                    <a href="<?php echo safeText($step["url"]); ?>" class="btn btn-purple">
                        <?php echo safeText($step["button"]); ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="panel">
        <h2>End-of-Workflow Actions</h2>

        <div class="actions">
            <a href="bulk_export_course_specifications_zip.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
                Export Ready Courses ZIP
            </a>

            <a href="course_specification_archive.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
                Create / View Archive
            </a>

            <a href="export_course_specification_archive_word.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
                Export Archive Report
            </a>

            <a href="link_course_reports_to_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
                Link Course Reports
            </a>
	<a href="bulk_create_course_reports_from_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
    Bulk Create Course Reports
</a>
        </div>
    </div>

</div>

</body>
</html>