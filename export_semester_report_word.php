<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid semester report ID.");
}

$id = (int) $_GET["id"];

// Load report
$stmt = $conn->prepare("
    SELECT *
    FROM semester_reports
    WHERE id = :id
");
$stmt->execute([":id" => $id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die("Semester report not found.");
}

// Load system settings
$settingsStmt = $conn->query("
    SELECT *
    FROM system_settings
    ORDER BY id ASC
    LIMIT 1
");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

// Load linked evidence
$linkedEvidenceStmt = $conn->prepare("
    SELECT
        sre.notes AS link_notes,
        e.id AS evidence_id,
        e.title,
        e.evidence_type,
        e.responsible_person,
        e.status,
        e.academic_year,
        e.semester,
        e.review_status,
        e.notes AS evidence_notes,
        ss.substandard_code,
        ss.substandard_text,
        s.standard_code,
        s.standard_title,
        COUNT(ef.id) AS file_count
    FROM semester_report_evidence sre
    INNER JOIN evidence_items e ON sre.evidence_id = e.id
    LEFT JOIN substandards ss ON e.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
    LEFT JOIN evidence_files ef ON e.id = ef.evidence_id
    WHERE sre.report_id = :report_id
    GROUP BY
        sre.id,
        sre.notes,
        e.id,
        e.title,
        e.evidence_type,
        e.responsible_person,
        e.status,
        e.academic_year,
        e.semester,
        e.review_status,
        e.notes,
        ss.substandard_code,
        ss.substandard_text,
        s.standard_code,
        s.standard_title
    ORDER BY e.id DESC
");
$linkedEvidenceStmt->execute([":report_id" => $id]);
$linkedEvidence = $linkedEvidenceStmt->fetchAll(PDO::FETCH_ASSOC);

// Load linked tasks
$linkedTaskStmt = $conn->prepare("
    SELECT
        srt.notes AS link_notes,
        t.id AS task_id,
        t.task_title,
        t.task_description,
        t.responsible_person,
        t.due_date,
        t.priority,
        t.status,
        t.notes AS task_notes,
        ss.substandard_code,
        ss.substandard_text,
        s.standard_code,
        s.standard_title
    FROM semester_report_tasks srt
    INNER JOIN tasks t ON srt.task_id = t.id
    LEFT JOIN substandards ss ON t.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
    WHERE srt.report_id = :report_id
    ORDER BY
        CASE
            WHEN t.priority = 'High' THEN 1
            WHEN t.priority = 'Medium' THEN 2
            WHEN t.priority = 'Low' THEN 3
            ELSE 4
        END,
        t.due_date ASC,
        t.id DESC
");
$linkedTaskStmt->execute([":report_id" => $id]);
$linkedTasks = $linkedTaskStmt->fetchAll(PDO::FETCH_ASSOC);

// Load linked course reports
$linkedCourseReportStmt = $conn->prepare("
    SELECT
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
        cr.clo_achievement_summary,
        cr.assessment_summary,
        cr.teaching_summary,
        cr.student_feedback_summary,
        cr.course_improvement_actions,
        cr.status
    FROM semester_report_course_reports srcr
    INNER JOIN course_reports cr ON srcr.course_report_id = cr.id
    WHERE srcr.report_id = :report_id
    ORDER BY cr.id DESC
");
$linkedCourseReportStmt->execute([":report_id" => $id]);
$linkedCourseReports = $linkedCourseReportStmt->fetchAll(PDO::FETCH_ASSOC);

// Load linked KPI results
$linkedKpiResultStmt = $conn->prepare("
    SELECT
        srkr.notes AS link_notes,
        kr.id AS kpi_result_id,
        kr.academic_year,
        kr.semester,
        kr.target_value,
        kr.actual_value,
        kr.achievement_percentage,
        kr.analysis,
        kr.improvement_action,
        kr.status,
        k.kpi_code,
        k.kpi_name,
        k.kpi_description,
        k.measurement_method,
        k.unit,
        k.responsible_person
    FROM semester_report_kpi_results srkr
    INNER JOIN kpi_results kr ON srkr.kpi_result_id = kr.id
    INNER JOIN kpis k ON kr.kpi_id = k.id
    WHERE srkr.report_id = :report_id
    ORDER BY kr.id DESC
");
$linkedKpiResultStmt->execute([":report_id" => $id]);
$linkedKpiResults = $linkedKpiResultStmt->fetchAll(PDO::FETCH_ASSOC);

function cleanText($text) {
    if ($text === null || trim((string)$text) === "") {
        return "Not provided";
    }

    return htmlspecialchars((string)$text);
}

function cleanParagraph($text) {
    if ($text === null || trim((string)$text) === "") {
        return "<em>Not provided</em>";
    }

    return nl2br(htmlspecialchars((string)$text));
}

function calcRate($part, $total) {
    $part = (int)$part;
    $total = (int)$total;

    if ($total <= 0) {
        return 0;
    }

    return round(($part / $total) * 100, 1);
}

$safeTitle = preg_replace("/[^a-zA-Z0-9_\-]/", "_", $report["report_title"]);
$filename = $safeTitle . "_" . date("Y-m-d_H-i-s") . ".doc";

header("Content-Type: application/msword; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// UTF-8 BOM for Word compatibility
echo "\xEF\xBB\xBF";
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo cleanText($report["report_title"]); ?></title>
 <link rel="stylesheet" href="assets/style.css">

    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12pt;
            line-height: 1.5;
            color: #000;
        }

        h1 {
            text-align: center;
            font-size: 20pt;
            margin-bottom: 20px;
        }

        h2 {
            font-size: 16pt;
            margin-top: 25px;
            border-bottom: 1px solid #333;
            padding-bottom: 5px;
        }

        h3 {
            font-size: 13pt;
            margin-top: 18px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }

        th, td {
            border: 1px solid #000;
            padding: 6px;
            vertical-align: top;
        }

        th {
            background-color: #e6e6e6;
            font-weight: bold;
        }

        .meta th {
            width: 220px;
        }

        .small {
            font-size: 10pt;
        }

        .good {
            color: #008000;
            font-weight: bold;
        }

        .warning {
            color: #cc7a00;
            font-weight: bold;
        }

        .bad {
            color: #cc0000;
            font-weight: bold;
        }
    </style>
</head>

<body>

<h1>Semester Program Report</h1>

<table class="meta">
    <tr>
        <th>Institution</th>
        <td><?php echo cleanText($settings["institution_name"] ?? ""); ?></td>
    </tr>

    <tr>
        <th>College</th>
        <td><?php echo cleanText($settings["college_name"] ?? ""); ?></td>
    </tr>

    <tr>
        <th>Department</th>
        <td><?php echo cleanText($settings["department_name"] ?? ""); ?></td>
    </tr>

    <tr>
        <th>Program</th>
        <td><?php echo cleanText($settings["program_name"] ?? ""); ?></td>
    </tr>
</table>

<h2>Report Information</h2>

<table class="meta">
    <tr>
        <th>Report Title</th>
        <td><?php echo cleanText($report["report_title"]); ?></td>
    </tr>

    <tr>
        <th>Academic Year</th>
        <td><?php echo cleanText($report["academic_year"]); ?></td>
    </tr>

    <tr>
        <th>Semester</th>
        <td><?php echo cleanText($report["semester"]); ?></td>
    </tr>

    <tr>
        <th>Prepared By</th>
        <td><?php echo cleanText($report["prepared_by"]); ?></td>
    </tr>

    <tr>
        <th>Status</th>
        <td><?php echo cleanText($report["status"]); ?></td>
    </tr>

    <tr>
        <th>Created At</th>
        <td><?php echo cleanText($report["created_at"]); ?></td>
    </tr>

    <tr>
        <th>Updated At</th>
        <td><?php echo cleanText($report["updated_at"]); ?></td>
    </tr>
</table>

<h2>Linked Evidence Summary</h2>

<table>
    <tr>
        <th>Linked Evidence Items</th>
        <th>Linked Course Reports</th>
        <th>Linked KPI Results</th>
        <th>Linked Improvement Tasks</th>
    </tr>

    <tr>
        <td><?php echo count($linkedEvidence); ?></td>
        <td><?php echo count($linkedCourseReports); ?></td>
        <td><?php echo count($linkedKpiResults); ?></td>
        <td><?php echo count($linkedTasks); ?></td>
    </tr>
</table>

<h2>Program Evaluation Summary</h2>

<h3>Program Strengths</h3>
<p><?php echo cleanParagraph($report["program_strengths"]); ?></p>

<h3>Program Weaknesses / Areas for Improvement</h3>
<p><?php echo cleanParagraph($report["program_weaknesses"]); ?></p>

<h3>Improvement Actions</h3>
<p><?php echo cleanParagraph($report["improvement_actions"]); ?></p>

<h2>Quality Indicators and Evidence Summary</h2>

<h3>KPI Summary</h3>
<p><?php echo cleanParagraph($report["kpi_summary"]); ?></p>

<h3>Course Report Summary</h3>
<p><?php echo cleanParagraph($report["course_report_summary"]); ?></p>

<h3>Student Feedback Summary</h3>
<p><?php echo cleanParagraph($report["student_feedback_summary"]); ?></p>

<h3>Teaching and Learning Summary</h3>
<p><?php echo cleanParagraph($report["teaching_learning_summary"]); ?></p>

<h3>Assessment Summary</h3>
<p><?php echo cleanParagraph($report["assessment_summary"]); ?></p>

<h3>Resources Summary</h3>
<p><?php echo cleanParagraph($report["resources_summary"]); ?></p>

<h2>Linked KPI Results Supporting This Semester Report</h2>

<?php if (count($linkedKpiResults) > 0): ?>

    <table>
        <tr>
            <th>ID</th>
            <th>KPI</th>
            <th>Academic Period</th>
            <th>Target</th>
            <th>Actual</th>
            <th>Achievement %</th>
            <th>Status</th>
            <th>Analysis</th>
            <th>Improvement Action</th>
            <th>Link Notes</th>
        </tr>

        <?php foreach ($linkedKpiResults as $kr): ?>
            <?php
                $achievement = (float)$kr["achievement_percentage"];
                $achievementClass = "bad";

                if ($achievement >= 100) {
                    $achievementClass = "good";
                } elseif ($achievement >= 80) {
                    $achievementClass = "warning";
                }
            ?>

            <tr>
                <td><?php echo cleanText($kr["kpi_result_id"]); ?></td>

                <td>
                    <strong><?php echo cleanText($kr["kpi_code"]); ?></strong><br>
                    <?php echo cleanText($kr["kpi_name"]); ?><br>
                    <span class="small">
                        Responsible:
                        <?php echo cleanText($kr["responsible_person"]); ?>
                    </span>
                </td>

                <td>
                    <?php echo cleanText($kr["academic_year"]); ?><br>
                    <?php echo cleanText($kr["semester"]); ?>
                </td>

                <td>
                    <?php echo cleanText($kr["target_value"]); ?>
                    <?php echo cleanText($kr["unit"]); ?>
                </td>

                <td>
                    <?php echo cleanText($kr["actual_value"]); ?>
                    <?php echo cleanText($kr["unit"]); ?>
                </td>

                <td class="<?php echo $achievementClass; ?>">
                    <?php echo cleanText($kr["achievement_percentage"]); ?>%
                </td>

                <td><?php echo cleanText($kr["status"]); ?></td>

                <td><?php echo cleanParagraph($kr["analysis"]); ?></td>

                <td><?php echo cleanParagraph($kr["improvement_action"]); ?></td>

                <td><?php echo cleanParagraph($kr["link_notes"]); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

<?php else: ?>

    <p><em>No KPI results have been linked to this semester report.</em></p>

<?php endif; ?>

<h2>Linked Course Reports Supporting This Semester Report</h2>

<?php if (count($linkedCourseReports) > 0): ?>

    <table>
        <tr>
            <th>ID</th>
            <th>Course</th>
            <th>Coordinator</th>
            <th>Students</th>
            <th>Pass / Fail Rate</th>
            <th>Status</th>
            <th>CLO / Assessment Summary</th>
            <th>Improvement Actions</th>
            <th>Link Notes</th>
        </tr>

        <?php foreach ($linkedCourseReports as $cr): ?>
            <?php
                $enrolled = (int)($cr["students_enrolled"] ?? 0);
                $passed = (int)($cr["students_passed"] ?? 0);
                $failed = (int)($cr["students_failed"] ?? 0);
                $passRate = calcRate($passed, $enrolled);
                $failRate = calcRate($failed, $enrolled);
            ?>

            <tr>
                <td><?php echo cleanText($cr["course_report_id"]); ?></td>

                <td>
                    <strong><?php echo cleanText($cr["course_code"]); ?></strong><br>
                    <?php echo cleanText($cr["course_title"]); ?><br>
                    <span class="small">
                        <?php echo cleanText($cr["academic_year"]); ?>
                        |
                        <?php echo cleanText($cr["semester"]); ?>
                    </span>
                </td>

                <td><?php echo cleanText($cr["course_coordinator"]); ?></td>

                <td>
                    Enrolled: <?php echo cleanText($enrolled); ?><br>
                    Passed: <?php echo cleanText($passed); ?><br>
                    Failed: <?php echo cleanText($failed); ?>
                </td>

                <td>
                    Pass: <?php echo cleanText($passRate); ?>%<br>
                    Fail: <?php echo cleanText($failRate); ?>%
                </td>

                <td><?php echo cleanText($cr["status"]); ?></td>

                <td>
                    <strong>CLO Achievement:</strong><br>
                    <?php echo cleanParagraph($cr["clo_achievement_summary"]); ?>
                    <br><br>
                    <strong>Assessment:</strong><br>
                    <?php echo cleanParagraph($cr["assessment_summary"]); ?>
                </td>

                <td><?php echo cleanParagraph($cr["course_improvement_actions"]); ?></td>

                <td><?php echo cleanParagraph($cr["link_notes"]); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

<?php else: ?>

    <p><em>No course reports have been linked to this semester report.</em></p>

<?php endif; ?>

<h2>Linked Evidence Supporting This Report</h2>

<?php if (count($linkedEvidence) > 0): ?>

    <table>
        <tr>
            <th>ID</th>
            <th>Evidence Title</th>
            <th>Standard</th>
            <th>Substandard</th>
            <th>Type</th>
            <th>Status</th>
            <th>Review Status</th>
            <th>Files</th>
            <th>Link Notes</th>
        </tr>

        <?php foreach ($linkedEvidence as $item): ?>
            <tr>
                <td><?php echo cleanText($item["evidence_id"]); ?></td>

                <td>
                    <strong><?php echo cleanText($item["title"]); ?></strong><br>
                    <span class="small"><?php echo cleanParagraph($item["evidence_notes"]); ?></span>
                </td>

                <td>
                    <?php echo cleanText($item["standard_code"] ?? "Not linked"); ?><br>
                    <span class="small"><?php echo cleanText($item["standard_title"] ?? ""); ?></span>
                </td>

                <td>
                    <?php echo cleanText($item["substandard_code"] ?? "Not linked"); ?><br>
                    <span class="small"><?php echo cleanText($item["substandard_text"] ?? ""); ?></span>
                </td>

                <td><?php echo cleanText($item["evidence_type"]); ?></td>

                <td><?php echo cleanText($item["status"]); ?></td>

                <td><?php echo cleanText($item["review_status"]); ?></td>

                <td><?php echo cleanText($item["file_count"]); ?> file(s)</td>

                <td><?php echo cleanParagraph($item["link_notes"]); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

<?php else: ?>

    <p><em>No evidence has been linked to this report.</em></p>

<?php endif; ?>

<h2>Linked Improvement Tasks</h2>

<?php if (count($linkedTasks) > 0): ?>

    <table>
        <tr>
            <th>ID</th>
            <th>Task Title</th>
            <th>Standard</th>
            <th>Substandard</th>
            <th>Responsible</th>
            <th>Due Date</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Link Notes</th>
        </tr>

        <?php foreach ($linkedTasks as $task): ?>
            <tr>
                <td><?php echo cleanText($task["task_id"]); ?></td>

                <td>
                    <strong><?php echo cleanText($task["task_title"]); ?></strong><br>
                    <span class="small"><?php echo cleanParagraph($task["task_description"]); ?></span>
                </td>

                <td>
                    <?php echo cleanText($task["standard_code"] ?? "Not linked"); ?><br>
                    <span class="small"><?php echo cleanText($task["standard_title"] ?? ""); ?></span>
                </td>

                <td>
                    <?php echo cleanText($task["substandard_code"] ?? "Not linked"); ?><br>
                    <span class="small"><?php echo cleanText($task["substandard_text"] ?? ""); ?></span>
                </td>

                <td><?php echo cleanText($task["responsible_person"]); ?></td>

                <td><?php echo cleanText($task["due_date"]); ?></td>

                <td><?php echo cleanText($task["priority"]); ?></td>

                <td><?php echo cleanText($task["status"]); ?></td>

                <td><?php echo cleanParagraph($task["link_notes"]); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

<?php else: ?>

    <p><em>No improvement tasks have been linked to this report.</em></p>

<?php endif; ?>

<h2>General Notes</h2>
<p><?php echo cleanParagraph($report["general_notes"]); ?></p>

</body>
</html>