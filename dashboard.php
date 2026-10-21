<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"]);

// ===============================
// Main counts
// ===============================
$totalEvidence = $conn->query("SELECT COUNT(*) FROM evidence_items")->fetchColumn();
$totalFiles = $conn->query("SELECT COUNT(*) FROM evidence_files")->fetchColumn();

$totalTasks = $conn->query("SELECT COUNT(*) FROM tasks")->fetchColumn();

$pendingTasksCount = $conn->query("
    SELECT COUNT(*) 
    FROM tasks 
    WHERE status IN ('Pending', 'In Progress')
")->fetchColumn();

$overdueTasksCount = $conn->query("
    SELECT COUNT(*) 
    FROM tasks 
    WHERE due_date IS NOT NULL
      AND due_date < CURDATE()
      AND status NOT IN ('Completed', 'Cancelled')
")->fetchColumn();

// ===============================
// Semester report counts
// ===============================
$totalSemesterReports = $conn->query("SELECT COUNT(*) FROM semester_reports")->fetchColumn();

$draftSemesterReports = $conn->query("
    SELECT COUNT(*) 
    FROM semester_reports 
    WHERE status = 'Draft'
")->fetchColumn();

$underReviewSemesterReports = $conn->query("
    SELECT COUNT(*) 
    FROM semester_reports 
    WHERE status = 'Under Review'
")->fetchColumn();

$approvedSemesterReports = $conn->query("
    SELECT COUNT(*) 
    FROM semester_reports 
    WHERE status = 'Approved'
")->fetchColumn();

// ===============================
// Course report counts
// ===============================
$totalCourseReports = $conn->query("SELECT COUNT(*) FROM course_reports")->fetchColumn();

$draftCourseReports = $conn->query("
    SELECT COUNT(*) 
    FROM course_reports 
    WHERE status = 'Draft'
")->fetchColumn();

$submittedCourseReports = $conn->query("
    SELECT COUNT(*) 
    FROM course_reports 
    WHERE status = 'Submitted'
")->fetchColumn();

$underReviewCourseReports = $conn->query("
    SELECT COUNT(*) 
    FROM course_reports 
    WHERE status = 'Under Review'
")->fetchColumn();

$approvedCourseReports = $conn->query("
    SELECT COUNT(*) 
    FROM course_reports 
    WHERE status = 'Approved'
")->fetchColumn();

// ===============================
// KPI counts
// ===============================
$totalKpis = $conn->query("SELECT COUNT(*) FROM kpis")->fetchColumn();

$activeKpis = $conn->query("
    SELECT COUNT(*)
    FROM kpis
    WHERE status = 'Active'
")->fetchColumn();

$totalKpiResults = $conn->query("SELECT COUNT(*) FROM kpi_results")->fetchColumn();

$approvedKpiResults = $conn->query("
    SELECT COUNT(*)
    FROM kpi_results
    WHERE status = 'Approved'
")->fetchColumn();

$kpiResultsBelowTarget = $conn->query("
    SELECT COUNT(*)
    FROM kpi_results
    WHERE achievement_percentage < 100
")->fetchColumn();

// ===============================
// KPI achievement monitoring
// Latest result per active KPI
// ===============================
$kpiAchievementStmt = $conn->query("
    SELECT
        k.id AS kpi_id,
        k.kpi_code,
        k.kpi_name,
        k.target_value AS default_target,
        k.unit,
        k.responsible_person,
        kr.id AS result_id,
        kr.academic_year,
        kr.semester,
        kr.target_value,
        kr.actual_value,
        kr.achievement_percentage,
        kr.status AS result_status,
        kr.updated_at
    FROM kpis k
    LEFT JOIN kpi_results kr 
        ON kr.id = (
            SELECT kr2.id
            FROM kpi_results kr2
            WHERE kr2.kpi_id = k.id
            ORDER BY kr2.id DESC
            LIMIT 1
        )
    WHERE k.status = 'Active'
    ORDER BY k.kpi_code
    LIMIT 10
");
$kpiAchievementRows = $kpiAchievementStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Latest KPI results
// ===============================
$latestKpiResultsStmt = $conn->query("
    SELECT
        kr.id,
        kr.academic_year,
        kr.semester,
        kr.target_value,
        kr.actual_value,
        kr.achievement_percentage,
        kr.status,
        kr.updated_at,
        k.kpi_code,
        k.kpi_name,
        k.unit
    FROM kpi_results kr
    INNER JOIN kpis k ON kr.kpi_id = k.id
    ORDER BY kr.id DESC
    LIMIT 5
");
$latestKpiResults = $latestKpiResultsStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Latest course reports
// ===============================
$latestCourseReportsStmt = $conn->query("
    SELECT
        id,
        course_code,
        course_title,
        academic_year,
        semester,
        course_coordinator,
        students_enrolled,
        students_passed,
        students_failed,
        status,
        updated_at
    FROM course_reports
    ORDER BY id DESC
    LIMIT 5
");
$latestCourseReports = $latestCourseReportsStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Latest semester reports
// ===============================
$latestSemesterReportsStmt = $conn->query("
    SELECT 
        id,
        academic_year,
        semester,
        report_title,
        prepared_by,
        status,
        updated_at
    FROM semester_reports
    ORDER BY id DESC
    LIMIT 5
");
$latestSemesterReports = $latestSemesterReportsStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Evidence by status
// ===============================
$statusStmt = $conn->query("
    SELECT 
        status_label AS status,
        COUNT(*) AS total
    FROM (
        SELECT COALESCE(NULLIF(status, ''), 'No Status') AS status_label
        FROM evidence_items
    ) AS evidence_status_summary
    GROUP BY status_label
    ORDER BY total DESC
");
$statusCounts = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Evidence by review status
// ===============================
$reviewStmt = $conn->query("
    SELECT 
        review_status_label AS review_status,
        COUNT(*) AS total
    FROM (
        SELECT COALESCE(NULLIF(review_status, ''), 'No Review Status') AS review_status_label
        FROM evidence_items
    ) AS review_status_summary
    GROUP BY review_status_label
    ORDER BY total DESC
");
$reviewCounts = $reviewStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Evidence by standard
// ===============================
$standardStmt = $conn->query("
    SELECT 
        s.standard_code,
        s.standard_title,
        COUNT(e.id) AS total_evidence
    FROM standards s
    LEFT JOIN substandards ss ON ss.standard_id = s.id
    LEFT JOIN evidence_items e ON e.substandard_id = ss.id
    GROUP BY s.id, s.standard_code, s.standard_title
    ORDER BY s.id
");
$standardCounts = $standardStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Substandards without evidence
// Limited to 10 for dashboard
// ===============================
$missingStmt = $conn->query("
    SELECT 
        s.standard_code,
        ss.substandard_code,
        ss.substandard_text
    FROM substandards ss
    INNER JOIN standards s ON ss.standard_id = s.id
    LEFT JOIN evidence_items e ON e.substandard_id = ss.id
    WHERE e.id IS NULL
    ORDER BY s.id, ss.substandard_code
    LIMIT 10
");
$missingSubstandards = $missingStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Evidence needing revision
// Limited to 10 for dashboard
// ===============================
$revisionStmt = $conn->query("
    SELECT 
        e.id,
        e.title,
        e.review_status,
        e.reviewer_notes,
        ss.substandard_code,
        s.standard_code
    FROM evidence_items e
    LEFT JOIN substandards ss ON e.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
    WHERE e.review_status = 'Needs Revision'
    ORDER BY e.id DESC
    LIMIT 10
");
$needsRevision = $revisionStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Tasks by status
// ===============================
$taskStatusStmt = $conn->query("
    SELECT 
        status_label AS status,
        COUNT(*) AS total
    FROM (
        SELECT COALESCE(NULLIF(status, ''), 'No Status') AS status_label
        FROM tasks
    ) AS task_status_summary
    GROUP BY status_label
    ORDER BY total DESC
");
$taskStatusCounts = $taskStatusStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Tasks by priority
// ===============================
$taskPriorityStmt = $conn->query("
    SELECT 
        priority_label AS priority,
        COUNT(*) AS total
    FROM (
        SELECT COALESCE(NULLIF(priority, ''), 'No Priority') AS priority_label
        FROM tasks
    ) AS task_priority_summary
    GROUP BY priority_label
    ORDER BY 
        CASE priority_label
            WHEN 'High' THEN 1
            WHEN 'Medium' THEN 2
            WHEN 'Low' THEN 3
            ELSE 4
        END
");
$taskPriorityCounts = $taskPriorityStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Pending/in-progress task list
// Limited to 10 for dashboard
// ===============================
$pendingTaskListStmt = $conn->query("
    SELECT 
        t.id,
        t.task_title,
        t.responsible_person,
        t.due_date,
        t.priority,
        t.status,
        ss.substandard_code,
        s.standard_code
    FROM tasks t
    LEFT JOIN substandards ss ON t.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
    WHERE t.status IN ('Pending', 'In Progress')
    ORDER BY 
        CASE 
            WHEN t.priority = 'High' THEN 1
            WHEN t.priority = 'Medium' THEN 2
            WHEN t.priority = 'Low' THEN 3
            ELSE 4
        END,
        t.due_date ASC,
        t.id DESC
    LIMIT 10
");
$pendingTaskList = $pendingTaskListStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Overdue task list
// Limited to 10 for dashboard
// ===============================
$overdueTaskListStmt = $conn->query("
    SELECT 
        t.id,
        t.task_title,
        t.responsible_person,
        t.due_date,
        t.priority,
        t.status,
        ss.substandard_code,
        s.standard_code
    FROM tasks t
    LEFT JOIN substandards ss ON t.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
    WHERE t.due_date IS NOT NULL
      AND t.due_date < CURDATE()
      AND t.status NOT IN ('Completed', 'Cancelled')
    ORDER BY t.due_date ASC, t.id DESC
    LIMIT 10
");
$overdueTaskList = $overdueTaskListStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Helper functions
// ===============================
function getPriorityClass($priority) {
    if ($priority === "High") {
        return "priority-high";
    }

    if ($priority === "Medium") {
        return "priority-medium";
    }

    if ($priority === "Low") {
        return "priority-low";
    }

    return "";
}

function calcRate($part, $total) {
    $part = (int)$part;
    $total = (int)$total;

    if ($total <= 0) {
        return 0;
    }

    return round(($part / $total) * 100, 1);
}

function getAchievementClass($achievement) {
    if ($achievement === null || $achievement === "") {
        return "warning-text";
    }

    $achievement = (float)$achievement;

    if ($achievement >= 100) {
        return "ok";
    }

    if ($achievement >= 80) {
        return "pending";
    }

    return "warning-text";
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Quality System Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .dashboard-subtitle {
            color: #666;
            margin-bottom: 20px;
        }

        .section-panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 14px;
        }

        .card-number {
            font-size: 30px;
            font-weight: bold;
            color: #007bff;
            line-height: 1;
        }

        .card-number.warning {
            color: #dc3545;
        }

        .card-number.pending-color {
            color: #fd7e14;
        }

        .card-number.success-color {
            color: #28a745;
        }

        .card-label {
            color: #555;
            margin-top: 8px;
            font-size: 13px;
            line-height: 1.35;
        }

        .warning-text {
            color: #dc3545;
            font-weight: bold;
        }

        .ok {
            color: #28a745;
            font-weight: bold;
        }

        .pending {
            color: #fd7e14;
            font-weight: bold;
        }

        .priority-high {
            color: #dc3545;
            font-weight: bold;
        }

        .priority-medium {
            color: #fd7e14;
            font-weight: bold;
        }

        .priority-low {
            color: #28a745;
            font-weight: bold;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .two-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .section-link {
            margin-top: 10px;
            font-size: 13px;
        }

        @media (max-width: 1200px) {
            .cards {
                grid-template-columns: repeat(2, minmax(180px, 1fr));
            }

            .two-column {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Quality System Dashboard</h1>
        <p>Program quality overview, evidence progress, reporting status, KPI monitoring, and improvement tracking.</p>
    </div>

    <div class="section-panel">
        <h2>Overview</h2>

        <div class="cards">
            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalEvidence); ?></div>
                <div class="card-label">Total Evidence Items</div>
            </div>

            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalFiles); ?></div>
                <div class="card-label">Uploaded Evidence Files</div>
            </div>

            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalTasks); ?></div>
                <div class="card-label">Total Tasks</div>
            </div>

            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalKpis); ?></div>
                <div class="card-label">Total KPIs</div>
            </div>
        </div>
    </div>

    <div class="section-panel">
        <h2>Quality Gaps and Risks</h2>

        <div class="cards">
            <div class="card">
                <div class="card-number warning"><?php echo htmlspecialchars($overdueTasksCount); ?></div>
                <div class="card-label">Overdue Tasks</div>
            </div>

            <div class="card">
                <div class="card-number pending-color"><?php echo htmlspecialchars($pendingTasksCount); ?></div>
                <div class="card-label">Pending / In Progress Tasks</div>
            </div>

            <div class="card">
                <div class="card-number warning"><?php echo htmlspecialchars($kpiResultsBelowTarget); ?></div>
                <div class="card-label">KPI Results Below Target</div>
            </div>

            <div class="card">
                <div class="card-number success-color"><?php echo htmlspecialchars($approvedKpiResults); ?></div>
                <div class="card-label">Approved KPI Results</div>
            </div>
        </div>
    </div>

    <div class="section-panel">
        <h2>Reports Status</h2>

        <div class="cards">
            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalSemesterReports); ?></div>
                <div class="card-label">Total Semester Reports</div>
            </div>

            <div class="card">
                <div class="card-number pending-color"><?php echo htmlspecialchars($draftSemesterReports); ?></div>
                <div class="card-label">Draft Semester Reports</div>
            </div>

            <div class="card">
                <div class="card-number pending-color"><?php echo htmlspecialchars($underReviewSemesterReports); ?></div>
                <div class="card-label">Semester Reports Under Review</div>
            </div>

            <div class="card">
                <div class="card-number success-color"><?php echo htmlspecialchars($approvedSemesterReports); ?></div>
                <div class="card-label">Approved Semester Reports</div>
            </div>

            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalCourseReports); ?></div>
                <div class="card-label">Total Course Reports</div>
            </div>

            <div class="card">
                <div class="card-number pending-color"><?php echo htmlspecialchars($draftCourseReports); ?></div>
                <div class="card-label">Draft Course Reports</div>
            </div>

            <div class="card">
                <div class="card-number pending-color"><?php echo htmlspecialchars($submittedCourseReports); ?></div>
                <div class="card-label">Submitted Course Reports</div>
            </div>

            <div class="card">
                <div class="card-number success-color"><?php echo htmlspecialchars($approvedCourseReports); ?></div>
                <div class="card-label">Approved Course Reports</div>
            </div>
        </div>
    </div>

    <div class="section-panel">
        <h2>KPI Status</h2>

        <div class="cards">
            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalKpis); ?></div>
                <div class="card-label">Total KPIs</div>
            </div>

            <div class="card">
                <div class="card-number success-color"><?php echo htmlspecialchars($activeKpis); ?></div>
                <div class="card-label">Active KPIs</div>
            </div>

            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalKpiResults); ?></div>
                <div class="card-label">Total KPI Results</div>
            </div>

            <div class="card">
                <div class="card-number success-color"><?php echo htmlspecialchars($approvedKpiResults); ?></div>
                <div class="card-label">Approved KPI Results</div>
            </div>
        </div>
    </div>

    <div class="section-panel">
        <h2>KPI Achievement Monitoring</h2>

        <?php if (count($kpiAchievementRows) > 0): ?>
            <table>
                <tr>
                    <th>KPI Code</th>
                    <th>KPI Name</th>
                    <th>Responsible</th>
                    <th>Academic Period</th>
                    <th>Target</th>
                    <th>Actual</th>
                    <th>Achievement %</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>

                <?php foreach ($kpiAchievementRows as $row): ?>
                    <?php
                        $achievement = $row["achievement_percentage"] !== null ? (float)$row["achievement_percentage"] : null;
                        $achievementClass = getAchievementClass($achievement);
                        $achievementText = $achievement !== null ? $achievement . "%" : "No result";
                    ?>

                    <tr>
                        <td><strong><?php echo htmlspecialchars($row["kpi_code"]); ?></strong></td>
                        <td><?php echo htmlspecialchars($row["kpi_name"]); ?></td>
                        <td><?php echo htmlspecialchars($row["responsible_person"] ?? ""); ?></td>

                        <td>
                            <?php if (!empty($row["result_id"])): ?>
                                <?php echo htmlspecialchars($row["academic_year"]); ?><br>
                                <?php echo htmlspecialchars($row["semester"]); ?>
                            <?php else: ?>
                                <span class="warning-text">No result entered</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars(!empty($row["result_id"]) ? $row["target_value"] : $row["default_target"]); ?>
                            <?php echo htmlspecialchars($row["unit"] ?? ""); ?>
                        </td>

                        <td>
                            <?php if (!empty($row["result_id"])): ?>
                                <?php echo htmlspecialchars($row["actual_value"]); ?>
                                <?php echo htmlspecialchars($row["unit"] ?? ""); ?>
                            <?php else: ?>
                                <span class="warning-text">Not entered</span>
                            <?php endif; ?>
                        </td>

                        <td class="<?php echo htmlspecialchars($achievementClass); ?>">
                            <?php echo htmlspecialchars($achievementText); ?>
                        </td>

                        <td>
                            <?php if (!empty($row["result_id"])): ?>
                                <strong><?php echo htmlspecialchars($row["result_status"]); ?></strong>
                            <?php else: ?>
                                <span class="warning-text">Missing</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($row["result_id"])): ?>
                                <a href="edit_kpi_result.php?id=<?php echo htmlspecialchars($row["result_id"]); ?>">Edit Result</a>
                            <?php else: ?>
                                <a href="create_kpi_result.php?kpi_id=<?php echo htmlspecialchars($row["kpi_id"]); ?>">Add Result</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <div class="section-link">
                <a href="kpi_results.php">View all KPI results</a>
            </div>
        <?php else: ?>
            <p class="ok">No active KPIs found.</p>
        <?php endif; ?>
    </div>

    <div class="two-column">

        <div class="section-panel">
            <h2>Latest KPI Results</h2>

            <?php if (count($latestKpiResults) > 0): ?>
                <table>
                    <tr>
                        <th>KPI</th>
                        <th>Achievement</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>

                    <?php foreach ($latestKpiResults as $row): ?>
                        <?php
                            $achievement = (float)$row["achievement_percentage"];
                            $achievementClass = getAchievementClass($achievement);
                        ?>

                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($row["kpi_code"]); ?></strong><br>
                                <?php echo htmlspecialchars($row["kpi_name"]); ?>
                            </td>

                            <td class="<?php echo htmlspecialchars($achievementClass); ?>">
                                <?php echo htmlspecialchars($row["achievement_percentage"]); ?>%
                            </td>

                            <td><strong><?php echo htmlspecialchars($row["status"]); ?></strong></td>

                            <td>
                                <a href="edit_kpi_result.php?id=<?php echo htmlspecialchars($row["id"]); ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <div class="section-link">
                    <a href="kpi_results.php">View all KPI results</a>
                </div>
            <?php else: ?>
                <p class="ok">No KPI results entered yet.</p>
            <?php endif; ?>
        </div>

        <div class="section-panel">
            <h2>Latest Course Reports</h2>

            <?php if (count($latestCourseReports) > 0): ?>
                <table>
                    <tr>
                        <th>Course</th>
                        <th>Period</th>
                        <th>Pass Rate</th>
                        <th>Status</th>
                    </tr>

                    <?php foreach ($latestCourseReports as $report): ?>
                        <?php
                            $enrolled = (int)($report["students_enrolled"] ?? 0);
                            $passed = (int)($report["students_passed"] ?? 0);
                            $passRate = calcRate($passed, $enrolled);
                        ?>

                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($report["course_code"]); ?></strong><br>
                                <?php echo htmlspecialchars($report["course_title"]); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($report["academic_year"]); ?><br>
                                <?php echo htmlspecialchars($report["semester"]); ?>
                            </td>

                            <td><?php echo htmlspecialchars($passRate); ?>%</td>
                            <td><strong><?php echo htmlspecialchars($report["status"]); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <div class="section-link">
                    <a href="course_reports.php">View all course reports</a>
                </div>
            <?php else: ?>
                <p class="ok">No course reports created yet.</p>
            <?php endif; ?>
        </div>

    </div>

    <div class="section-panel">
        <h2>Latest Semester Program Reports</h2>

        <?php if (count($latestSemesterReports) > 0): ?>
            <table>
                <tr>
                    <th>Academic Year</th>
                    <th>Semester</th>
                    <th>Report Title</th>
                    <th>Prepared By</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>

                <?php foreach ($latestSemesterReports as $report): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($report["academic_year"]); ?></td>
                        <td><?php echo htmlspecialchars($report["semester"]); ?></td>
                        <td><strong><?php echo htmlspecialchars($report["report_title"]); ?></strong></td>
                        <td><?php echo htmlspecialchars($report["prepared_by"] ?? ""); ?></td>
                        <td><strong><?php echo htmlspecialchars($report["status"]); ?></strong></td>
                        <td>
                            <a href="view_semester_report.php?id=<?php echo htmlspecialchars($report["id"]); ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <div class="section-link">
                <a href="semester_reports.php">View all semester reports</a>
            </div>
        <?php else: ?>
            <p class="ok">No semester reports created yet.</p>
        <?php endif; ?>
    </div>

    <div class="two-column">

        <div class="section-panel">
            <h2>Pending / In Progress Tasks</h2>

            <?php if (count($pendingTaskList) > 0): ?>
                <table>
                    <tr>
                        <th>Task</th>
                        <th>Responsible</th>
                        <th>Due Date</th>
                        <th>Priority</th>
                    </tr>

                    <?php foreach ($pendingTaskList as $task): ?>
                        <tr>
                            <td>
                                <a href="edit_task.php?id=<?php echo htmlspecialchars($task["id"]); ?>">
                                    <?php echo htmlspecialchars($task["task_title"]); ?>
                                </a>
                            </td>

                            <td><?php echo htmlspecialchars($task["responsible_person"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars($task["due_date"] ?? "No due date"); ?></td>

                            <td>
                                <span class="<?php echo htmlspecialchars(getPriorityClass($task["priority"] ?? "")); ?>">
                                    <?php echo htmlspecialchars($task["priority"] ?? ""); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <div class="section-link">
                    <a href="tasks.php?status=Pending">View task list</a>
                </div>
            <?php else: ?>
                <p class="ok">No pending or in-progress tasks.</p>
            <?php endif; ?>
        </div>

        <div class="section-panel">
            <h2>Overdue Tasks</h2>

            <?php if (count($overdueTaskList) > 0): ?>
                <table>
                    <tr>
                        <th>Task</th>
                        <th>Responsible</th>
                        <th>Due Date</th>
                        <th>Priority</th>
                    </tr>

                    <?php foreach ($overdueTaskList as $task): ?>
                        <tr>
                            <td>
                                <a href="edit_task.php?id=<?php echo htmlspecialchars($task["id"]); ?>">
                                    <?php echo htmlspecialchars($task["task_title"]); ?>
                                </a>
                            </td>

                            <td><?php echo htmlspecialchars($task["responsible_person"] ?? ""); ?></td>
                            <td class="warning-text"><?php echo htmlspecialchars($task["due_date"]); ?></td>

                            <td>
                                <span class="<?php echo htmlspecialchars(getPriorityClass($task["priority"] ?? "")); ?>">
                                    <?php echo htmlspecialchars($task["priority"] ?? ""); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <div class="section-link">
                    <a href="tasks.php?overdue=1">View overdue tasks</a>
                </div>
            <?php else: ?>
                <p class="ok">No overdue tasks.</p>
            <?php endif; ?>
        </div>

    </div>

    <div class="section-panel">
        <h2>Task Summary</h2>

        <div class="two-column">
            <div>
                <h3>Tasks by Status</h3>
                <table>
                    <tr>
                        <th>Status</th>
                        <th>Total Tasks</th>
                    </tr>

                    <?php foreach ($taskStatusCounts as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row["status"]); ?></td>
                            <td><?php echo htmlspecialchars($row["total"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div>
                <h3>Tasks by Priority</h3>
                <table>
                    <tr>
                        <th>Priority</th>
                        <th>Total Tasks</th>
                    </tr>

                    <?php foreach ($taskPriorityCounts as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row["priority"]); ?></td>
                            <td><?php echo htmlspecialchars($row["total"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="section-panel">
        <h2>Evidence Summary</h2>

        <div class="two-column">
            <div>
                <h3>Evidence by Status</h3>
                <table>
                    <tr>
                        <th>Status</th>
                        <th>Total Evidence</th>
                    </tr>

                    <?php foreach ($statusCounts as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row["status"]); ?></td>
                            <td><?php echo htmlspecialchars($row["total"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div>
                <h3>Evidence by Review Status</h3>
                <table>
                    <tr>
                        <th>Review Status</th>
                        <th>Total Evidence</th>
                    </tr>

                    <?php foreach ($reviewCounts as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row["review_status"]); ?></td>
                            <td><?php echo htmlspecialchars($row["total"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="section-panel">
        <h2>Evidence Coverage by Standard</h2>

        <table>
            <tr>
                <th>Standard</th>
                <th>Title</th>
                <th>Total Evidence</th>
            </tr>

            <?php if (count($standardCounts) > 0): ?>
                <?php foreach ($standardCounts as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row["standard_code"]); ?></td>
                        <td><?php echo htmlspecialchars($row["standard_title"]); ?></td>
                        <td><?php echo htmlspecialchars($row["total_evidence"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="3">No standards found.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="two-column">

        <div class="section-panel">
            <h2>Substandards Without Evidence</h2>

            <?php if (count($missingSubstandards) > 0): ?>
                <table>
                    <tr>
                        <th>Standard</th>
                        <th>Substandard</th>
                        <th>Requirement</th>
                    </tr>

                    <?php foreach ($missingSubstandards as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row["standard_code"]); ?></td>
                            <td class="warning-text"><?php echo htmlspecialchars($row["substandard_code"]); ?></td>
                            <td><?php echo htmlspecialchars($row["substandard_text"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <div class="section-link">
                    <a href="index.php?status=Missing">Open evidence repository</a>
                </div>
            <?php else: ?>
                <p class="ok">All substandards have at least one evidence item.</p>
            <?php endif; ?>
        </div>

        <div class="section-panel">
            <h2>Evidence Needing Revision</h2>

            <?php if (count($needsRevision) > 0): ?>
                <table>
                    <tr>
                        <th>ID</th>
                        <th>Evidence Title</th>
                        <th>Reviewer Notes</th>
                        <th>Action</th>
                    </tr>

                    <?php foreach ($needsRevision as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row["id"]); ?></td>
                            <td><?php echo htmlspecialchars($row["title"]); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($row["reviewer_notes"] ?? "")); ?></td>
                            <td>
                                <a href="edit.php?id=<?php echo htmlspecialchars($row["id"]); ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <div class="section-link">
                    <a href="index.php?search=Needs+Revision">Open evidence repository</a>
                </div>
            <?php else: ?>
                <p class="ok">No evidence currently marked as needing revision.</p>
            <?php endif; ?>
        </div>

    </div>

</div>

</body>
</html>