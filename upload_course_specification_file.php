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

$course_specification_id = isset($_GET["course_specification_id"]) && is_numeric($_GET["course_specification_id"])
    ? (int)$_GET["course_specification_id"]
    : null;

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

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

function cleanFilename($filename) {
    $filename = preg_replace('/[^A-Za-z0-9_\.\-\s]/', '_', $filename);
    $filename = preg_replace('/\s+/', '_', $filename);
    return trim($filename, "_");
}

function detectCourseCodeFromFilename($filename) {
    $base = strtoupper($filename);

    if (preg_match('/\b([A-Z]{2,5}\s*\d{3,5}[A-Z]?)\b/', $base, $matches)) {
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

/*
    Load program courses from Program Specification ID = 3.
    These are used for matching uploaded course spec files by course code.
*/
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

$selectedCourseSpec = null;

if ($course_specification_id !== null) {
    $specStmt = $conn->prepare("
        SELECT *
        FROM course_specifications
        WHERE id = :id
        LIMIT 1
    ");
    $specStmt->execute([":id" => $course_specification_id]);
    $selectedCourseSpec = $specStmt->fetch(PDO::FETCH_ASSOC);
}

/*
    Handle upload.
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedProgramSpecId = isset($_POST["program_specification_id"]) && is_numeric($_POST["program_specification_id"])
        ? (int)$_POST["program_specification_id"]
        : $program_specification_id;

    $postedCourseSpecId = isset($_POST["course_specification_id"]) && is_numeric($_POST["course_specification_id"])
        ? (int)$_POST["course_specification_id"]
        : null;

    $courseCode = strtoupper(trim($_POST["course_code"] ?? ""));
    $notes = trim($_POST["notes"] ?? "");

    if (!isset($_FILES["course_file"]) || $_FILES["course_file"]["error"] === UPLOAD_ERR_NO_FILE) {
        $error = "Please select a Word or PDF course specification file.";
    } elseif ($_FILES["course_file"]["error"] !== UPLOAD_ERR_OK) {
        $error = "File upload failed. PHP upload error code: " . (int)$_FILES["course_file"]["error"];
    } else {
        $originalName = $_FILES["course_file"]["name"];
        $tmpName = $_FILES["course_file"]["tmp_name"];
        $fileSize = (int)$_FILES["course_file"]["size"];
        $mimeType = '';

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ["doc", "docx", "pdf"];

        if (!in_array($extension, $allowedExtensions, true)) {
            $error = "Only Word and PDF files are allowed: .doc, .docx, .pdf";
        } elseif ($fileSize <= 0) {
            $error = "The selected file is empty.";
        } elseif ($fileSize > 30 * 1024 * 1024) {
            $error = "File is too large. Maximum allowed size is 30 MB.";
        } else {
            try {
                $mimeType = validateUploadedDocument($tmpName, $extension, $allowedExtensions);
            } catch (RuntimeException $validationError) {
                $error = $validationError->getMessage();
            }

        if ($error === '') {
            if ($courseCode === "") {
                $courseCode = detectCourseCodeFromFilename($originalName);
            }

            $courseSpecDir = ensureStorageDirectory('uploads/course_specifications');

            $storedName = "course_spec_" .
                $postedProgramSpecId . "_" .
                ($courseCode !== "" ? $courseCode . "_" : "") .
                date("Ymd_His") . "_" .
                bin2hex(random_bytes(4)) . "." .
                $extension;

            $destination = $courseSpecDir . DIRECTORY_SEPARATOR . $storedName;
            $relativePath = "uploads/course_specifications/" . $storedName;

            if (!move_uploaded_file($tmpName, $destination)) {
                $error = "Could not move uploaded file to uploads/course_specifications.";
            } else {
                try {
                    $insertStmt = $conn->prepare("
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

                    $insertStmt->execute([
                        ":course_specification_id" => $postedCourseSpecId,
                        ":program_specification_id" => $postedProgramSpecId,
                        ":course_code" => $courseCode !== "" ? $courseCode : null,
                        ":original_filename" => $originalName,
                        ":stored_filename" => $storedName,
                        ":file_path" => $relativePath,
                        ":file_type" => $extension,
                        ":mime_type" => $mimeType,
                        ":file_size" => $fileSize,
                        ":uploaded_by" => getUserIdIfAvailable(),
                        ":notes" => $notes !== "" ? $notes : null
                    ]);

                    $newFileId = (int)$conn->lastInsertId();

                    /*
                        If no course specification record is linked yet, create a lightweight main record
                        using course code and program course data if found.
                    */
                    if ($postedCourseSpecId === null && $courseCode !== "") {
                        $existingSpecStmt = $conn->prepare("
                            SELECT id
                            FROM course_specifications
                            WHERE program_specification_id = :program_specification_id
                              AND UPPER(course_code) = :course_code
                            LIMIT 1
                        ");
                        $existingSpecStmt->execute([
                            ":program_specification_id" => $postedProgramSpecId,
                            ":course_code" => strtoupper($courseCode)
                        ]);
                        $existingCourseSpecId = $existingSpecStmt->fetchColumn();

                        if ($existingCourseSpecId) {
                            $postedCourseSpecId = (int)$existingCourseSpecId;
                        } else {
                            $matchedProgramCourse = null;

                            foreach ($programCourses as $pc) {
                                if (strtoupper(trim((string)($pc["course_code"] ?? ""))) === strtoupper($courseCode)) {
                                    $matchedProgramCourse = $pc;
                                    break;
                                }
                            }

                            $courseTitle = $matchedProgramCourse["course_title"] ?? "";
                            $programCourseId = $matchedProgramCourse["id"] ?? null;
                            $creditHours = $matchedProgramCourse["credit_hours"] ?? 0;
                            $levelNo = $matchedProgramCourse["level_no"] ?? "";

                            $createSpecStmt = $conn->prepare("
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

                            $createSpecStmt->execute([
                                ":program_specification_id" => $postedProgramSpecId,
                                ":program_course_id" => $programCourseId,
                                ":course_code" => $courseCode,
                                ":course_title" => $courseTitle !== "" ? $courseTitle : null,
                                ":program_name" => $programSpec["program_name"] ?? null,
                                ":department_name" => $programSpec["department_name"] ?? null,
                                ":college_name" => $programSpec["college_name"] ?? null,
                                ":institution_name" => $programSpec["institution_name"] ?? null,
                                ":credit_hours" => $creditHours,
                                ":course_level_year" => $levelNo !== "" ? "Level " . $levelNo : null,
                                ":status" => "File Uploaded",
                                ":approval_status" => "Needs Review",
                                ":notes" => "Created automatically from uploaded course specification file."
                            ]);

                            $postedCourseSpecId = (int)$conn->lastInsertId();
                        }

                        $updateFileStmt = $conn->prepare("
                            UPDATE course_specification_files
                            SET course_specification_id = :course_specification_id
                            WHERE id = :id
                        ");
                        $updateFileStmt->execute([
                            ":course_specification_id" => $postedCourseSpecId,
                            ":id" => $newFileId
                        ]);
                    }

                    logActivitySafe(
                        $conn,
                        "Upload",
                        "Course Specification File",
                        $newFileId,
                        "Uploaded course specification file: " . $originalName
                    );

                    header("Location: upload_course_specification_file.php?program_specification_id=" . $postedProgramSpecId . "&uploaded=1");
                    exit;

                } catch (Exception $e) {
                    if (file_exists($destination)) {
                        unlink($destination);
                    }

                    $error = "Database save failed: " . $e->getMessage();
                }
            }
        }
        }
    }
}

