<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty"]);

$report_id = 0;

if (isset($_GET["id"]) && is_numeric($_GET["id"])) {
    $report_id = (int)$_GET["id"];
} elseif (isset($_GET["report_id"]) && is_numeric($_GET["report_id"])) {
    $report_id = (int)$_GET["report_id"];
}

if ($report_id <= 0) {
    die("Invalid course report ID.");
}

$error = "";
$success = "";
$importResults = [];
$gradeSummary = [];

function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function nlText($text) {
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

function cleanHeader($header) {
    $header = strtolower(trim((string)$header));
    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
    $header = preg_replace('/\s+/', '_', $header);
    $header = str_replace("-", "_", $header);
    return $header;
}

function numericOrNull($value) {
    $value = trim((string)$value);

    if ($value === "") {
        return null;
    }

    $value = str_replace(",", "", $value);

    return is_numeric($value) ? (float)$value : null;
}

function normalizeCourseCode($code) {
    $code = strtoupper(trim((string)$code));
    $code = preg_replace('/\s+/', '', $code);
    return $code;
}

function getGradeLabel($mark) {
    if ($mark === null) {
        return "";
    }

    $mark = (float)$mark;

    if ($mark >= 95) {
        return "A+";
    } elseif ($mark >= 90) {
        return "A";
    } elseif ($mark >= 85) {
        return "B+";
    } elseif ($mark >= 80) {
        return "B";
    } elseif ($mark >= 75) {
        return "C+";
    } elseif ($mark >= 70) {
        return "C";
    } elseif ($mark >= 65) {
        return "D+";
    } elseif ($mark >= 60) {
        return "D";
    }

    return "F";
}

function getResultStatus($gradeLabel) {
    if ($gradeLabel === "" || $gradeLabel === "F") {
        return "Failed";
    }

    return "Passed";
}

function ensureTables($conn) {
    requireSchemaTable($conn, "course_report_student_results");

    addColumnIfMissing($conn, "course_report_student_results", "course_report_id", "INT NOT NULL");
    addColumnIfMissing($conn, "course_report_student_results", "course_code", "VARCHAR(50) NULL");
    addColumnIfMissing($conn, "course_report_student_results", "course_coordinator", "VARCHAR(255) NULL");
    addColumnIfMissing($conn, "course_report_student_results", "student_id", "VARCHAR(100) NULL");
    addColumnIfMissing($conn, "course_report_student_results", "student_name", "VARCHAR(255) NULL");
    addColumnIfMissing($conn, "course_report_student_results", "coursework", "DECIMAL(8,2) NULL");
    addColumnIfMissing($conn, "course_report_student_results", "quiz_exam", "DECIMAL(8,2) NULL");
    addColumnIfMissing($conn, "course_report_student_results", "midterm", "DECIMAL(8,2) NULL");
    addColumnIfMissing($conn, "course_report_student_results", "final_exam", "DECIMAL(8,2) NULL");
    addColumnIfMissing($conn, "course_report_student_results", "total_mark", "DECIMAL(8,2) NULL");
    addColumnIfMissing($conn, "course_report_student_results", "grade_label", "VARCHAR(20) NULL");
    addColumnIfMissing($conn, "course_report_student_results", "result_status", "VARCHAR(50) NULL");
    addColumnIfMissing($conn, "course_report_student_results", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

    requireSchemaTable($conn, "course_report_grade_distribution");

    addColumnIfMissing($conn, "course_report_grade_distribution", "course_report_id", "INT NOT NULL");
    addColumnIfMissing($conn, "course_report_grade_distribution", "grade_label", "VARCHAR(50) NULL");
    addColumnIfMissing($conn, "course_report_grade_distribution", "student_count", "INT DEFAULT 0");
    addColumnIfMissing($conn, "course_report_grade_distribution", "percentage", "DECIMAL(6,2) NULL");
    addColumnIfMissing($conn, "course_report_grade_distribution", "notes", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_grade_distribution", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

function calculateGradeSummary($conn, $reportId) {
    $gradeOrder = ["A+", "A", "B+", "B", "C+", "C", "D+", "D", "F"];
    $summary = [];

    foreach ($gradeOrder as $grade) {
        $summary[$grade] = [
            "grade_label" => $grade,
            "student_count" => 0,
            "percentage" => 0
        ];
    }

    $stmt = $conn->prepare("
        SELECT grade_label, COUNT(*) AS total
        FROM course_report_student_results
        WHERE course_report_id = :course_report_id
          AND grade_label IS NOT NULL
          AND grade_label <> ''
        GROUP BY grade_label
    ");
    $stmt->execute([":course_report_id" => $reportId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStudents = 0;

    foreach ($rows as $row) {
        $grade = trim((string)($row["grade_label"] ?? ""));
        $count = (int)($row["total"] ?? 0);

        if (!isset($summary[$grade])) {
            continue;
        }

        $summary[$grade]["student_count"] = $count;
        $totalStudents += $count;
    }

    foreach ($summary as $grade => $row) {
        $summary[$grade]["percentage"] = $totalStudents > 0
            ? round(($row["student_count"] / $totalStudents) * 100, 2)
            : 0;
    }

    return [
        "summary" => $summary,
        "total_students" => $totalStudents
    ];
}

function refreshGradeDistribution($conn, $reportId) {
    $gradeData = calculateGradeSummary($conn, $reportId);
    $summary = $gradeData["summary"];
    $totalStudents = $gradeData["total_students"];

    $deleteStmt = $conn->prepare("
        DELETE FROM course_report_grade_distribution
        WHERE course_report_id = :course_report_id
    ");
    $deleteStmt->execute([":course_report_id" => $reportId]);

    $insertStmt = $conn->prepare("
        INSERT INTO course_report_grade_distribution
        (
            course_report_id,
            grade_label,
            student_count,
            percentage,
            notes
        )
        VALUES
        (
            :course_report_id,
            :grade_label,
            :student_count,
            :percentage,
            :notes
        )
    ");

    foreach ($summary as $grade => $row) {
        $insertStmt->execute([
            ":course_report_id" => $reportId,
            ":grade_label" => $grade,
            ":student_count" => $row["student_count"],
            ":percentage" => $row["percentage"],
            ":notes" => "Generated automatically from imported student results."
        ]);
    }

    $passedStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM course_report_student_results
        WHERE course_report_id = :course_report_id
          AND result_status = 'Passed'
    ");
    $passedStmt->execute([":course_report_id" => $reportId]);
    $passed = (int)$passedStmt->fetchColumn();

    $failedStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM course_report_student_results
        WHERE course_report_id = :course_report_id
          AND result_status = 'Failed'
    ");
    $failedStmt->execute([":course_report_id" => $reportId]);
    $failed = (int)$failedStmt->fetchColumn();

    $coordinatorStmt = $conn->prepare("
        SELECT course_coordinator
        FROM course_report_student_results
        WHERE course_report_id = :course_report_id
          AND course_coordinator IS NOT NULL
          AND course_coordinator <> ''
        ORDER BY id ASC
        LIMIT 1
    ");
    $coordinatorStmt->execute([":course_report_id" => $reportId]);
    $detectedCoordinator = $coordinatorStmt->fetchColumn();

    $updateReportStmt = $conn->prepare("
        UPDATE course_reports
        SET
            students_enrolled = :students_enrolled,
            students_starting = :students_starting,
            students_completed = :students_completed,
            students_passed = :students_passed,
            students_failed = :students_failed,
            course_coordinator = COALESCE(NULLIF(:course_coordinator, ''), course_coordinator)
        WHERE id = :id
    ");
    $updateReportStmt->execute([
        ":students_enrolled" => $totalStudents,
        ":students_starting" => $totalStudents,
        ":students_completed" => $totalStudents,
        ":students_passed" => $passed,
        ":students_failed" => $failed,
        ":course_coordinator" => $detectedCoordinator ?: "",
        ":id" => $reportId
    ]);

    return [
        "total_students" => $totalStudents,
        "passed" => $passed,
        "failed" => $failed,
        "summary" => $summary
    ];
}

if (!tableExists($conn, "course_reports")) {
    die("course_reports table does not exist.");
}

ensureTables($conn);

/*
    Load report.
*/
$stmt = $conn->prepare("
    SELECT 
        cr.*,
        cs.id AS spec_id,
        cs.course_code AS spec_course_code,
        cs.course_title AS spec_course_title,
        cs.program_specification_id
    FROM course_reports cr
    LEFT JOIN course_specifications cs
        ON cs.id = cr.course_specification_id
    WHERE cr.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $report_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die("Course report not found.");
}

$program_specification_id = isset($report["program_specification_id"]) && is_numeric($report["program_specification_id"])
    ? (int)$report["program_specification_id"]
    : 3;

$reportCourseCode = trim((string)($report["course_code"] ?? ""));

/*
    Handle actions.
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    try {
        if ($action === "import_csv") {
            if (!isset($_FILES["results_file"]) || $_FILES["results_file"]["error"] !== UPLOAD_ERR_OK) {
                throw new Exception("No valid CSV file uploaded.");
            }

            $originalName = $_FILES["results_file"]["name"] ?? "";
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if ($extension !== "csv") {
                throw new Exception("This version accepts CSV only. Save Excel as CSV first.");
            }

            $replaceExisting = isset($_POST["replace_existing"]);

            $filePath = $_FILES["results_file"]["tmp_name"];

            if (!is_uploaded_file($filePath)) {
                throw new Exception("Uploaded file could not be read.");
            }

            $handle = fopen($filePath, "r");

            if (!$handle) {
                throw new Exception("Could not open uploaded CSV file.");
            }

            $headers = fgetcsv($handle);

            if (!$headers || count($headers) === 0) {
                fclose($handle);
                throw new Exception("CSV file is empty or missing headers.");
            }

            $headerMap = [];

            foreach ($headers as $index => $header) {
                $headerMap[cleanHeader($header)] = $index;
            }

            $requiredHeaders = [
                "course_code",
                "course_coordinator",
                "student_id",
                "student_name",
                "coursework",
                "quiz_exam",
                "midterm",
                "final_exam",
                "total_mark"
            ];

            foreach ($requiredHeaders as $required) {
                if (!array_key_exists($required, $headerMap)) {
                    fclose($handle);
                    throw new Exception("Missing required CSV column: " . $required);
                }
            }

            $conn->beginTransaction();

            if ($replaceExisting) {
                $deleteStudentsStmt = $conn->prepare("
                    DELETE FROM course_report_student_results
                    WHERE course_report_id = :course_report_id
                ");
                $deleteStudentsStmt->execute([":course_report_id" => $report_id]);

                $deleteGradesStmt = $conn->prepare("
                    DELETE FROM course_report_grade_distribution
                    WHERE course_report_id = :course_report_id
                ");
                $deleteGradesStmt->execute([":course_report_id" => $report_id]);
            }

            $insertStmt = $conn->prepare("
                INSERT INTO course_report_student_results
                (
                    course_report_id,
                    course_code,
                    course_coordinator,
                    student_id,
                    student_name,
                    coursework,
                    quiz_exam,
                    midterm,
                    final_exam,
                    total_mark,
                    grade_label,
                    result_status
                )
                VALUES
                (
                    :course_report_id,
                    :course_code,
                    :course_coordinator,
                    :student_id,
                    :student_name,
                    :coursework,
                    :quiz_exam,
                    :midterm,
                    :final_exam,
                    :total_mark,
                    :grade_label,
                    :result_status
                )
            ");

            $importedCount = 0;
            $skippedCount = 0;
            $lineNo = 1;

            while (($data = fgetcsv($handle)) !== false) {
                $lineNo++;

                if (count($data) === 1 && trim((string)$data[0]) === "") {
                    continue;
                }

                $csvCourseCode = trim((string)($data[$headerMap["course_code"]] ?? ""));
                $csvCourseCoordinator = trim((string)($data[$headerMap["course_coordinator"]] ?? ""));

                $studentId = trim((string)($data[$headerMap["student_id"]] ?? ""));
                $studentName = trim((string)($data[$headerMap["student_name"]] ?? ""));

                $coursework = numericOrNull($data[$headerMap["coursework"]] ?? "");
                $quizExam = numericOrNull($data[$headerMap["quiz_exam"]] ?? "");
                $midterm = numericOrNull($data[$headerMap["midterm"]] ?? "");
                $finalExam = numericOrNull($data[$headerMap["final_exam"]] ?? "");
                $totalMark = numericOrNull($data[$headerMap["total_mark"]] ?? "");

                if (normalizeCourseCode($csvCourseCode) !== normalizeCourseCode($reportCourseCode)) {
                    $skippedCount++;
                    $importResults[] = [
                        "line" => $lineNo,
                        "student_id" => $studentId,
                        "student_name" => $studentName,
                        "status" => "Skipped",
                        "message" => "CSV course_code ($csvCourseCode) does not match report course_code ($reportCourseCode)."
                    ];
                    continue;
                }

                if ($studentId === "" && $studentName === "") {
                    $skippedCount++;
                    $importResults[] = [
                        "line" => $lineNo,
                        "student_id" => $studentId,
                        "student_name" => $studentName,
                        "status" => "Skipped",
                        "message" => "Missing student ID and name."
                    ];
                    continue;
                }

                if ($totalMark === null) {
                    $skippedCount++;
                    $importResults[] = [
                        "line" => $lineNo,
                        "student_id" => $studentId,
                        "student_name" => $studentName,
                        "status" => "Skipped",
                        "message" => "Missing or invalid total_mark."
                    ];
                    continue;
                }

                if ($totalMark < 0 || $totalMark > 100) {
                    $skippedCount++;
                    $importResults[] = [
                        "line" => $lineNo,
                        "student_id" => $studentId,
                        "student_name" => $studentName,
                        "status" => "Skipped",
                        "message" => "total_mark must be between 0 and 100."
                    ];
                    continue;
                }

                $gradeLabel = getGradeLabel($totalMark);
                $resultStatus = getResultStatus($gradeLabel);

                $insertStmt->execute([
                    ":course_report_id" => $report_id,
                    ":course_code" => $csvCourseCode,
                    ":course_coordinator" => $csvCourseCoordinator,
                    ":student_id" => $studentId,
                    ":student_name" => $studentName,
                    ":coursework" => $coursework,
                    ":quiz_exam" => $quizExam,
                    ":midterm" => $midterm,
                    ":final_exam" => $finalExam,
                    ":total_mark" => $totalMark,
                    ":grade_label" => $gradeLabel,
                    ":result_status" => $resultStatus
                ]);

                $importedCount++;

                $importResults[] = [
                    "line" => $lineNo,
                    "student_id" => $studentId,
                    "student_name" => $studentName,
                    "status" => "Imported",
                    "message" => "Grade: " . $gradeLabel . " | " . $resultStatus
                ];
            }

            fclose($handle);

            refreshGradeDistribution($conn, $report_id);

            $conn->commit();

            logActivitySafe(
                $conn,
                "Import",
                "Course Report Student Results",
                $report_id,
                "Imported $importedCount student result rows into course report ID $report_id."
            );

            $success = "Import completed. Imported: $importedCount. Skipped: $skippedCount. Grade distribution and student numbers updated.";
        }

        if ($action === "refresh_grade_distribution") {
            $conn->beginTransaction();
            refreshGradeDistribution($conn, $report_id);
            $conn->commit();

            $success = "Grade distribution refreshed from existing student results.";

            logActivitySafe(
                $conn,
                "Refresh",
                "Course Report Grade Distribution",
                $report_id,
                "Refreshed grade distribution from student results for report ID $report_id."
            );
        }

        if ($action === "delete_student_results") {
            $conn->beginTransaction();

            $deleteStudentsStmt = $conn->prepare("
                DELETE FROM course_report_student_results
                WHERE course_report_id = :course_report_id
            ");
            $deleteStudentsStmt->execute([":course_report_id" => $report_id]);

            $deleteGradesStmt = $conn->prepare("
                DELETE FROM course_report_grade_distribution
                WHERE course_report_id = :course_report_id
            ");
            $deleteGradesStmt->execute([":course_report_id" => $report_id]);

            $updateReportStmt = $conn->prepare("
                UPDATE course_reports
                SET
                    students_enrolled = 0,
                    students_starting = NULL,
                    students_completed = NULL,
                    students_passed = 0,
                    students_failed = 0
                WHERE id = :id
            ");
            $updateReportStmt->execute([":id" => $report_id]);

            $conn->commit();

            $success = "Student results and grade distribution deleted.";

            logActivitySafe(
                $conn,
                "Delete",
                "Course Report Student Results",
                $report_id,
                "Deleted student results and grade distribution for report ID $report_id."
            );
        }

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        $error = $e->getMessage();
    }

    /*
        Reload report after changes.
    */
    $stmt = $conn->prepare("
        SELECT 
            cr.*,
            cs.id AS spec_id,
            cs.course_code AS spec_course_code,
            cs.course_title AS spec_course_title,
            cs.program_specification_id
        FROM course_reports cr
        LEFT JOIN course_specifications cs
            ON cs.id = cr.course_specification_id
        WHERE cr.id = :id
        LIMIT 1
    ");
    $stmt->execute([":id" => $report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
}

/*
    Load current student results.
*/
$studentsStmt = $conn->prepare("
    SELECT *
    FROM course_report_student_results
    WHERE course_report_id = :course_report_id
    ORDER BY total_mark DESC, student_name ASC
");
$studentsStmt->execute([":course_report_id" => $report_id]);
$studentRows = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

$gradeData = calculateGradeSummary($conn, $report_id);
$gradeSummary = $gradeData["summary"];
$totalStudents = $gradeData["total_students"];

$passed = 0;
$failed = 0;

foreach ($studentRows as $student) {
    if (($student["result_status"] ?? "") === "Passed") {
        $passed++;
    } elseif (($student["result_status"] ?? "") === "Failed") {
        $failed++;
    }
}

$passRate = $totalStudents > 0 ? round(($passed / $totalStudents) * 100, 1) : 0;

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Import Student Results</title>
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
            grid-template-columns: repeat(4, minmax(140px, 1fr));
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

        .actions a,
        .actions button {
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
            min-width: 1250px;
            width: 100%;
            border-collapse: collapse;
        }

        .small-text {
            font-size: 12px;
            color: #555;
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

        .badge-danger {
            background: #f8d7da;
            color: #842029;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }

        .template-box {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 12px;
            border-radius: 8px;
            font-family: Consolas, monospace;
            font-size: 13px;
            white-space: pre-wrap;
        }

        @media (max-width: 900px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(140px, 1fr));
            }
        }

        @media (max-width: 650px) {
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
        <h1>Import Student Results</h1>
        <p>Import CSV student marks and automatically generate grade distribution for the course report.</p>
    </div>

    <div class="actions">
        <a href="manage_course_report_details.php?id=<?php echo safeText($report_id); ?>" class="btn btn-purple">
            Manage Report Details
        </a>

        <a href="view_course_report.php?id=<?php echo safeText($report_id); ?>" class="btn">
            View Report
        </a>

        <a href="course_report_completion_check.php?id=<?php echo safeText($report_id); ?>" class="btn">
            Completion Check
        </a>

        <a href="export_course_report_word.php?id=<?php echo safeText($report_id); ?>" class="btn btn-purple">
            Export Word
        </a>

        <a href="course_reports.php" class="btn btn-secondary">
            Course Reports
        </a>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error"><?php echo safeText($error); ?></div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="alert-success"><?php echo safeText($success); ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Course Report</h2>

        <table>
            <tr>
                <th>Report ID</th>
                <td><?php echo safeText($report_id); ?></td>
                <th>Course</th>
                <td>
                    <strong><?php echo safeText($report["course_code"] ?? ""); ?></strong>
                    -
                    <?php echo safeText($report["course_title"] ?? ""); ?>
                </td>
            </tr>
            <tr>
                <th>Academic Year</th>
                <td><?php echo safeText($report["academic_year"] ?? ""); ?></td>
                <th>Semester</th>
                <td><?php echo safeText($report["semester"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Course Coordinator</th>
                <td><?php echo safeText($report["course_coordinator"] ?? ""); ?></td>
                <th>Linked Specification</th>
                <td>
                    <?php if (!empty($report["spec_id"])): ?>
                        <span class="badge-ok">Linked</span>
                        <?php echo safeText($report["spec_course_code"] ?? ""); ?>
                        -
                        <?php echo safeText($report["spec_course_title"] ?? ""); ?>
                    <?php else: ?>
                        <span class="badge-danger">Not Linked</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalStudents); ?></div>
            <div class="summary-label">Imported Students</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($passed); ?></div>
            <div class="summary-label">Passed</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($failed); ?></div>
            <div class="summary-label">Failed</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($passRate); ?>%</div>
            <div class="summary-label">Pass Rate</div>
        </div>
    </div>

    <div class="panel">
        <h2>CSV Template</h2>

        <p>Save your Excel file as CSV with this exact header:</p>

        <div class="template-box">course_code,course_coordinator,student_id,student_name,coursework,quiz_exam,midterm,final_exam,total_mark
HP1001,Dr. Ahmed,2024001,Student One,20,10,25,40,95
HP1001,Dr. Ahmed,2024002,Student Two,18,9,22,36,85
HP1001,Dr. Ahmed,2024003,Student Three,15,7,18,30,70</div>

        <p class="small-text">
            The course_code in the CSV must match this course report: <strong><?php echo safeText($reportCourseCode); ?></strong>.
        </p>
    </div>

    <div class="panel">
        <h2>Upload CSV Results</h2>

        <form method="POST" enctype="multipart/form-data" action="import_course_report_student_results.php?id=<?php echo safeText($report_id); ?>">
            <input type="hidden" name="action" value="import_csv">

            <label>CSV File</label>
            <input type="file" name="results_file" accept=".csv" required>

            <p>
                <label>
                    <input type="checkbox" name="replace_existing" value="1" checked>
                    Replace existing student results and grade distribution for this report
                </label>
            </p>

            <button type="submit" class="btn btn-purple">
                Import Student Results
            </button>
        </form>

        <br>

        <form method="POST" action="import_course_report_student_results.php?id=<?php echo safeText($report_id); ?>" style="display:inline;">
            <input type="hidden" name="action" value="refresh_grade_distribution">
            <button type="submit" class="btn">
                Refresh Grade Distribution
            </button>
        </form>

        <form method="POST" action="import_course_report_student_results.php?id=<?php echo safeText($report_id); ?>" style="display:inline;" onsubmit="return confirm('Delete all imported student results and grade distribution for this report?');">
            <input type="hidden" name="action" value="delete_student_results">
            <button type="submit" class="btn btn-delete">
                Delete Imported Results
            </button>
        </form>
    </div>

    <div class="panel">
        <h2>Grade Distribution</h2>

        <table>
            <tr>
                <th>Grade</th>
                <th>Student Count</th>
                <th>Percentage</th>
            </tr>

            <?php foreach ($gradeSummary as $grade => $row): ?>
                <tr>
                    <td><strong><?php echo safeText($grade); ?></strong></td>
                    <td><?php echo safeText($row["student_count"]); ?></td>
                    <td><?php echo safeText($row["percentage"]); ?>%</td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php if (count($importResults) > 0): ?>
        <div class="panel">
            <h2>Import Results</h2>

            <div class="wide-table-wrapper">
                <table class="wide-table">
                    <tr>
                        <th>Line</th>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>

                    <?php foreach ($importResults as $row): ?>
                        <tr>
                            <td><?php echo safeText($row["line"]); ?></td>
                            <td><?php echo safeText($row["student_id"]); ?></td>
                            <td><?php echo safeText($row["student_name"]); ?></td>
                            <td><?php echo safeText($row["status"]); ?></td>
                            <td><?php echo safeText($row["message"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Imported Student Results</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>No.</th>
                    <th>Course Code</th>
                    <th>Coordinator</th>
                    <th>Student ID</th>
                    <th>Student Name</th>
                    <th>Coursework</th>
                    <th>Quiz Exam</th>
                    <th>Midterm</th>
                    <th>Final Exam</th>
                    <th>Total Mark</th>
                    <th>Grade</th>
                    <th>Status</th>
                </tr>

                <?php if (count($studentRows) > 0): ?>
                    <?php $i = 1; ?>
                    <?php foreach ($studentRows as $student): ?>
                        <tr>
                            <td><?php echo safeText($i); ?></td>
                            <td><?php echo safeText($student["course_code"] ?? ""); ?></td>
                            <td><?php echo safeText($student["course_coordinator"] ?? ""); ?></td>
                            <td><?php echo safeText($student["student_id"] ?? ""); ?></td>
                            <td><?php echo safeText($student["student_name"] ?? ""); ?></td>
                            <td><?php echo safeText($student["coursework"] ?? ""); ?></td>
                            <td><?php echo safeText($student["quiz_exam"] ?? ""); ?></td>
                            <td><?php echo safeText($student["midterm"] ?? ""); ?></td>
                            <td><?php echo safeText($student["final_exam"] ?? ""); ?></td>
                            <td><?php echo safeText($student["total_mark"] ?? ""); ?></td>
                            <td><strong><?php echo safeText($student["grade_label"] ?? ""); ?></strong></td>
                            <td><?php echo safeText($student["result_status"] ?? ""); ?></td>
                        </tr>
                        <?php $i++; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="12">No student results imported yet.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
