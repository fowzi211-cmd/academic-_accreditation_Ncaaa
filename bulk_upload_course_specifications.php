<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

$program_specification_id = isset($_GET["program_specification_id"]) && is_numeric($_GET["program_specification_id"])
    ? (int)$_GET["program_specification_id"]
    : 3;

$error = "";
$success = "";
$uploadResults = [];

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

function cleanFilename($filename) {
    $filename = preg_replace('/[^A-Za-z0-9_\.\-\s]/', '_', $filename);
    $filename = preg_replace('/\s+/', '_', $filename);
    return trim($filename, "_");
}

function detectCourseCodeFromFilename($filename) {
    $base = strtoupper($filename);

    if (preg_match('/\b([A-Z]{2,6}\s*\d{3,5}[A-Z]?)\b/', $base, $matches)) {
        return str_replace(" ", "", $matches[1]);
    }

    return "";
}

function getUserIdIfAvailable() {
    if (isset($_SESSION["user_id"]) && is_numeric($_SESSION["user_id"])) {
        return (int)$_SESSION["user_id"];
    }

    if (isset($_SESSION["id"]) && is_numeric($_SESSION["id"])) {
        return (int)$_SESSION["id"];
    }

    return null;
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "course_specifications")) {
    die("course_specifications table does not exist. Run course_specification_schema_update.php first.");
}

if (!tableExists($conn, "course_specification_files")) {
    die("course_specification_files table does not exist. Run course_specification_schema_update.php first.");
}