if (isset($_GET["uploaded"])) {
    $success = "Course specification file uploaded successfully.";
}

/*
    Load uploaded files.
*/
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

$uploadedCount = count($uploadedFiles);

$uploadedCourseCodes = [];
foreach ($uploadedFiles as $file) {
    $code = strtoupper(trim((string)($file["course_code"] ?? "")));
    if ($code !== "") {
        $uploadedCourseCodes[$code] = true;
    }
}

$matchedProgramCourses = 0;
foreach ($programCourses as $course) {
    $code = strtoupper(trim((string)($course["course_code"] ?? "")));
    if ($code !== "" && isset($uploadedCourseCodes[$code])) {
        $matchedProgramCourses++;
    }
}

$totalProgramCourses = count($programCourses);
$coveragePercent = $totalProgramCourses > 0 ? round(($matchedProgramCourses / $totalProgramCourses) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Upload Course Specification File</title>
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(240px, 1fr));
            gap: 14px;
        }

        textarea {
            min-height: 95px;
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
            min-width: 1100px;
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

        .badge-missing {
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
        <h1>Upload Course Specification File</h1>
        <p>Upload Word/PDF course specification files and match them to imported program courses.</p>
    </div>

    <div class="actions">
        <a href="program_specification_dashboard.php?id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Program Spec Dashboard
        </a>

        <a href="program_specification_profile.php?id=<?php echo safeText($program_specification_id); ?>" class="btn btn-secondary">
            Back to Program Specification
        </a>

        <a href="course_specifications.php" class="btn">
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
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="alert-success">
            <?php echo safeText($success); ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Upload New Course Specification File</h2>

        <form method="POST"
              action="upload_course_specification_file.php?program_specification_id=<?php echo safeText($program_specification_id); ?>"
              enctype="multipart/form-data">

            <input type="hidden" name="program_specification_id" value="<?php echo safeText($program_specification_id); ?>">

            <div class="form-grid">
                <div>
                    <label>Course Code</label>
                    <input type="text" name="course_code" placeholder="Example: HP1001">
                    <p class="small-text">
                        Leave blank if the filename contains the course code. The system will try to detect it.
                    </p>
                </div>

                <div>
                    <label>Course Specification File <span style="color:red;">*</span></label>
                    <input type="file" name="course_file" accept=".doc,.docx,.pdf" required>
                    <p class="small-text">
                        Allowed: .doc, .docx, .pdf. Maximum size: 30 MB.
                    </p>
                </div>
            </div>

            <label>Upload Notes</label>
            <textarea name="notes" placeholder="Example: Approved course specification, version 1, uploaded from official file."></textarea>

            <br>

            <button type="submit" class="btn">
                Upload Course Specification
            </button>
        </form>
    </div>

    <div class="panel">
        <h2>Uploaded Course Specification Files</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Course Code</th>
                    <th>Linked Course Title</th>
                    <th>Original File</th>
                    <th>Type</th>
                    <th>Size</th>
                    <th>Upload Date</th>
                    <th>Notes</th>
                    <th>Open</th>
                </tr>

                <?php if (count($uploadedFiles) > 0): ?>
                    <?php foreach ($uploadedFiles as $file): ?>
                        <tr>
                            <td><?php echo safeText($file["course_code"] ?? ""); ?></td>
                            <td><?php echo safeText($file["linked_course_title"] ?? ""); ?></td>
                            <td><?php echo safeText($file["original_filename"] ?? ""); ?></td>
                            <td><?php echo safeText($file["file_type"] ?? ""); ?></td>
                            <td><?php echo safeText(round(((int)($file["file_size"] ?? 0)) / 1024, 1)); ?> KB</td>
                            <td><?php echo safeText($file["upload_date"] ?? ""); ?></td>
                            <td><?php echo nl2br(safeText($file["notes"] ?? "")); ?></td>
                            <td>
                                <?php if (!empty($file["file_path"])): ?>
                                    <a href="download_file.php?type=course_specification&amp;id=<?php echo urlencode((string)$file['id']); ?>" class="btn" target="_blank">
                                        Open
                                    </a>
                                <?php else: ?>
                                    <span class="small-text">No file path</span>
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
                                    <span class="badge-missing">Missing</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">
                            No program courses found. Import courses into Program Specification ID <?php echo safeText($program_specification_id); ?> first.
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
