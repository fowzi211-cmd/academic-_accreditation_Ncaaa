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

$error = "";
$success = "";

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

function addColumnIfMissing($conn, $tableName, $columnName, $definition) {
    if (!columnExists($conn, $tableName, $columnName)) {
        requireSchemaColumn($conn, $tableName, $columnName);
    }
}

function nullIfEmpty($value) {
    $value = trim((string)$value);
    return $value === "" ? null : $value;
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

function countRowsForCourseSpec($conn, $tableName, $courseSpecId) {
    if (!tableExists($conn, $tableName)) return 0;
    if (!columnExists($conn, $tableName, "course_specification_id")) return 0;

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM $tableName
        WHERE course_specification_id = :id
    ");
    $stmt->execute([":id" => $courseSpecId]);
    return (int)$stmt->fetchColumn();
}

function sumColumnForCourseSpec($conn, $tableName, $columnName, $courseSpecId) {
    if (!tableExists($conn, $tableName)) return 0;
    if (!columnExists($conn, $tableName, "course_specification_id")) return 0;
    if (!columnExists($conn, $tableName, $columnName)) return 0;

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

/*
    Create archive table.
*/
if (!tableExists($conn, "course_specification_archive")) {
    requireSchemaTable($conn, "course_specification_archive");
}

addColumnIfMissing($conn, "course_specification_archive", "program_specification_id", "INT NOT NULL");
addColumnIfMissing($conn, "course_specification_archive", "archive_title", "VARCHAR(255) NOT NULL");
addColumnIfMissing($conn, "course_specification_archive", "archive_version", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "course_specification_archive", "archive_date", "DATE NULL");
addColumnIfMissing($conn, "course_specification_archive", "package_status", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "course_specification_archive", "total_program_courses", "INT DEFAULT 0");
addColumnIfMissing($conn, "course_specification_archive", "total_course_specifications", "INT DEFAULT 0");
addColumnIfMissing($conn, "course_specification_archive", "uploaded_files_count", "INT DEFAULT 0");
addColumnIfMissing($conn, "course_specification_archive", "docx_files_count", "INT DEFAULT 0");
addColumnIfMissing($conn, "course_specification_archive", "pdf_files_count", "INT DEFAULT 0");
addColumnIfMissing($conn, "course_specification_archive", "imported_courses_count", "INT DEFAULT 0");
addColumnIfMissing($conn, "course_specification_archive", "ready_for_export_count", "INT DEFAULT 0");
addColumnIfMissing($conn, "course_specification_archive", "incomplete_courses_count", "INT DEFAULT 0");
addColumnIfMissing($conn, "course_specification_archive", "bulk_zip_exported", "TINYINT(1) DEFAULT 0");
addColumnIfMissing($conn, "course_specification_archive", "bulk_zip_export_date", "DATE NULL");
addColumnIfMissing($conn, "course_specification_archive", "review_status", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "course_specification_archive", "approval_status", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "course_specification_archive", "archive_notes", "TEXT NULL");
addColumnIfMissing($conn, "course_specification_archive", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

/*
    Load program specification.
*/
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
    Calculate current package counts.
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

$stmt = $conn->prepare("
    SELECT *
    FROM course_specifications
    WHERE program_specification_id = :program_specification_id
    ORDER BY course_code ASC, id ASC
");
$stmt->execute([":program_specification_id" => $program_specification_id]);
$courseSpecs = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
}

$incompleteCoursesCount = max($totalProgramCourses - $readyForExportCount, 0);

$form = [
    "archive_id" => "",
    "archive_title" => "Course Specification Package Archive",
    "archive_version" => "",
    "archive_date" => date("Y-m-d"),
    "package_status" => "Draft",
    "total_program_courses" => $totalProgramCourses,
    "total_course_specifications" => $totalCourseSpecifications,
    "uploaded_files_count" => $uploadedFilesCount,
    "docx_files_count" => $docxFilesCount,
    "pdf_files_count" => $pdfFilesCount,
    "imported_courses_count" => $importedCoursesCount,
    "ready_for_export_count" => $readyForExportCount,
    "incomplete_courses_count" => $incompleteCoursesCount,
    "bulk_zip_exported" => "0",
    "bulk_zip_export_date" => "",
    "review_status" => "Pending Review",
    "approval_status" => "Draft",
    "archive_notes" => ""
];

$programCode = trim((string)($programSpec["program_code"] ?? ""));
$programName = trim((string)($programSpec["program_name"] ?? ""));

if ($programCode !== "" || $programName !== "") {
    $form["archive_title"] = trim($programCode . " " . $programName . " Course Specification Package");
}

$canEdit = hasRole(["Admin", "Quality Coordinator", "Program Leader"]);

/*
    Delete.
*/
if ($canEdit && isset($_GET["delete"]) && is_numeric($_GET["delete"])) {
    $deleteId = (int)$_GET["delete"];

    $stmt = $conn->prepare("
        SELECT *
        FROM course_specification_archive
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $stmt->execute([
        ":id" => $deleteId,
        ":program_specification_id" => $program_specification_id
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $deleteStmt = $conn->prepare("
            DELETE FROM course_specification_archive
            WHERE id = :id
              AND program_specification_id = :program_specification_id
        ");
        $deleteStmt->execute([
            ":id" => $deleteId,
            ":program_specification_id" => $program_specification_id
        ]);

        logActivitySafe(
            $conn,
            "Delete",
            "Course Specification Archive",
            $deleteId,
            "Deleted course specification archive record: " . ($row["archive_title"] ?? "")
        );

        header("Location: course_specification_archive.php?program_specification_id=" . $program_specification_id);
        exit;
    }
}

/*
    Edit load.
*/
if (isset($_GET["edit"]) && is_numeric($_GET["edit"])) {
    $editId = (int)$_GET["edit"];

    $stmt = $conn->prepare("
        SELECT *
        FROM course_specification_archive
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $stmt->execute([
        ":id" => $editId,
        ":program_specification_id" => $program_specification_id
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        foreach ($form as $key => $value) {
            if (array_key_exists($key, $row)) {
                $form[$key] = $row[$key];
            }
        }
        $form["archive_id"] = $row["id"];
    }
}

/*
    Save.
*/
if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $key => $value) {
        if (isset($_POST[$key])) {
            $form[$key] = trim((string)$_POST[$key]);
        }
    }

    $form["bulk_zip_exported"] = isset($_POST["bulk_zip_exported"]) ? "1" : "0";

    /*
        Always refresh numeric counts from current system state.
    */
    $form["total_program_courses"] = $totalProgramCourses;
    $form["total_course_specifications"] = $totalCourseSpecifications;
    $form["uploaded_files_count"] = $uploadedFilesCount;
    $form["docx_files_count"] = $docxFilesCount;
    $form["pdf_files_count"] = $pdfFilesCount;
    $form["imported_courses_count"] = $importedCoursesCount;
    $form["ready_for_export_count"] = $readyForExportCount;
    $form["incomplete_courses_count"] = $incompleteCoursesCount;

    if ($form["archive_title"] === "") {
        $error = "Archive title is required.";
    } else {
        if ($form["archive_id"] !== "" && is_numeric($form["archive_id"])) {
            $stmt = $conn->prepare("
                UPDATE course_specification_archive
                SET
                    archive_title = :archive_title,
                    archive_version = :archive_version,
                    archive_date = :archive_date,
                    package_status = :package_status,
                    total_program_courses = :total_program_courses,
                    total_course_specifications = :total_course_specifications,
                    uploaded_files_count = :uploaded_files_count,
                    docx_files_count = :docx_files_count,
                    pdf_files_count = :pdf_files_count,
                    imported_courses_count = :imported_courses_count,
                    ready_for_export_count = :ready_for_export_count,
                    incomplete_courses_count = :incomplete_courses_count,
                    bulk_zip_exported = :bulk_zip_exported,
                    bulk_zip_export_date = :bulk_zip_export_date,
                    review_status = :review_status,
                    approval_status = :approval_status,
                    archive_notes = :archive_notes
                WHERE id = :id
                  AND program_specification_id = :program_specification_id
            ");

            $stmt->execute([
                ":archive_title" => $form["archive_title"],
                ":archive_version" => nullIfEmpty($form["archive_version"]),
                ":archive_date" => nullIfEmpty($form["archive_date"]),
                ":package_status" => nullIfEmpty($form["package_status"]),
                ":total_program_courses" => (int)$form["total_program_courses"],
                ":total_course_specifications" => (int)$form["total_course_specifications"],
                ":uploaded_files_count" => (int)$form["uploaded_files_count"],
                ":docx_files_count" => (int)$form["docx_files_count"],
                ":pdf_files_count" => (int)$form["pdf_files_count"],
                ":imported_courses_count" => (int)$form["imported_courses_count"],
                ":ready_for_export_count" => (int)$form["ready_for_export_count"],
                ":incomplete_courses_count" => (int)$form["incomplete_courses_count"],
                ":bulk_zip_exported" => (int)$form["bulk_zip_exported"],
                ":bulk_zip_export_date" => nullIfEmpty($form["bulk_zip_export_date"]),
                ":review_status" => nullIfEmpty($form["review_status"]),
                ":approval_status" => nullIfEmpty($form["approval_status"]),
                ":archive_notes" => nullIfEmpty($form["archive_notes"]),
                ":id" => (int)$form["archive_id"],
                ":program_specification_id" => $program_specification_id
            ]);

            logActivitySafe(
                $conn,
                "Update",
                "Course Specification Archive",
                (int)$form["archive_id"],
                "Updated course specification archive record: " . $form["archive_title"]
            );

            header("Location: course_specification_archive.php?program_specification_id=" . $program_specification_id . "&saved=1");
            exit;
        } else {
            $stmt = $conn->prepare("
                INSERT INTO course_specification_archive
                (
                    program_specification_id,
                    archive_title,
                    archive_version,
                    archive_date,
                    package_status,
                    total_program_courses,
                    total_course_specifications,
                    uploaded_files_count,
                    docx_files_count,
                    pdf_files_count,
                    imported_courses_count,
                    ready_for_export_count,
                    incomplete_courses_count,
                    bulk_zip_exported,
                    bulk_zip_export_date,
                    review_status,
                    approval_status,
                    archive_notes
                )
                VALUES
                (
                    :program_specification_id,
                    :archive_title,
                    :archive_version,
                    :archive_date,
                    :package_status,
                    :total_program_courses,
                    :total_course_specifications,
                    :uploaded_files_count,
                    :docx_files_count,
                    :pdf_files_count,
                    :imported_courses_count,
                    :ready_for_export_count,
                    :incomplete_courses_count,
                    :bulk_zip_exported,
                    :bulk_zip_export_date,
                    :review_status,
                    :approval_status,
                    :archive_notes
                )
            ");

            $stmt->execute([
                ":program_specification_id" => $program_specification_id,
                ":archive_title" => $form["archive_title"],
                ":archive_version" => nullIfEmpty($form["archive_version"]),
                ":archive_date" => nullIfEmpty($form["archive_date"]),
                ":package_status" => nullIfEmpty($form["package_status"]),
                ":total_program_courses" => (int)$form["total_program_courses"],
                ":total_course_specifications" => (int)$form["total_course_specifications"],
                ":uploaded_files_count" => (int)$form["uploaded_files_count"],
                ":docx_files_count" => (int)$form["docx_files_count"],
                ":pdf_files_count" => (int)$form["pdf_files_count"],
                ":imported_courses_count" => (int)$form["imported_courses_count"],
                ":ready_for_export_count" => (int)$form["ready_for_export_count"],
                ":incomplete_courses_count" => (int)$form["incomplete_courses_count"],
                ":bulk_zip_exported" => (int)$form["bulk_zip_exported"],
                ":bulk_zip_export_date" => nullIfEmpty($form["bulk_zip_export_date"]),
                ":review_status" => nullIfEmpty($form["review_status"]),
                ":approval_status" => nullIfEmpty($form["approval_status"]),
                ":archive_notes" => nullIfEmpty($form["archive_notes"])
            ]);

            $newId = (int)$conn->lastInsertId();

            logActivitySafe(
                $conn,
                "Create",
                "Course Specification Archive",
                $newId,
                "Created course specification archive record: " . $form["archive_title"]
            );

            header("Location: course_specification_archive.php?program_specification_id=" . $program_specification_id . "&saved=1");
            exit;
        }
    }
}

if (isset($_GET["saved"])) {
    $success = "Archive record saved successfully.";
}

/*
    Load archive records.
*/
$stmt = $conn->prepare("
    SELECT *
    FROM course_specification_archive
    WHERE program_specification_id = :program_specification_id
    ORDER BY archive_date DESC, id DESC
");
$stmt->execute([":program_specification_id" => $program_specification_id]);
$archiveRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalArchives = count($archiveRows);
$finalArchives = 0;

foreach ($archiveRows as $row) {
    if (strtolower(trim((string)($row["package_status"] ?? ""))) === "final") {
        $finalArchives++;
    }
}

$packageStatusOptions = ["Draft", "Under Review", "Final", "Archived", "Needs Revision"];
$reviewStatusOptions = ["Pending Review", "Reviewed", "Returned for Correction", "Approved by Reviewer"];
$approvalStatusOptions = ["Draft", "Approved", "Not Approved", "Archived"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Specification Archive</title>
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 14px;
        }

        .check-box {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
            margin-bottom: 10px;
        }

        textarea {
            min-height: 100px;
        }

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .alert-success {
            background: #d1e7dd;
            border: 1px solid #badbcc;
            color: #0f5132;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .wide-table {
            min-width: 1450px;
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
        }

        .badge-warning {
            background: #fff3cd;
            color: #664d03;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        @media (max-width: 1100px) {
            .summary-grid,
            .form-grid {
                grid-template-columns: repeat(2, minmax(130px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .summary-grid,
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Course Specification Archive</h1>
        <p>Archive finalized course specification packages and maintain version control.</p>
    </div>

    <div class="actions">
        <a href="program_specification_dashboard.php?id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Program Spec Dashboard
        </a>

        <a href="course_specification_export_center.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Export Center
        </a>

        <a href="course_specification_import_status.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Import Status
        </a>

        <a href="bulk_export_course_specifications_zip.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Export ZIP
        </a>

        <a href="course_specifications.php" class="btn btn-secondary">
            Course Specifications
        </a>
	<a href="export_course_specification_archive_word.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
    Export Archive Report
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
            <div class="summary-number"><?php echo safeText($uploadedFilesCount); ?></div>
            <div class="summary-label">Uploaded Files</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($importedCoursesCount); ?></div>
            <div class="summary-label">Imported Courses</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($readyForExportCount); ?></div>
            <div class="summary-label">Ready for Export</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalArchives); ?></div>
            <div class="summary-label">Archive Records</div>
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error"><?php echo safeText($error); ?></div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="alert-success"><?php echo safeText($success); ?></div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <div class="panel">
            <h2><?php echo $form["archive_id"] !== "" ? "Edit Archive Record" : "Create Archive Record"; ?></h2>

            <form method="POST" action="course_specification_archive.php?program_specification_id=<?php echo safeText($program_specification_id); ?>">

                <input type="hidden" name="archive_id" value="<?php echo safeText($form["archive_id"]); ?>">

                <div class="form-grid">
                    <div>
                        <label>Archive Title <span style="color:red;">*</span></label>
                        <input type="text" name="archive_title" required value="<?php echo safeText($form["archive_title"]); ?>">
                    </div>

                    <div>
                        <label>Archive Version</label>
                        <input type="text" name="archive_version" value="<?php echo safeText($form["archive_version"]); ?>" placeholder="Example: v1.0 / 2026 Final">
                    </div>

                    <div>
                        <label>Archive Date</label>
                        <input type="date" name="archive_date" value="<?php echo safeText($form["archive_date"]); ?>">
                    </div>

                    <div>
                        <label>Package Status</label>
                        <select name="package_status">
                            <?php foreach ($packageStatusOptions as $option): ?>
                                <option value="<?php echo safeText($option); ?>" <?php if ($form["package_status"] === $option) echo "selected"; ?>>
                                    <?php echo safeText($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Review Status</label>
                        <select name="review_status">
                            <?php foreach ($reviewStatusOptions as $option): ?>
                                <option value="<?php echo safeText($option); ?>" <?php if ($form["review_status"] === $option) echo "selected"; ?>>
                                    <?php echo safeText($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Approval Status</label>
                        <select name="approval_status">
                            <?php foreach ($approvalStatusOptions as $option): ?>
                                <option value="<?php echo safeText($option); ?>" <?php if ($form["approval_status"] === $option) echo "selected"; ?>>
                                    <?php echo safeText($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Bulk ZIP Export Date</label>
                        <input type="date" name="bulk_zip_export_date" value="<?php echo safeText($form["bulk_zip_export_date"]); ?>">
                    </div>
                </div>

                <div class="check-box">
                    <label>
                        <input type="checkbox" name="bulk_zip_exported" value="1" <?php if ((int)$form["bulk_zip_exported"] === 1) echo "checked"; ?>>
                        Bulk ZIP package exported
                    </label>
                </div>

                <h3>Current Package Counts</h3>

                <table>
                    <tr>
                        <th>Total Program Courses</th>
                        <td><?php echo safeText($totalProgramCourses); ?></td>
                        <th>Course Specification Records</th>
                        <td><?php echo safeText($totalCourseSpecifications); ?></td>
                    </tr>
                    <tr>
                        <th>Uploaded Files</th>
                        <td><?php echo safeText($uploadedFilesCount); ?></td>
                        <th>DOCX / PDF</th>
                        <td><?php echo safeText($docxFilesCount); ?> / <?php echo safeText($pdfFilesCount); ?></td>
                    </tr>
                    <tr>
                        <th>Imported Courses</th>
                        <td><?php echo safeText($importedCoursesCount); ?></td>
                        <th>Ready for Export</th>
                        <td><?php echo safeText($readyForExportCount); ?></td>
                    </tr>
                    <tr>
                        <th>Incomplete Courses</th>
                        <td colspan="3"><?php echo safeText($incompleteCoursesCount); ?></td>
                    </tr>
                </table>

                <label>Archive Notes</label>
                <textarea name="archive_notes" placeholder="Write review notes, version notes, approval notes, or ZIP package location."><?php echo safeText($form["archive_notes"]); ?></textarea>

                <br>

                <button type="submit" class="btn">
                    <?php echo $form["archive_id"] !== "" ? "Update Archive Record" : "Create Archive Record"; ?>
                </button>

                <?php if ($form["archive_id"] !== ""): ?>
                    <a href="course_specification_archive.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-secondary">
                        Cancel Edit
                    </a>
                <?php endif; ?>

            </form>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Archive Records</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
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
                    <th>Actions</th>
                </tr>

                <?php if (count($archiveRows) > 0): ?>
                    <?php foreach ($archiveRows as $row): ?>
                        <tr>
                            <td><?php echo safeText($row["archive_title"] ?? ""); ?></td>
                            <td><?php echo safeText($row["archive_version"] ?? ""); ?></td>
                            <td><?php echo safeText($row["archive_date"] ?? ""); ?></td>
                            <td>
                                <?php if (($row["package_status"] ?? "") === "Final"): ?>
                                    <span class="badge-ok">Final</span>
                                <?php else: ?>
                                    <span class="badge-warning"><?php echo safeText($row["package_status"] ?? ""); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo safeText($row["total_program_courses"] ?? "0"); ?></td>
                            <td><?php echo safeText($row["total_course_specifications"] ?? "0"); ?></td>
                            <td>
                                <?php echo safeText($row["uploaded_files_count"] ?? "0"); ?>
                                <br>
                                <span class="small-text">
                                    DOCX: <?php echo safeText($row["docx_files_count"] ?? "0"); ?> /
                                    PDF: <?php echo safeText($row["pdf_files_count"] ?? "0"); ?>
                                </span>
                            </td>
                            <td><?php echo safeText($row["imported_courses_count"] ?? "0"); ?></td>
                            <td><?php echo safeText($row["ready_for_export_count"] ?? "0"); ?></td>
                            <td><?php echo safeText($row["incomplete_courses_count"] ?? "0"); ?></td>
                            <td><?php echo (int)($row["bulk_zip_exported"] ?? 0) === 1 ? "Yes" : "No"; ?></td>
                            <td><?php echo safeText($row["bulk_zip_export_date"] ?? ""); ?></td>
                            <td><?php echo safeText($row["review_status"] ?? ""); ?></td>
                            <td><?php echo safeText($row["approval_status"] ?? ""); ?></td>
                            <td><?php echo nl2br(safeText($row["archive_notes"] ?? "")); ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <a href="course_specification_archive.php?program_specification_id=<?php echo safeText($program_specification_id); ?>&edit=<?php echo safeText($row["id"]); ?>" class="btn btn-edit">
                                        Edit
                                    </a>

                                    <a href="course_specification_archive.php?program_specification_id=<?php echo safeText($program_specification_id); ?>&delete=<?php echo safeText($row["id"]); ?>"
                                       class="btn btn-delete"
                                       onclick="return confirm('Delete this archive record?');">
                                        Delete
                                    </a>
                                <?php else: ?>
                                    <span class="small-text">View only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="16">No archive records created yet.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
