<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

$error = "";

// ===============================
// Load system settings for default values
// ===============================
$settingsStmt = $conn->query("
    SELECT *
    FROM system_settings
    ORDER BY id ASC
    LIMIT 1
");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

$default_academic_year = $settings["default_academic_year"] ?? "";
$default_semester = $settings["default_semester"] ?? "";
$default_prepared_by = $settings["quality_coordinator"] ?? "";

$default_report_title = trim("Semester Program Report - " . $default_semester . " " . $default_academic_year);

// ===============================
// Handle form submission
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $academic_year = trim($_POST["academic_year"] ?? "");
    $semester = trim($_POST["semester"] ?? "");
    $report_title = trim($_POST["report_title"] ?? "");
    $prepared_by = trim($_POST["prepared_by"] ?? "");
    $status = trim($_POST["status"] ?? "Draft");

    $program_strengths = trim($_POST["program_strengths"] ?? "");
    $program_weaknesses = trim($_POST["program_weaknesses"] ?? "");
    $improvement_actions = trim($_POST["improvement_actions"] ?? "");

    $kpi_summary = trim($_POST["kpi_summary"] ?? "");
    $course_report_summary = trim($_POST["course_report_summary"] ?? "");
    $student_feedback_summary = trim($_POST["student_feedback_summary"] ?? "");
    $teaching_learning_summary = trim($_POST["teaching_learning_summary"] ?? "");
    $assessment_summary = trim($_POST["assessment_summary"] ?? "");
    $resources_summary = trim($_POST["resources_summary"] ?? "");

    $general_notes = trim($_POST["general_notes"] ?? "");

    if ($academic_year === "") {
        $error = "Academic year is required.";
    } elseif ($semester === "") {
        $error = "Semester is required.";
    } elseif ($report_title === "") {
        $error = "Report title is required.";
    } else {
        $sql = "
            INSERT INTO semester_reports
            (
                academic_year,
                semester,
                report_title,
                prepared_by,
                status,
                program_strengths,
                program_weaknesses,
                improvement_actions,
                kpi_summary,
                course_report_summary,
                student_feedback_summary,
                teaching_learning_summary,
                assessment_summary,
                resources_summary,
                general_notes
            )
            VALUES
            (
                :academic_year,
                :semester,
                :report_title,
                :prepared_by,
                :status,
                :program_strengths,
                :program_weaknesses,
                :improvement_actions,
                :kpi_summary,
                :course_report_summary,
                :student_feedback_summary,
                :teaching_learning_summary,
                :assessment_summary,
                :resources_summary,
                :general_notes
            )
        ";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ":academic_year" => $academic_year,
            ":semester" => $semester,
            ":report_title" => $report_title,
            ":prepared_by" => $prepared_by,
            ":status" => $status,
            ":program_strengths" => $program_strengths,
            ":program_weaknesses" => $program_weaknesses,
            ":improvement_actions" => $improvement_actions,
            ":kpi_summary" => $kpi_summary,
            ":course_report_summary" => $course_report_summary,
            ":student_feedback_summary" => $student_feedback_summary,
            ":teaching_learning_summary" => $teaching_learning_summary,
            ":assessment_summary" => $assessment_summary,
            ":resources_summary" => $resources_summary,
            ":general_notes" => $general_notes
        ]);

        header("Location: semester_reports.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Add Semester Program Report</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .semester-report-form {
            max-width: 1000px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(220px, 1fr));
            gap: 14px;
        }

        .form-section {
            margin-top: 22px;
            padding-top: 14px;
            border-top: 1px solid #ddd;
        }

        .form-section:first-child {
            margin-top: 0;
            padding-top: 0;
            border-top: none;
        }

        textarea {
            min-height: 110px;
        }

        .large-textarea {
            min-height: 150px;
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

        .button-row {
            margin-top: 18px;
        }

        @media (max-width: 800px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .semester-report-form {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Add Semester Program Report</h1>
        <p>Create a semester-level program report covering strengths, weaknesses, KPIs, course reports, feedback, assessment, resources, and improvement actions.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="create_semester_report.php" class="semester-report-form">

            <div class="form-section">
                <h2>Report Identity</h2>

                <div class="form-grid">
                    <div>
                        <label>Academic Year</label>
                        <input type="text" name="academic_year" required
                               value="<?php echo htmlspecialchars($default_academic_year); ?>"
                               placeholder="Example: 2025-2026">
                    </div>

                    <div>
                        <label>Semester</label>
                        <select name="semester" required>
                            <option value="">-- Select Semester --</option>

                            <?php
                            $semesters = ["First Semester", "Second Semester", "Third Semester", "Annual"];
                            foreach ($semesters as $sem):
                            ?>
                                <option value="<?php echo htmlspecialchars($sem); ?>"
                                    <?php if ($default_semester === $sem) echo "selected"; ?>>
                                    <?php echo htmlspecialchars($sem); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Report Title</label>
                        <input type="text" name="report_title" required
                               value="<?php echo htmlspecialchars($default_report_title); ?>"
                               placeholder="Example: Semester Program Report 2025-2026">
                    </div>

                    <div>
                        <label>Prepared By</label>
                        <input type="text" name="prepared_by"
                               value="<?php echo htmlspecialchars($default_prepared_by); ?>"
                               placeholder="Example: Quality Coordinator">
                    </div>

                    <div>
                        <label>Status</label>
                        <select name="status">
                            <option value="Draft">Draft</option>
                            <option value="Under Review">Under Review</option>
                            <option value="Approved">Approved</option>
                            <option value="Archived">Archived</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Program Evaluation Summary</h2>

                <label>Program Strengths</label>
                <textarea name="program_strengths" class="large-textarea" placeholder="Summarize major strengths identified during the semester."></textarea>

                <label>Program Weaknesses / Areas for Improvement</label>
                <textarea name="program_weaknesses" class="large-textarea" placeholder="Summarize weaknesses, gaps, or risks."></textarea>

                <label>Improvement Actions</label>
                <textarea name="improvement_actions" class="large-textarea" placeholder="List improvement actions, responsible persons, and expected timelines."></textarea>
            </div>

            <div class="form-section">
                <h2>Quality Indicators and Evidence</h2>

                <label>KPI Summary</label>
                <textarea name="kpi_summary" placeholder="Summarize KPI results and trends."></textarea>

                <label>Course Report Summary</label>
                <textarea name="course_report_summary" placeholder="Summarize key findings from course reports."></textarea>

                <label>Student Feedback Summary</label>
                <textarea name="student_feedback_summary" placeholder="Summarize student survey or feedback findings."></textarea>

                <label>Teaching and Learning Summary</label>
                <textarea name="teaching_learning_summary" placeholder="Summarize teaching strategies, delivery issues, and improvements."></textarea>

                <label>Assessment Summary</label>
                <textarea name="assessment_summary" placeholder="Summarize assessment quality, CLO/PLO achievement, and moderation findings."></textarea>

                <label>Resources Summary</label>
                <textarea name="resources_summary" placeholder="Summarize classrooms, labs, library, LMS, and learning resources issues."></textarea>
            </div>

            <div class="form-section">
                <h2>General Notes</h2>

                <label>General Notes</label>
                <textarea name="general_notes" placeholder="Any additional notes."></textarea>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Save Semester Report</button>
                <a href="semester_reports.php" class="btn btn-secondary">Back to Reports</a>
            </div>

        </form>

    </div>

</div>

</body>
</html>