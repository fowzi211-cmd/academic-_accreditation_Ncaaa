<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid semester report ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Load report
// ===============================
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

// ===============================
// Load linked evidence
// ===============================
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

// ===============================
// Load linked tasks
// ===============================
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

// ===============================
// Load linked course reports
// ===============================
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

// ===============================
// Load linked KPI results
// ===============================
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

// ===============================
// Helper functions
// ===============================
function showText($text) {
    if ($text === null || trim((string)$text) === "") {
        return '<span class="missing">Not provided</span>';
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
    <title>View Semester Program Report</title>
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

        .actions {
            margin-bottom: 18px;
        }

        .report-title {
            margin-top: 0;
            margin-bottom: 14px;
        }

        .meta-table th {
            width: 220px;
        }

        .section-text {
            line-height: 1.6;
        }

        .missing {
            color: #999;
            font-style: italic;
        }

        .status {
            font-weight: bold;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .summary-card {
            background: white;
            padding: 16px;
            border: 1px solid #ddd;
            border-radius: 10px;
        }

        .summary-number {
            font-size: 28px;
            font-weight: bold;
            color: #007bff;
            line-height: 1;
        }

        .summary-label {
            color: #555;
            margin-top: 8px;
            font-size: 13px;
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

        @media print {
            .top-bar,
            nav,
            .actions,
            .btn {
                display: none !important;
            }

            body {
                background: white;
                margin: 20px;
            }

            .container {
                width: 100%;
                max-width: none;
                margin: 0;
            }

            .panel,
            .summary-card {
                border: 1px solid #999;
                box-shadow: none;
                page-break-inside: avoid;
            }

            table {
                font-size: 12px;
            }
        }

        @media (max-width: 1000px) {
            .summary-cards {
                grid-template-columns: repeat(2, minmax(180px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .summary-cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>View Semester Program Report</h1>
        <p>Review the semester program report, linked evidence, tasks, course reports, and KPI results.</p>
    </div>

    <div class="actions">
        <a href="semester_reports.php" class="btn btn-secondary">Back to Reports</a>

        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
            <a href="edit_semester_report.php?id=<?php echo htmlspecialchars($report["id"]); ?>" class="btn btn-edit">
                Edit Report
            </a>

            <a href="link_semester_report_items.php?id=<?php echo htmlspecialchars($report["id"]); ?>" class="btn">
                Link Evidence / Tasks / Course Reports / KPI Results
            </a>
        <?php endif; ?>

        <button onclick="window.print();" class="btn btn-purple">
            Print / Save as PDF
        </button>

        <a href="export_semester_report_word.php?id=<?php echo htmlspecialchars($report["id"]); ?>" class="btn">
            Export Word
        </a>
    </div>

    <div class="panel">
        <h2 class="report-title"><?php echo htmlspecialchars($report["report_title"]); ?></h2>

        <table class="meta-table">
            <tr>
                <th>Academic Year</th>
                <td><?php echo htmlspecialchars($report["academic_year"]); ?></td>
            </tr>

            <tr>
                <th>Semester</th>
                <td><?php echo htmlspecialchars($report["semester"]); ?></td>
            </tr>

            <tr>
                <th>Prepared By</th>
                <td><?php echo htmlspecialchars($report["prepared_by"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Status</th>
                <td class="status"><?php echo htmlspecialchars($report["status"]); ?></td>
            </tr>

            <tr>
                <th>Created At</th>
                <td><?php echo htmlspecialchars($report["created_at"]); ?></td>
            </tr>

            <tr>
                <th>Updated At</th>
                <td><?php echo htmlspecialchars($report["updated_at"]); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-cards">
        <div class="summary-card">
            <div class="summary-number"><?php echo htmlspecialchars(count($linkedEvidence)); ?></div>
            <div class="summary-label">Linked Evidence Items</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo htmlspecialchars(count($linkedTasks)); ?></div>
            <div class="summary-label">Linked Improvement Tasks</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo htmlspecialchars(count($linkedCourseReports)); ?></div>
            <div class="summary-label">Linked Course Reports</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo htmlspecialchars(count($linkedKpiResults)); ?></div>
            <div class="summary-label">Linked KPI Results</div>
        </div>
    </div>

    <div class="panel">
        <h2>Program Strengths</h2>
        <div class="section-text"><?php echo showText($report["program_strengths"] ?? ""); ?></div>
    </div>

    <div class="panel">
        <h2>Program Weaknesses / Areas for Improvement</h2>
        <div class="section-text"><?php echo showText($report["program_weaknesses"] ?? ""); ?></div>
    </div>

    <div class="panel">
        <h2>Improvement Actions</h2>
        <div class="section-text"><?php echo showText($report["improvement_actions"] ?? ""); ?></div>
    </div>

    <div class="panel">
        <h2>KPI Summary</h2>
        <div class="section-text"><?php echo showText($report["kpi_summary"] ?? ""); ?></div>
    </div>

    <div class="panel">
        <h2>Course Report Summary</h2>
        <div class="section-text"><?php echo showText($report["course_report_summary"] ?? ""); ?></div>
    </div>

    <div class="panel">
        <h2>Student Feedback Summary</h2>
        <div class="section-text"><?php echo showText($report["student_feedback_summary"] ?? ""); ?></div>
    </div>

    <div class="panel">
        <h2>Teaching and Learning Summary</h2>
        <div class="section-text"><?php echo showText($report["teaching_learning_summary"] ?? ""); ?></div>
    </div>

    <div class="panel">
        <h2>Assessment Summary</h2>
        <div class="section-text"><?php echo showText($report["assessment_summary"] ?? ""); ?></div>
    </div>

    <div class="panel">
        <h2>Resources Summary</h2>
        <div class="section-text"><?php echo showText($report["resources_summary"] ?? ""); ?></div>
    </div>

    <div class="panel">
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
                    <tr>
                        <td><?php echo htmlspecialchars($kr["kpi_result_id"]); ?></td>

                        <td>
                            <strong><?php echo htmlspecialchars($kr["kpi_code"]); ?></strong><br>
                            <?php echo htmlspecialchars($kr["kpi_name"]); ?><br>
                            <span class="small-text">
                                Responsible:
                                <?php echo htmlspecialchars($kr["responsible_person"] ?? ""); ?>
                            </span>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($kr["academic_year"]); ?><br>
                            <?php echo htmlspecialchars($kr["semester"]); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($kr["target_value"]); ?>
                            <?php echo htmlspecialchars($kr["unit"] ?? ""); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($kr["actual_value"]); ?>
                            <?php echo htmlspecialchars($kr["unit"] ?? ""); ?>
                        </td>

                        <td class="<?php echo htmlspecialchars(achievementClass($kr["achievement_percentage"])); ?>">
                            <?php echo htmlspecialchars($kr["achievement_percentage"]); ?>%
                        </td>

                        <td><?php echo htmlspecialchars($kr["status"]); ?></td>

                        <td><?php echo showText($kr["analysis"]); ?></td>

                        <td><?php echo showText($kr["improvement_action"]); ?></td>

                        <td><?php echo showText($kr["link_notes"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No KPI results have been linked to this semester report.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
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
                    <th>Key CLO / Assessment Summary</th>
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
                        <td><?php echo htmlspecialchars($cr["course_report_id"]); ?></td>

                        <td>
                            <strong><?php echo htmlspecialchars($cr["course_code"]); ?></strong><br>
                            <?php echo htmlspecialchars($cr["course_title"]); ?><br>
                            <span class="small-text">
                                <?php echo htmlspecialchars($cr["academic_year"]); ?>
                                |
                                <?php echo htmlspecialchars($cr["semester"]); ?>
                            </span>
                        </td>

                        <td><?php echo htmlspecialchars($cr["course_coordinator"] ?? ""); ?></td>

                        <td>
                            Enrolled: <?php echo htmlspecialchars($enrolled); ?><br>
                            Passed: <?php echo htmlspecialchars($passed); ?><br>
                            Failed: <?php echo htmlspecialchars($failed); ?>
                        </td>

                        <td>
                            Pass: <?php echo htmlspecialchars($passRate); ?>%<br>
                            Fail: <?php echo htmlspecialchars($failRate); ?>%
                        </td>

                        <td><?php echo htmlspecialchars($cr["status"] ?? ""); ?></td>

                        <td>
                            <strong>CLO:</strong><br>
                            <?php echo showText($cr["clo_achievement_summary"]); ?>
                            <br><br>
                            <strong>Assessment:</strong><br>
                            <?php echo showText($cr["assessment_summary"]); ?>
                        </td>

                        <td><?php echo showText($cr["course_improvement_actions"]); ?></td>

                        <td><?php echo showText($cr["link_notes"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No course reports have been linked to this semester report.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
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
                    <th>Review</th>
                    <th>Files</th>
                    <th>Link Notes</th>
                </tr>

                <?php foreach ($linkedEvidence as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item["evidence_id"]); ?></td>

                        <td>
                            <strong><?php echo htmlspecialchars($item["title"]); ?></strong>

                            <?php if (!empty($item["evidence_notes"])): ?>
                                <br>
                                <span class="small-text">
                                    <?php echo nl2br(htmlspecialchars($item["evidence_notes"])); ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($item["standard_code"] ?? "Not linked"); ?>
                            <br>
                            <span class="small-text">
                                <?php echo htmlspecialchars($item["standard_title"] ?? ""); ?>
                            </span>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($item["substandard_code"] ?? "Not linked"); ?>
                            <br>
                            <span class="small-text">
                                <?php echo htmlspecialchars($item["substandard_text"] ?? ""); ?>
                            </span>
                        </td>

                        <td><?php echo htmlspecialchars($item["evidence_type"] ?? ""); ?></td>

                        <td><?php echo htmlspecialchars($item["status"] ?? ""); ?></td>

                        <td><?php echo htmlspecialchars($item["review_status"] ?? ""); ?></td>

                        <td><?php echo htmlspecialchars($item["file_count"]); ?> file(s)</td>

                        <td><?php echo showText($item["link_notes"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No evidence has been linked to this report.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
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
                        <td><?php echo htmlspecialchars($task["task_id"]); ?></td>

                        <td>
                            <strong><?php echo htmlspecialchars($task["task_title"]); ?></strong>

                            <?php if (!empty($task["task_description"])): ?>
                                <br>
                                <span class="small-text">
                                    <?php echo nl2br(htmlspecialchars($task["task_description"])); ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($task["standard_code"] ?? "Not linked"); ?>
                            <br>
                            <span class="small-text">
                                <?php echo htmlspecialchars($task["standard_title"] ?? ""); ?>
                            </span>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($task["substandard_code"] ?? "Not linked"); ?>
                            <br>
                            <span class="small-text">
                                <?php echo htmlspecialchars($task["substandard_text"] ?? ""); ?>
                            </span>
                        </td>

                        <td><?php echo htmlspecialchars($task["responsible_person"] ?? ""); ?></td>

                        <td><?php echo htmlspecialchars($task["due_date"] ?? ""); ?></td>

                        <td><?php echo htmlspecialchars($task["priority"] ?? ""); ?></td>

                        <td><?php echo htmlspecialchars($task["status"] ?? ""); ?></td>

                        <td><?php echo showText($task["link_notes"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No improvement tasks have been linked to this report.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>General Notes</h2>
        <div class="section-text"><?php echo showText($report["general_notes"] ?? ""); ?></div>
    </div>

</div>

</body>
</html>