addColumnIfMissing($conn, "course_specification_files", "course_specification_id", "INT NULL");
addColumnIfMissing($conn, "course_specification_files", "program_specification_id", "INT NULL");
addColumnIfMissing($conn, "course_specification_files", "course_code", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "course_specification_files", "original_filename", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "course_specification_files", "stored_filename", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "course_specification_files", "file_path", "VARCHAR(500) NULL");
addColumnIfMissing($conn, "course_specification_files", "file_type", "VARCHAR(50) NULL");
addColumnIfMissing($conn, "course_specification_files", "mime_type", "VARCHAR(150) NULL");
addColumnIfMissing($conn, "course_specification_files", "file_size", "BIGINT DEFAULT 0");
addColumnIfMissing($conn, "course_specification_files", "uploaded_by", "INT NULL");
addColumnIfMissing($conn, "course_specification_files", "upload_date", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
addColumnIfMissing($conn, "course_specification_files", "notes", "TEXT NULL");

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

$programCourses = [];

if (tableExists($conn, "program_specification_courses")) {
    $courseStmt = $conn->prepare("
        SELECT *
        FROM program_specification_courses
        WHERE program_specification_id = :program_specification_id
        ORDER BY level_no ASC, course_code ASC, id ASC
    ");
    $courseStmt->execute([":program_specification_id" => $program_specification_id]);
    $programCourses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);
}

$programCoursesByCode = [];

foreach ($programCourses as $course) {
    $code = strtoupper(trim((string)($course["course_code"] ?? "")));

    if ($code !== "") {
        $programCoursesByCode[$code] = $course;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_FILES["course_files"])) {
        $error = "No files selected.";
    } else {
        $courseSpecDir = ensureStorageDirectory('uploads/course_specifications');

        $fileCount = count($_FILES["course_files"]["name"]);
        $allowedExtensions = ["doc", "docx", "pdf"];
        $uploadedBy = getUserIdIfAvailable();

        for ($i = 0; $i < $fileCount; $i++) {
            $originalName = $_FILES["course_files"]["name"][$i] ?? "";
            $tmpName = $_FILES["course_files"]["tmp_name"][$i] ?? "";
            $fileError = $_FILES["course_files"]["error"][$i] ?? UPLOAD_ERR_NO_FILE;
            $fileSize = (int)($_FILES["course_files"]["size"][$i] ?? 0);
            $mimeType = '';

            $result = [
                "filename" => $originalName,
                "course_code" => "",
                "status" => "Skipped",
                "message" => ""
            ];

            if ($fileError === UPLOAD_ERR_NO_FILE || $originalName === "") {
                $result["message"] = "No file selected.";
                $uploadResults[] = $result;
                continue;
            }

            if ($fileError !== UPLOAD_ERR_OK) {
                $result["status"] = "Failed";
                $result["message"] = "PHP upload error code: " . $fileError;
                $uploadResults[] = $result;
                continue;
            }

            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (!in_array($extension, $allowedExtensions, true)) {
                $result["status"] = "Failed";
                $result["message"] = "Invalid file type. Only .doc, .docx, .pdf allowed.";
                $uploadResults[] = $result;
                continue;
            }

            if ($fileSize <= 0) {
                $result["status"] = "Failed";
                $result["message"] = "File is empty.";
                $uploadResults[] = $result;
                continue;
            }

            if ($fileSize > 30 * 1024 * 1024) {
                $result["status"] = "Failed";
                $result["message"] = "File exceeds 30 MB.";
                $uploadResults[] = $result;
                continue;
            }

            try {
                $mimeType = validateUploadedDocument($tmpName, $extension, $allowedExtensions);
            } catch (RuntimeException $validationError) {
                $result["status"] = "Failed";
                $result["message"] = $validationError->getMessage();
                $uploadResults[] = $result;
                continue;
            }

            $courseCode = detectCourseCodeFromFilename($originalName);
            $result["course_code"] = $courseCode;

            $safeOriginal = cleanFilename($originalName);
            $storedName = "course_spec_" .
                $program_specification_id . "_" .
                ($courseCode !== "" ? $courseCode . "_" : "") .
                date("Ymd_His") . "_" .
                bin2hex(random_bytes(4)) . "." .
                $extension;

            $destination = $courseSpecDir . DIRECTORY_SEPARATOR . $storedName;
            $relativePath = "uploads/course_specifications/" . $storedName;

            if (!move_uploaded_file($tmpName, $destination)) {
                $result["status"] = "Failed";
                $result["message"] = "Could not move uploaded file.";
                $uploadResults[] = $result;
                continue;
            }

            try {
                $courseSpecificationId = null;
                $matchedCourse = null;

                if ($courseCode !== "" && isset($programCoursesByCode[$courseCode])) {
                    $matchedCourse = $programCoursesByCode[$courseCode];
                }

                if ($courseCode !== "") {
                    $findStmt = $conn->prepare("
                        SELECT id
                        FROM course_specifications
                        WHERE program_specification_id = :program_specification_id
                          AND UPPER(course_code) = :course_code
                        LIMIT 1
                    ");
                    $findStmt->execute([
                        ":program_specification_id" => $program_specification_id,
                        ":course_code" => strtoupper($courseCode)
                    ]);
                    $foundId = $findStmt->fetchColumn();

                    if ($foundId) {
                        $courseSpecificationId = (int)$foundId;
                    }
                }

                if ($courseSpecificationId === null && $courseCode !== "") {
                    $programCourseId = $matchedCourse["id"] ?? null;
                    $courseTitle = $matchedCourse["course_title"] ?? null;
                    $creditHours = $matchedCourse["credit_hours"] ?? 0;
                    $levelNo = $matchedCourse["level_no"] ?? null;

                    $createStmt = $conn->prepare("
                        INSERT INTO course_specifications
                        (
                            program_specification_id,
                            program_course_id,
                            course_code,
                            course_title,
                            program_name,
                            department_name,
                            college_name,
                            institution_name,
                            credit_hours,
                            course_level_year,
                            status,
                            approval_status,
                            notes
                        )
                        VALUES
                        (
                            :program_specification_id,
                            :program_course_id,
                            :course_code,
                            :course_title,
                            :program_name,
                            :department_name,
                            :college_name,
                            :institution_name,
                            :credit_hours,
                            :course_level_year,
                            :status,
                            :approval_status,
                            :notes
                        )
                    ");

                    $createStmt->execute([
                        ":program_specification_id" => $program_specification_id,
                        ":program_course_id" => $programCourseId,
                        ":course_code" => $courseCode,
                        ":course_title" => $courseTitle,
                        ":program_name" => $programSpec["program_name"] ?? null,
                        ":department_name" => $programSpec["department_name"] ?? null,
                        ":college_name" => $programSpec["college_name"] ?? null,
                        ":institution_name" => $programSpec["institution_name"] ?? null,
                        ":credit_hours" => $creditHours,
                        ":course_level_year" => $levelNo !== null ? "Level " . $levelNo : null,
                        ":status" => "File Uploaded",
                        ":approval_status" => "Needs Review",
                        ":notes" => "Created automatically during bulk upload."
                    ]);

                    $courseSpecificationId = (int)$conn->lastInsertId();
                }

                $insertFileStmt = $conn->prepare("
                    INSERT INTO course_specification_files
                    (
                        course_specification_id,
                        program_specification_id,
                        course_code,
                        original_filename,
                        stored_filename,
                        file_path,
                        file_type,
                        mime_type,
                        file_size,
                        uploaded_by,
                        notes
                    )
                    VALUES
                    (
                        :course_specification_id,
                        :program_specification_id,
                        :course_code,
                        :original_filename,
                        :stored_filename,
                        :file_path,
                        :file_type,
                        :mime_type,
                        :file_size,
                        :uploaded_by,
                        :notes
                    )
                ");

                $insertFileStmt->execute([
                    ":course_specification_id" => $courseSpecificationId,
                    ":program_specification_id" => $program_specification_id,
                    ":course_code" => $courseCode !== "" ? $courseCode : null,
                    ":original_filename" => $originalName,
                    ":stored_filename" => $storedName,
                    ":file_path" => $relativePath,
                    ":file_type" => $extension,
                    ":mime_type" => $mimeType,
                    ":file_size" => $fileSize,
                    ":uploaded_by" => $uploadedBy,
                    ":notes" => "Bulk uploaded course specification file."
                ]);

                $newFileId = (int)$conn->lastInsertId();

                logActivitySafe(
                    $conn,
                    "Upload",
                    "Bulk Course Specification File",
                    $newFileId,
                    "Bulk uploaded file: " . $originalName
                );

                $result["status"] = "Uploaded";
                $result["message"] = $courseCode !== ""
                    ? "Uploaded and linked/detected by course code."
                    : "Uploaded, but course code was not detected from filename.";

                $uploadResults[] = $result;

            } catch (Exception $e) {
                if (file_exists($destination)) {
                    unlink($destination);
                }

                $result["status"] = "Failed";
                $result["message"] = "Database save failed: " . $e->getMessage();
                $uploadResults[] = $result;
            }
        }

        $success = "Bulk upload completed. Review the result table below.";
    }
}

$filesStmt = $conn->prepare("
    SELECT 
        f.*,
        cs.course_title AS linked_course_title
    FROM course_specification_files f
    LEFT JOIN course_specifications cs ON f.course_specification_id = cs.id
    WHERE f.program_specification_id = :program_specification_id
    ORDER BY f.upload_date DESC, f.id DESC
");
$filesStmt->execute([":program_specification_id" => $program_specification_id]);
$uploadedFiles = $filesStmt->fetchAll(PDO::FETCH_ASSOC);

$uploadedCourseCodes = [];

foreach ($uploadedFiles as $file) {
    $code = strtoupper(trim((string)($file["course_code"] ?? "")));

    if ($code !== "") {
        $uploadedCourseCodes[$code] = true;
    }
}

$totalProgramCourses = count($programCourses);
$matchedProgramCourses = 0;

foreach ($programCourses as $course) {
    $code = strtoupper(trim((string)($course["course_code"] ?? "")));

    if ($code !== "" && isset($uploadedCourseCodes[$code])) {
        $matchedProgramCourses++;
    }
}

$uploadedCount = count($uploadedFiles);
$coveragePercent = $totalProgramCourses > 0 ? round(($matchedProgramCourses / $totalProgramCourses) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Bulk Upload Course Specifications</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(150px, 1fr));
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
            font-size: 26px;
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
            min-width: 1050px;
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

        .badge-danger {
            background: #f8d7da;
            color: #842029;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        @media (max-width: 900px) {
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
        <h1>Bulk Upload Course Specifications</h1>
        <p>Upload multiple Word/PDF course specification files and match them to Program Specification ID <?php echo safeText($program_specification_id); ?>.</p>
    </div>

    <div class="actions">
	<a href="bulk_import_course_specifications_from_docx.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
    Next Step: Import DOCX
</a>

	<a href="course_specification_workflow.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
    Back to Workflow
</a>
        <a href="program_specification_dashboard.php?id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Program Spec Dashboard
        </a>

        <a href="upload_course_specification_file.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Single Upload
        </a>

        <a href="import_course_specification_from_docx.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Import DOCX
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

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalProgramCourses); ?></div>
            <div class="summary-label">Program Courses</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($uploadedCount); ?></div>
            <div class="summary-label">Uploaded Files</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($matchedProgramCourses); ?></div>
            <div class="summary-label">Matched Courses</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($coveragePercent); ?>%</div>
            <div class="summary-label">Upload Coverage</div>
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error"><?php echo safeText($error); ?></div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="alert-success"><?php echo safeText($success); ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Upload Multiple Course Specification Files</h2>

        <form method="POST"
              <form method="POST" enctype="multipart/form-data" action="bulk_upload_course_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>">

            <label>Select Course Specification Files</label>
            <input type="file" name="course_files[]" multiple accept=".doc,.docx,.pdf">

            <p class="small-text">
                Allowed: .doc, .docx, .pdf. Maximum 30 MB per file.
                Filenames should contain the course code, for example:
                HP1001_Course_Specification.docx
            </p>

            <button type="submit" class="btn btn-purple">
                Upload Selected Files
            </button>
        </form>
    </div>

    <?php if (!empty($uploadResults)): ?>
        <div class="panel">
            <h2>Bulk Upload Results</h2>

            <table>
                <tr>
                    <th>Filename</th>
                    <th>Detected Course Code</th>
                    <th>Status</th>
                    <th>Message</th>
                </tr>

                <?php foreach ($uploadResults as $result): ?>
                    <tr>
                        <td><?php echo safeText($result["filename"]); ?></td>
                        <td><?php echo safeText($result["course_code"]); ?></td>
                        <td>
                            <?php if ($result["status"] === "Uploaded"): ?>
                                <span class="badge-ok">Uploaded</span>
                            <?php elseif ($result["status"] === "Failed"): ?>
                                <span class="badge-danger">Failed</span>
                            <?php else: ?>
                                <span class="badge-warning">Skipped</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo safeText($result["message"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Uploaded Course Specification Files</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>ID</th>
                    <th>Course Code</th>
                    <th>Linked Course Title</th>
                    <th>Original File</th>
                    <th>Type</th>
                    <th>Size</th>
                    <th>Upload Date</th>
                    <th>Open</th>
                </tr>

                <?php if (count($uploadedFiles) > 0): ?>
                    <?php foreach ($uploadedFiles as $file): ?>
                        <tr>
                            <td><?php echo safeText($file["id"]); ?></td>
                            <td><?php echo safeText($file["course_code"] ?? ""); ?></td>
                            <td><?php echo safeText($file["linked_course_title"] ?? ""); ?></td>
                            <td><?php echo safeText($file["original_filename"] ?? ""); ?></td>
                            <td><?php echo safeText($file["file_type"] ?? ""); ?></td>
                            <td><?php echo safeText(round(((int)($file["file_size"] ?? 0)) / 1024, 1)); ?> KB</td>
                            <td><?php echo safeText($file["upload_date"] ?? ""); ?></td>
                            <td>
                                <?php if (!empty($file["file_path"])): ?>
                                    <a href="download_file.php?type=course_specification&amp;id=<?php echo urlencode((string)$file['id']); ?>" class="btn" target="_blank">
                                        Open
                                    </a>
                                <?php else: ?>
                                    <span class="small-text">No path</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8">No course specification files uploaded yet.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="panel">
        <h2>Program Course Upload Coverage</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Level</th>
                    <th>Course Code</th>
                    <th>Course Title</th>
                    <th>Credit Hours</th>
                    <th>Upload Status</th>
                </tr>

                <?php if (count($programCourses) > 0): ?>
                    <?php foreach ($programCourses as $course): ?>
                        <?php
                        $code = strtoupper(trim((string)($course["course_code"] ?? "")));
                        $hasUpload = $code !== "" && isset($uploadedCourseCodes[$code]);
                        ?>
                        <tr>
                            <td><?php echo safeText($course["level_no"] ?? ""); ?></td>
                            <td><?php echo safeText($course["course_code"] ?? ""); ?></td>
                            <td><?php echo safeText($course["course_title"] ?? ""); ?></td>
                            <td><?php echo safeText($course["credit_hours"] ?? ""); ?></td>
                            <td>
                                <?php if ($hasUpload): ?>
                                    <span class="badge-ok">Uploaded</span>
                                <?php else: ?>
                                    <span class="badge-danger">Missing</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">No program courses found for this program specification.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
