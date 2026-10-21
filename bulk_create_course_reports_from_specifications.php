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
$createdRows = [];

function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function nullIfEmpty($value) {
    $value = trim((string)$value);
    return $value === "" ? null : $value;
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

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

function reportExists($conn, $courseCode, $academicYear, $semester) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM course_reports
        WHERE course_code = :course_code
          AND academic_year = :academic_year
          AND semester = :semester
    ");
    $stmt->execute([
        ":course_code" => $courseCode,
        ":academic_year" => $academicYear,
        ":semester" => $semester
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function getExistingReportId($conn, $courseCode, $academicYear, $semester) {
    $stmt = $conn->prepare("
        SELECT id
        FROM course_reports
        WHERE course_code = :course_code
          AND academic_year = :academic_year
          AND semester = :semester
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ":course_code" => $courseCode,
        ":academic_year" => $academicYear,
        ":semester" => $semester
    ]);

    $id = $stmt->fetchColumn();

    return $id ? (int)$id : 0;
}

if (!tableExists($conn, "course_specifications")) {
    die("course_specifications table does not exist.");
}

if (!tableExists($conn, "course_reports")) {
    die("course_reports table does not exist.");
}

if (!columnExists($conn, "course_reports", "course_specification_id")) {
    die("course_reports.course_specification_id column does not exist.");
}

/*
    Load program specification.
*/
$programSpec = null;

if (tableExists($conn, "program_specifications")) {
    $programStmt = $conn->prepare("
        SELECT *
        FROM program_specifications
        WHERE id = :id
        LIMIT 1
    ");
    $programStmt->execute([":id" => $program_specification_id]);
    $programSpec = $programStmt->fetch(PDO::FETCH_ASSOC);
}

$defaultAcademicYear = date("Y") . "-" . (date("Y") + 1);

$academic_year = trim($_POST["academic_year"] ?? ($_GET["academic_year"] ?? $defaultAcademicYear));
$semester = trim($_POST["semester"] ?? ($_GET["semester"] ?? "Semester 1"));
$default_status = trim($_POST["default_status"] ?? "Draft");
$default_report_version = trim($_POST["report_version"] ?? "v1.0");
$report_date = trim($_POST["report_date"] ?? date("Y-m-d"));

/*
    Load course specifications for program.
*/
$specStmt = $conn->prepare("
    SELECT
        id,
        course_code,
        course_title,
        program_name,
        department_name,
        college_name,
        institution_name,
        credit_hours,
        course_level_year,
        status,
        approval_status
    FROM course_specifications
    WHERE program_specification_id = :program_specification_id
    ORDER BY course_code ASC, course_title ASC, id ASC
");
$specStmt->execute([":program_specification_id" => $program_specification_id]);
$courseSpecs = $specStmt->fetchAll(PDO::FETCH_ASSOC);

/*
    Build preview rows.
*/
$previewRows = [];

foreach ($courseSpecs as $spec) {
    $courseCode = trim((string)($spec["course_code"] ?? ""));
    $courseTitle = trim((string)($spec["course_title"] ?? ""));

    if ($courseCode === "") {
        continue;
    }

    $existingReportId = getExistingReportId($conn, $courseCode, $academic_year, $semester);

    $previewRows[] = [
        "spec" => $spec,
        "existing_report_id" => $existingReportId,
        "status" => $existingReportId > 0 ? "Already Exists" : "Ready to Create"
    ];
}

/*
    Create reports.
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($academic_year === "") {
        $error = "Academic year is required.";
    } elseif ($semester === "") {
        $error = "Semester is required.";
    } elseif ($action === "create_selected" || $action === "create_all_missing") {

        $selectedSpecIds = $_POST["spec_ids"] ?? [];

        if ($action === "create_all_missing") {
            $selectedSpecIds = [];
            foreach ($previewRows as $row) {
                if ((int)$row["existing_report_id"] === 0) {
                    $selectedSpecIds[] = (string)$row["spec"]["id"];
                }
            }
        }

        if (!is_array($selectedSpecIds) || count($selectedSpecIds) === 0) {
            $error = "No course specifications selected, or all reports already exist.";
        } else {
            $selectedMap = array_flip(array_map("strval", $selectedSpecIds));

            try {
                $conn->beginTransaction();

                $insertStmt = $conn->prepare("
                    INSERT INTO course_reports
                    (
                        course_specification_id,
                        course_code,
                        course_title,
                        academic_year,
                        semester,
                        course_coordinator,
                        students_enrolled,
                        students_passed,
                        students_failed,
                        clo_achievement_summary,
                        assessment_summary,
                        teaching_summary,
                        student_feedback_summary,
                        course_improvement_actions,
                        general_notes,
                        status,
                        department_name,
                        program_name,
                        college_name,
                        institution_name,
                        course_instructors,
                        location,
                        number_of_sections,
                        students_starting,
                        students_completed,
                        report_date,
                        report_version
                    )
                    VALUES
                    (
                        :course_specification_id,
                        :course_code,
                        :course_title,
                        :academic_year,
                        :semester,
                        :course_coordinator,
                        :students_enrolled,
                        :students_passed,
                        :students_failed,
                        :clo_achievement_summary,
                        :assessment_summary,
                        :teaching_summary,
                        :student_feedback_summary,
                        :course_improvement_actions,
                        :general_notes,
                        :status,
                        :department_name,
                        :program_name,
                        :college_name,
                        :institution_name,
                        :course_instructors,
                        :location,
                        :number_of_sections,
                        :students_starting,
                        :students_completed,
                        :report_date,
                        :report_version
                    )
                ");

                foreach ($courseSpecs as $spec) {
                    $specId = (int)$spec["id"];

                    if (!isset($selectedMap[(string)$specId])) {
                        continue;
                    }

                    $courseCode = trim((string)($spec["course_code"] ?? ""));
                    $courseTitle = trim((string)($spec["course_title"] ?? ""));

                    if ($courseCode === "" || $courseTitle === "") {
                        $createdRows[] = [
                            "course_code" => $courseCode,
                            "course_title" => $courseTitle,
                            "status" => "Skipped",
                            "message" => "Missing course code or title."
                        ];
                        continue;
                    }

                    if (reportExists($conn, $courseCode, $academic_year, $semester)) {
                        $createdRows[] = [
                            "course_code" => $courseCode,
                            "course_title" => $courseTitle,
                            "status" => "Skipped",
                            "message" => "Course report already exists for this academic year and semester."
                        ];
                        continue;
                    }

                    $insertStmt->execute([
                        ":course_specification_id" => $specId,
                        ":course_code" => $courseCode,
                        ":course_title" => $courseTitle,
                        ":academic_year" => $academic_year,
                        ":semester" => $semester,
                        ":course_coordinator" => null,
                        ":students_enrolled" => 0,
                        ":students_passed" => 0,
                        ":students_failed" => 0,
                        ":clo_achievement_summary" => null,
                        ":assessment_summary" => null,
                        ":teaching_summary" => null,
                        ":student_feedback_summary" => null,
                        ":course_improvement_actions" => null,
                        ":general_notes" => null,
                        ":status" => $default_status,
                        ":department_name" => nullIfEmpty($spec["department_name"] ?? ""),
                        ":program_name" => nullIfEmpty($spec["program_name"] ?? ""),
                        ":college_name" => nullIfEmpty($spec["college_name"] ?? ""),
                        ":institution_name" => nullIfEmpty($spec["institution_name"] ?? ""),
                        ":course_instructors" => null,
                        ":location" => null,
                        ":number_of_sections" => null,
                        ":students_starting" => null,
                        ":students_completed" => null,
                        ":report_date" => nullIfEmpty($report_date),
                        ":report_version" => nullIfEmpty($default_report_version)
                    ]);

                    $newReportId = (int)$conn->lastInsertId();

                    logActivitySafe(
                        $conn,
                        "Create",
                        "Bulk Course Reports",
                        $newReportId,
                        "Bulk created course report for " . $courseCode . " from course specification ID " . $specId
                    );

                    $createdRows[] = [
                        "course_code" => $courseCode,
                        "course_title" => $courseTitle,
                        "status" => "Created",
                        "message" => "Course report ID " . $newReportId . " created and linked to specification ID " . $specId . "."
                    ];
                }

                $conn->commit();

                $success = "Bulk course report creation completed.";

                /*
                    Reload preview after creation.
                */
                $previewRows = [];

                foreach ($courseSpecs as $spec) {
                    $courseCode = trim((string)($spec["course_code"] ?? ""));

                    if ($courseCode === "") {
                        continue;
                    }

                    $existingReportId = getExistingReportId($conn, $courseCode, $academic_year, $semester);

                    $previewRows[] = [
                        "spec" => $spec,
                        "existing_report_id" => $existingReportId,
                        "status" => $existingReportId > 0 ? "Already Exists" : "Ready to Create"
                    ];
                }

            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }

                $error = "Bulk creation failed: " . $e->getMessage();
            }
        }
    }
}

