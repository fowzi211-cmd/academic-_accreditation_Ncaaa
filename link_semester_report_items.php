<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid semester report ID.");
}

$report_id = (int)$_GET["id"];
$message = "";

// ===============================
// Load semester report
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM semester_reports
    WHERE id = :id
");
$stmt->execute([":id" => $report_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die("Semester report not found.");
}

// ===============================
// Link evidence
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["link_evidence"])) {
    $evidence_id = !empty($_POST["evidence_id"]) ? (int)$_POST["evidence_id"] : 0;
    $notes = trim($_POST["evidence_notes"] ?? "");

    if ($evidence_id > 0) {
        try {
            $insertStmt = $conn->prepare("
                INSERT INTO semester_report_evidence
                (
                    report_id,
                    evidence_id,
                    notes
                )
                VALUES
                (
                    :report_id,
                    :evidence_id,
                    :notes
                )
            ");

            $insertStmt->execute([
                ":report_id" => $report_id,
                ":evidence_id" => $evidence_id,
                ":notes" => $notes
            ]);

            $message = "Evidence linked successfully.";
        } catch (PDOException $e) {
            $message = "This evidence is already linked to the report.";
        }
    } else {
        $message = "Please select evidence.";
    }
}

// ===============================
// Link task
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["link_task"])) {
    $task_id = !empty($_POST["task_id"]) ? (int)$_POST["task_id"] : 0;
    $notes = trim($_POST["task_notes"] ?? "");

    if ($task_id > 0) {
        try {
            $insertStmt = $conn->prepare("
                INSERT INTO semester_report_tasks
                (
                    report_id,
                    task_id,
                    notes
                )
                VALUES
                (
                    :report_id,
                    :task_id,
                    :notes
                )
            ");

            $insertStmt->execute([
                ":report_id" => $report_id,
                ":task_id" => $task_id,
                ":notes" => $notes
            ]);

            $message = "Task linked successfully.";
        } catch (PDOException $e) {
            $message = "This task is already linked to the report.";
        }
    } else {
        $message = "Please select task.";
    }
}

// ===============================
// Link course report
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["link_course_report"])) {
    $course_report_id = !empty($_POST["course_report_id"]) ? (int)$_POST["course_report_id"] : 0;
    $notes = trim($_POST["course_report_notes"] ?? "");

    if ($course_report_id > 0) {
        try {
            $insertStmt = $conn->prepare("
                INSERT INTO semester_report_course_reports
                (
                    report_id,
                    course_report_id,
                    notes
                )
                VALUES
                (
                    :report_id,
                    :course_report_id,
                    :notes
                )
            ");

            $insertStmt->execute([
                ":report_id" => $report_id,
                ":course_report_id" => $course_report_id,
                ":notes" => $notes
            ]);

            $message = "Course report linked successfully.";
        } catch (PDOException $e) {
            $message = "This course report is already linked to the semester report.";
        }
    } else {
        $message = "Please select a course report.";
    }
}

// ===============================
// Link KPI result
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["link_kpi_result"])) {
    $kpi_result_id = !empty($_POST["kpi_result_id"]) ? (int)$_POST["kpi_result_id"] : 0;
    $notes = trim($_POST["kpi_result_notes"] ?? "");

    if ($kpi_result_id > 0) {
        try {
            $insertStmt = $conn->prepare("
                INSERT INTO semester_report_kpi_results
                (
                    report_id,
                    kpi_result_id,
                    notes
                )
                VALUES
                (
                    :report_id,
                    :kpi_result_id,
                    :notes
                )
            ");

            $insertStmt->execute([
                ":report_id" => $report_id,
                ":kpi_result_id" => $kpi_result_id,
                ":notes" => $notes
            ]);

            $message = "KPI result linked successfully.";
        } catch (PDOException $e) {
            $message = "This KPI result is already linked to the semester report.";
        }
    } else {
        $message = "Please select a KPI result.";
    }
}

// ===============================
// Load all evidence
// ===============================
$evidenceStmt = $conn->query("
    SELECT 
        e.id,
        e.title,
        e.status,
        e.academic_year,
        e.semester,
        ss.substandard_code,
        s.standard_code
    FROM evidence_items e
    LEFT JOIN substandards ss ON e.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
    ORDER BY e.id DESC
");
$evidenceItems = $evidenceStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Load all tasks
// ===============================
$taskStmt = $conn->query("
    SELECT 
        t.id,
        t.task_title,
        t.status,
        t.priority,
        t.due_date,
        ss.substandard_code,
        s.standard_code
    FROM tasks t
    LEFT JOIN substandards ss ON t.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
    ORDER BY t.id DESC
");
$tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Load all course reports
// ===============================
$courseReportStmt = $conn->query("
    SELECT 
        id,
        course_code,
        course_title,
        academic_year,
        semester,
        course_coordinator,
        status
    FROM course_reports
    ORDER BY id DESC
");
$courseReports = $courseReportStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Load all KPI results
// ===============================
$kpiResultStmt = $conn->query("
    SELECT
        kr.id,
        kr.academic_year,
        kr.semester,
        kr.target_value,
        kr.actual_value,
        kr.achievement_percentage,
        kr.status,
        k.kpi_code,
        k.kpi_name,
        k.unit
    FROM kpi_results kr
    INNER JOIN kpis k ON kr.kpi_id = k.id
    ORDER BY kr.id DESC
");
$kpiResults = $kpiResultStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Load linked evidence
// ===============================
$linkedEvidenceStmt = $conn->prepare("
    SELECT
        sre.id AS link_id,
        sre.notes AS link_notes,
        e.id AS evidence_id,
        e.title,
        e.evidence_type,
        e.status,
        e.academic_year,
        e.semester,
        ss.substandard_code,
        s.standard_code
    FROM semester_report_evidence sre
    INNER JOIN evidence_items e ON sre.evidence_id = e.id
    LEFT JOIN substandards ss ON e.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
    WHERE sre.report_id = :report_id
    ORDER BY e.id DESC
");
$linkedEvidenceStmt->execute([":report_id" => $report_id]);
$linkedEvidence = $linkedEvidenceStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Load linked tasks
// ===============================
$linkedTaskStmt = $conn->prepare("
    SELECT
        srt.id AS link_id,
        srt.notes AS link_notes,
        t.id AS task_id,
        t.task_title,
        t.status,
        t.priority,
        t.due_date,
        ss.substandard_code,
        s.standard_code
    FROM semester_report_tasks srt
    INNER JOIN tasks t ON srt.task_id = t.id
    LEFT JOIN substandards ss ON t.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
    WHERE srt.report_id = :report_id
    ORDER BY t.id DESC
");
$linkedTaskStmt->execute([":report_id" => $report_id]);
$linkedTasks = $linkedTaskStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Load linked course reports
// ===============================
$linkedCourseReportStmt = $conn->prepare("
    SELECT
        srcr.id AS link_id,
        srcr.notes AS link_notes,
        cr.id AS course_report_id,
        cr.course_code,
        cr.course_title,
        cr.academic_year,
        cr.semester,
        cr.course_coordinator,
        cr.students_enrolled,
        cr.students_passed,
        cr.students_failed,
        cr.status
    FROM semester_report_course_reports srcr
    INNER JOIN course_reports cr ON srcr.course_report_id = cr.id
    WHERE srcr.report_id = :report_id
    ORDER BY cr.id DESC
");
$linkedCourseReportStmt->execute([":report_id" => $report_id]);
$linkedCourseReports = $linkedCourseReportStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Load linked KPI results
// ===============================
$linkedKpiResultStmt = $conn->prepare("
    SELECT
        srkr.id AS link_id,
        srkr.notes AS link_notes,
        kr.id AS kpi_result_id,
        kr.academic_year,
        kr.semester,
        kr.target_value,
        kr.actual_value,
        kr.achievement_percentage,
        kr.status,
        kr.analysis,
        kr.improvement_action,
        k.kpi_code,
        k.kpi_name,
        k.unit
    FROM semester_report_kpi_results srkr
    INNER JOIN kpi_results kr ON srkr.kpi_result_id = kr.id
    INNER JOIN kpis k ON kr.kpi_id = k.id
    WHERE srkr.report_id = :report_id
    ORDER BY kr.id DESC
");
$linkedKpiResultStmt->execute([":report_id" => $report_id]);
$linkedKpiResults = $linkedKpiResultStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Helper
// ===============================
function calcRate($part, $total) {
    $part = (int)$part;
    $total = (int)$total;

    if ($total <= 0) {
        return 0;
    }

    return round(($part / $total) * 100, 1);
}

function achievementClass($achievement) {
    $achievement = (float)$achievement;

    if ($achievement >= 100) {
        return "good";
    }

    if ($achievement >= 80) {
        return "warning";
    }

    return "bad";
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Link Report Items</title>
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

        .report-summary {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .link-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(280px, 1fr));
            gap: 18px;
        }

        textarea {
            min-height: 80px;
        }

        .message {
            background: #d1e7dd;
            border: 1px solid #badbcc;
            color: #0f5132;
            padding: 12px;
            border-radius: 8px;
            font-weight: bold;
            margin-bottom: 16px;
        }

        .missing {
            color: #999;
            font-style: italic;
        }

        .good {
            color: #28a745;
            font-weight: bold;
        }

        .warning {
            color: #fd7e14;
            font-weight: bold;
        }

        .bad {
            color: #dc3545;
            font-weight: bold;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .linked-section {
            margin-top: 22px;
        }

        @media (max-width: 900px) {
            .link-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Link Evidence, Tasks, Course Reports, and KPI Results</h1>
        <p>Attach supporting evidence and improvement records to the selected semester program report.</p>
    </div>

    <div class="report-summary">
        <strong>Semester Report:</strong>
        <?php echo htmlspecialchars($report["report_title"]); ?>
        <br>
        <strong>Academic Year:</strong>
        <?php echo htmlspecialchars($report["academic_year"]); ?>
        |
        <strong>Semester:</strong>
        <?php echo htmlspecialchars($report["semester"]); ?>
    </div>

    <?php if ($message !== ""): ?>
        <div class="message">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="link-grid">

        <div class="panel">
            <h2>Link Evidence</h2>

            <form method="POST" action="link_semester_report_items.php?id=<?php echo htmlspecialchars($report_id); ?>">
                <label>Select Evidence</label>
                <select name="evidence_id">
                    <option value="">-- Select Evidence --</option>

                    <?php foreach ($evidenceItems as $evidence): ?>
                        <option value="<?php echo htmlspecialchars($evidence["id"]); ?>">
                            <?php
                                echo htmlspecialchars(
                                    $evidence["id"] . " - " .
                                    $evidence["title"] . " | " .
                                    ($evidence["standard_code"] ?? "No Standard") . " " .
                                    ($evidence["substandard_code"] ?? "")
                                );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Link Notes</label>
                <textarea name="evidence_notes" placeholder="Explain why this evidence supports the report."></textarea>

                <button type="submit" name="link_evidence" class="btn">Link Evidence</button>
            </form>
        </div>

        <div class="panel">
            <h2>Link Task</h2>

            <form method="POST" action="link_semester_report_items.php?id=<?php echo htmlspecialchars($report_id); ?>">
                <label>Select Task</label>
                <select name="task_id">
                    <option value="">-- Select Task --</option>

                    <?php foreach ($tasks as $task): ?>
                        <option value="<?php echo htmlspecialchars($task["id"]); ?>">
                            <?php
                                echo htmlspecialchars(
                                    $task["id"] . " - " .
                                    $task["task_title"] . " | " .
                                    $task["status"] . " | " .
                                    ($task["standard_code"] ?? "No Standard") . " " .
                                    ($task["substandard_code"] ?? "")
                                );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Link Notes</label>
                <textarea name="task_notes" placeholder="Explain how this task supports report improvement actions."></textarea>

                <button type="submit" name="link_task" class="btn">Link Task</button>
            </form>
        </div>

        <div class="panel">
            <h2>Link Course Report</h2>

            <form method="POST" action="link_semester_report_items.php?id=<?php echo htmlspecialchars($report_id); ?>">
                <label>Select Course Report</label>
                <select name="course_report_id">
                    <option value="">-- Select Course Report --</option>

                    <?php foreach ($courseReports as $cr): ?>
                        <option value="<?php echo htmlspecialchars($cr["id"]); ?>">
                            <?php
                                echo htmlspecialchars(
                                    $cr["id"] . " - " .
                                    $cr["course_code"] . " - " .
                                    $cr["course_title"] . " | " .
                                    $cr["academic_year"] . " | " .
                                    $cr["semester"] . " | " .
                                    $cr["status"]
                                );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Link Notes</label>
                <textarea name="course_report_notes" placeholder="Explain how this course report supports the semester report."></textarea>

                <button type="submit" name="link_course_report" class="btn">Link Course Report</button>
            </form>
        </div>

        <div class="panel">
            <h2>Link KPI Result</h2>

            <form method="POST" action="link_semester_report_items.php?id=<?php echo htmlspecialchars($report_id); ?>">
                <label>Select KPI Result</label>
                <select name="kpi_result_id">
                    <option value="">-- Select KPI Result --</option>

                    <?php foreach ($kpiResults as $kr): ?>
                        <option value="<?php echo htmlspecialchars($kr["id"]); ?>">
                            <?php
                                echo htmlspecialchars(
                                    $kr["id"] . " - " .
                                    $kr["kpi_code"] . " - " .
                                    $kr["kpi_name"] . " | " .
                                    $kr["academic_year"] . " | " .
                                    $kr["semester"] . " | Achievement: " .
                                    $kr["achievement_percentage"] . "%"
                                );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Link Notes</label>
                <textarea name="kpi_result_notes" placeholder="Explain how this KPI result supports the semester report."></textarea>

                <button type="submit" name="link_kpi_result" class="btn">Link KPI Result</button>
            </form>
        </div>

    </div>

    <div class="linked-section panel">
        <h2>Linked KPI Results</h2>

        <?php if (count($linkedKpiResults) > 0): ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>KPI</th>
                    <th>Academic Year</th>
                    <th>Semester</th>
                    <th>Target</th>
                    <th>Actual</th>
                    <th>Achievement %</th>
                    <th>Status</th>
                    <th>Analysis</th>
                    <th>Improvement Action</th>
                    <th>Link Notes</th>
                    <th>Action</th>
                </tr>

                <?php foreach ($linkedKpiResults as $kr): ?>
                    <?php $achievementClass = achievementClass($kr["achievement_percentage"]); ?>

                    <tr>
                        <td><?php echo htmlspecialchars($kr["kpi_result_id"]); ?></td>

                        <td>
                            <strong><?php echo htmlspecialchars($kr["kpi_code"]); ?></strong><br>
                            <?php echo htmlspecialchars($kr["kpi_name"]); ?>
                        </td>

                        <td><?php echo htmlspecialchars($kr["academic_year"]); ?></td>
                        <td><?php echo htmlspecialchars($kr["semester"]); ?></td>

                        <td>
                            <?php echo htmlspecialchars($kr["target_value"]); ?>
                            <?php echo htmlspecialchars($kr["unit"] ?? ""); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($kr["actual_value"]); ?>
                            <?php echo htmlspecialchars($kr["unit"] ?? ""); ?>
                        </td>

                        <td class="<?php echo htmlspecialchars($achievementClass); ?>">
                            <?php echo htmlspecialchars($kr["achievement_percentage"]); ?>%
                        </td>

                        <td><?php echo htmlspecialchars($kr["status"]); ?></td>

                        <td><?php echo nl2br(htmlspecialchars($kr["analysis"] ?? "")); ?></td>

                        <td><?php echo nl2br(htmlspecialchars($kr["improvement_action"] ?? "")); ?></td>

                        <td><?php echo nl2br(htmlspecialchars($kr["link_notes"] ?? "")); ?></td>

                        <td>
                            <a href="unlink_report_kpi_result.php?id=<?php echo htmlspecialchars($kr["link_id"]); ?>&report_id=<?php echo htmlspecialchars($report_id); ?>"
                               class="btn btn-delete"
                               onclick="return confirm('Remove this KPI result link?');">
                                Unlink
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No KPI results linked yet.</p>
        <?php endif; ?>
    </div>

    <div class="linked-section panel">
        <h2>Linked Evidence</h2>

        <?php if (count($linkedEvidence) > 0): ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Evidence</th>
                    <th>Standard</th>
                    <th>Substandard</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Academic Year</th>
                    <th>Semester</th>
                    <th>Link Notes</th>
                    <th>Action</th>
                </tr>

                <?php foreach ($linkedEvidence as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item["evidence_id"]); ?></td>
                        <td><?php echo htmlspecialchars($item["title"]); ?></td>
                        <td><?php echo htmlspecialchars($item["standard_code"] ?? "Not linked"); ?></td>
                        <td><?php echo htmlspecialchars($item["substandard_code"] ?? "Not linked"); ?></td>
                        <td><?php echo htmlspecialchars($item["evidence_type"] ?? ""); ?></td>
                        <td><?php echo htmlspecialchars($item["status"] ?? ""); ?></td>
                        <td><?php echo htmlspecialchars($item["academic_year"] ?? ""); ?></td>
                        <td><?php echo htmlspecialchars($item["semester"] ?? ""); ?></td>
                        <td><?php echo nl2br(htmlspecialchars($item["link_notes"] ?? "")); ?></td>
                        <td>
                            <a href="unlink_report_evidence.php?id=<?php echo htmlspecialchars($item["link_id"]); ?>&report_id=<?php echo htmlspecialchars($report_id); ?>"
                               class="btn btn-delete"
                               onclick="return confirm('Remove this evidence link?');">
                                Unlink
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No evidence linked yet.</p>
        <?php endif; ?>
    </div>

    <div class="linked-section panel">
        <h2>Linked Tasks</h2>

        <?php if (count($linkedTasks) > 0): ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Task</th>
                    <th>Standard</th>
                    <th>Substandard</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Due Date</th>
                    <th>Link Notes</th>
                    <th>Action</th>
                </tr>

                <?php foreach ($linkedTasks as $task): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($task["task_id"]); ?></td>
                        <td><?php echo htmlspecialchars($task["task_title"]); ?></td>
                        <td><?php echo htmlspecialchars($task["standard_code"] ?? "Not linked"); ?></td>
                        <td><?php echo htmlspecialchars($task["substandard_code"] ?? "Not linked"); ?></td>
                        <td><?php echo htmlspecialchars($task["priority"] ?? ""); ?></td>
                        <td><?php echo htmlspecialchars($task["status"] ?? ""); ?></td>
                        <td><?php echo htmlspecialchars($task["due_date"] ?? ""); ?></td>
                        <td><?php echo nl2br(htmlspecialchars($task["link_notes"] ?? "")); ?></td>
                        <td>
                            <a href="unlink_report_task.php?id=<?php echo htmlspecialchars($task["link_id"]); ?>&report_id=<?php echo htmlspecialchars($report_id); ?>"
                               class="btn btn-delete"
                               onclick="return confirm('Remove this task link?');">
                                Unlink
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No tasks linked yet.</p>
        <?php endif; ?>
    </div>

    <div class="linked-section panel">
        <h2>Linked Course Reports</h2>

        <?php if (count($linkedCourseReports) > 0): ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Course</th>
                    <th>Academic Year</th>
                    <th>Semester</th>
                    <th>Coordinator</th>
                    <th>Students</th>
                    <th>Status</th>
                    <th>Link Notes</th>
                    <th>Action</th>
                </tr>

                <?php foreach ($linkedCourseReports as $cr): ?>
                    <?php
                        $enrolled = (int)($cr["students_enrolled"] ?? 0);
                        $passed = (int)($cr["students_passed"] ?? 0);
                        $failed = (int)($cr["students_failed"] ?? 0);
                        $passRate = calcRate($passed, $enrolled);
                    ?>

                    <tr>
                        <td><?php echo htmlspecialchars($cr["course_report_id"]); ?></td>

                        <td>
                            <strong><?php echo htmlspecialchars($cr["course_code"]); ?></strong><br>
                            <?php echo htmlspecialchars($cr["course_title"]); ?>
                        </td>

                        <td><?php echo htmlspecialchars($cr["academic_year"]); ?></td>
                        <td><?php echo htmlspecialchars($cr["semester"]); ?></td>
                        <td><?php echo htmlspecialchars($cr["course_coordinator"] ?? ""); ?></td>

                        <td>
                            Enrolled: <?php echo htmlspecialchars($enrolled); ?><br>
                            Passed: <?php echo htmlspecialchars($passed); ?><br>
                            Failed: <?php echo htmlspecialchars($failed); ?><br>
                            Pass Rate: <?php echo htmlspecialchars($passRate); ?>%
                        </td>

                        <td><?php echo htmlspecialchars($cr["status"]); ?></td>

                        <td><?php echo nl2br(htmlspecialchars($cr["link_notes"] ?? "")); ?></td>

                        <td>
                            <a href="unlink_report_course_report.php?id=<?php echo htmlspecialchars($cr["link_id"]); ?>&report_id=<?php echo htmlspecialchars($report_id); ?>"
                               class="btn btn-delete"
                               onclick="return confirm('Remove this course report link?');">
                                Unlink
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No course reports linked yet.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <a href="view_semester_report.php?id=<?php echo htmlspecialchars($report_id); ?>" class="btn btn-purple">
            View Report
        </a>

        <a href="semester_reports.php" class="btn btn-secondary">
            Back to Reports
        </a>
    </div>

</div>

</body>
</html>