/*
    Summary.
*/
$totalSpecs = count($courseSpecs);
$alreadyExistsCount = 0;
$readyToCreateCount = 0;

foreach ($previewRows as $row) {
    if ((int)$row["existing_report_id"] > 0) {
        $alreadyExistsCount++;
    } else {
        $readyToCreateCount++;
    }
}

$createdCount = 0;
$skippedCount = 0;

foreach ($createdRows as $row) {
    if ($row["status"] === "Created") {
        $createdCount++;
    } else {
        $skippedCount++;
    }
}

$semesterOptions = [
    "Semester 1",
    "Semester 2",
    "Semester 3",
    "Term 1",
    "Term 2",
    "Term 3",
    "First Semester",
    "Second Semester",
    "Third Semester",
    "Annual",
    "Summer"
];

$statusOptions = [
    "Draft",
    "Submitted",
    "Under Review",
    "Reviewed",
    "Approved"
];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Bulk Create Course Reports</title>
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
            grid-template-columns: repeat(5, minmax(130px, 1fr));
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 14px;
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

        .small-text {
            font-size: 12px;
            color: #555;
        }

        @media (max-width: 1000px) {
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
        <h1>Bulk Create Course Reports from Specifications</h1>
        <p>Create draft course reports from imported course specifications and link them automatically.</p>
    </div>

    <div class="actions">
        <a href="course_specification_workflow.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Course Spec Workflow
        </a>

        <a href="course_reports.php" class="btn">
            Course Reports
        </a>

        <a href="link_course_reports_to_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Link Reports to Specs
        </a>

        <a href="program_specification_dashboard.php?id=<?php echo safeText($program_specification_id); ?>" class="btn btn-secondary">
            Program Spec Dashboard
        </a>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error"><?php echo safeText($error); ?></div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="alert-success"><?php echo safeText($success); ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Program</h2>

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
            <div class="summary-number"><?php echo safeText($totalSpecs); ?></div>
            <div class="summary-label">Course Specifications</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($alreadyExistsCount); ?></div>
            <div class="summary-label">Reports Already Exist</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($readyToCreateCount); ?></div>
            <div class="summary-label">Ready to Create</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($createdCount); ?></div>
            <div class="summary-label">Created This Run</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($skippedCount); ?></div>
            <div class="summary-label">Skipped This Run</div>
        </div>
    </div>

    <form method="GET" action="bulk_create_course_reports_from_specifications.php">
        <input type="hidden" name="program_specification_id" value="<?php echo safeText($program_specification_id); ?>">

        <div class="panel">
            <h2>Report Settings</h2>

            <div class="form-grid">
                <div>
                    <label>Academic Year</label>
                    <input type="text" name="academic_year" value="<?php echo safeText($academic_year); ?>" placeholder="2025-2026">
                </div>

                <div>
                    <label>Semester</label>
                    <select name="semester">
                        <?php foreach ($semesterOptions as $sem): ?>
                            <option value="<?php echo safeText($sem); ?>" <?php if ($semester === $sem) echo "selected"; ?>>
                                <?php echo safeText($sem); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="align-self:end;">
                    <button type="submit" class="btn">
                        Refresh Preview
                    </button>
                </div>
            </div>
        </div>
    </form>

    <form method="POST" action="bulk_create_course_reports_from_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>">
        <input type="hidden" name="academic_year" value="<?php echo safeText($academic_year); ?>">
        <input type="hidden" name="semester" value="<?php echo safeText($semester); ?>">

        <div class="panel">
            <h2>Creation Options</h2>

            <div class="form-grid">
                <div>
                    <label>Default Report Status</label>
                    <select name="default_status">
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?php echo safeText($status); ?>" <?php if ($default_status === $status) echo "selected"; ?>>
                                <?php echo safeText($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Report Version</label>
                    <input type="text" name="report_version" value="<?php echo safeText($default_report_version); ?>">
                </div>

                <div>
                    <label>Report Date</label>
                    <input type="date" name="report_date" value="<?php echo safeText($report_date); ?>">
                </div>
            </div>

            <br>

            <div class="actions">
                <button type="submit"
                        name="action"
                        value="create_all_missing"
                        class="btn btn-purple"
                        onclick="return confirm('Create reports for all missing course specifications for this academic year and semester?');">
                    Create All Missing Reports
                </button>

                <button type="submit"
                        name="action"
                        value="create_selected"
                        class="btn"
                        onclick="return confirm('Create reports for selected course specifications?');">
                    Create Selected Reports
                </button>
            </div>
        </div>

        <?php if (count($createdRows) > 0): ?>
            <div class="panel">
                <h2>Creation Results</h2>

                <table>
                    <tr>
                        <th>Course Code</th>
                        <th>Course Title</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>

                    <?php foreach ($createdRows as $row): ?>
                        <tr>
                            <td><?php echo safeText($row["course_code"]); ?></td>
                            <td><?php echo safeText($row["course_title"]); ?></td>
                            <td><?php echo safeText($row["status"]); ?></td>
                            <td><?php echo safeText($row["message"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>

        <div class="panel">
            <h2>Course Specifications Preview</h2>

            <div class="wide-table-wrapper">
                <table class="wide-table">
                    <tr>
                        <th>Select</th>
                        <th>Spec ID</th>
                        <th>Course Code</th>
                        <th>Course Title</th>
                        <th>Program</th>
                        <th>Department</th>
                        <th>Status</th>
                        <th>Approval</th>
                        <th>Report Status</th>
                        <th>Existing Report</th>
                    </tr>

                    <?php if (count($previewRows) > 0): ?>
                        <?php foreach ($previewRows as $row): ?>
                            <?php
                            $spec = $row["spec"];
                            $existingReportId = (int)$row["existing_report_id"];
                            ?>
                            <tr>
                                <td>
                                    <?php if ($existingReportId === 0): ?>
                                        <input type="checkbox" name="spec_ids[]" value="<?php echo safeText($spec["id"]); ?>" checked>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>

                                <td><?php echo safeText($spec["id"]); ?></td>
                                <td><strong><?php echo safeText($spec["course_code"] ?? ""); ?></strong></td>
                                <td><?php echo safeText($spec["course_title"] ?? ""); ?></td>
                                <td><?php echo safeText($spec["program_name"] ?? ""); ?></td>
                                <td><?php echo safeText($spec["department_name"] ?? ""); ?></td>
                                <td><?php echo safeText($spec["status"] ?? ""); ?></td>
                                <td><?php echo safeText($spec["approval_status"] ?? ""); ?></td>

                                <td>
                                    <?php if ($existingReportId > 0): ?>
                                        <span class="badge-ok">Already Exists</span>
                                    <?php else: ?>
                                        <span class="badge-warning">Ready to Create</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($existingReportId > 0): ?>
                                        <a href="view_course_report.php?id=<?php echo safeText($existingReportId); ?>" class="btn">
                                            View Report #<?php echo safeText($existingReportId); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="small-text">No report yet for <?php echo safeText($academic_year); ?> / <?php echo safeText($semester); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10">No course specifications found under this program specification.</td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

    </form>

</div>

</body>
</html>