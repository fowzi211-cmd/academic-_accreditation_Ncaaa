<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

// ===============================
// Helper functions
// ===============================
function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SHOW TABLES LIKE :table_name");
        $stmt->execute([":table_name" => $tableName]);
        return $stmt->fetch(PDO::FETCH_NUM) ? true : false;
    } catch (Exception $e) {
        return false;
    }
}

function columnExists($conn, $tableName, $columnName) {
    try {
        if (!tableExists($conn, $tableName)) {
            return false;
        }

        $stmt = $conn->prepare("SHOW COLUMNS FROM `$tableName` LIKE :column_name");
        $stmt->execute([":column_name" => $columnName]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ? true : false;
    } catch (Exception $e) {
        return false;
    }
}

function countRowsByReport($conn, $tableName, $reportId) {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    $fk = null;

    if (columnExists($conn, $tableName, "course_report_id")) {
        $fk = "course_report_id";
    } elseif (columnExists($conn, $tableName, "report_id")) {
        $fk = "report_id";
    }

    if ($fk === null) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM `$tableName`
        WHERE `$fk` = :report_id
    ");
    $stmt->execute([":report_id" => $reportId]);

    return (int)$stmt->fetchColumn();
}

function hasValue($row, $field) {
    return isset($row[$field]) && trim((string)$row[$field]) !== "";
}

function calcCompletionRate($conn, $report) {
    $reportId = (int)$report["id"];

    $gradeCount = countRowsByReport($conn, "course_report_grade_distribution", $reportId);
    $cloCount = countRowsByReport($conn, "course_report_clo_results", $reportId);
    $examCount = countRowsByReport($conn, "course_report_exam_alignment", $reportId);
    $topicsCount = countRowsByReport($conn, "course_report_topics_not_covered", $reportId);
    $previousActionsCount = countRowsByReport($conn, "course_report_previous_improvement_actions", $reportId);
    $improvementPlanCount = countRowsByReport($conn, "course_report_improvement_plan", $reportId);

    $checks = [];

    $checks[] = !empty($report["spec_id"]);

    $checks[] =
        hasValue($report, "course_code") &&
        hasValue($report, "course_title") &&
        hasValue($report, "academic_year") &&
        hasValue($report, "semester") &&
        hasValue($report, "course_coordinator");

    $checks[] =
        hasValue($report, "program_name") &&
        hasValue($report, "department_name") &&
        hasValue($report, "college_name") &&
        hasValue($report, "institution_name") &&
        hasValue($report, "location") &&
        hasValue($report, "number_of_sections") &&
        hasValue($report, "report_date");

    $checks[] =
        isset($report["students_enrolled"]) &&
        (int)$report["students_enrolled"] > 0 &&
        isset($report["students_passed"]) &&
        isset($report["students_failed"]);

    $checks[] = $gradeCount > 0;
    $checks[] = $cloCount > 0;
    $checks[] = $examCount > 0;
    $checks[] = $topicsCount > 0;
    $checks[] = $previousActionsCount > 0;
    $checks[] = hasValue($report, "student_feedback_summary");
    $checks[] = $improvementPlanCount > 0;
    $checks[] = hasValue($report, "general_notes");

    $total = count($checks);
    $complete = 0;

    foreach ($checks as $check) {
        if ($check) {
            $complete++;
        }
    }

    return $total > 0 ? round(($complete / $total) * 100, 1) : 0;
}

function completionClass($rate) {
    if ($rate >= 80) {
        return "completion-good";
    }

    if ($rate >= 50) {
        return "completion-warning";
    }

    return "completion-danger";
}

// ===============================
// Message from task creation page
// ===============================
$message = trim($_GET["message"] ?? "");

// ===============================
// Load reports
// ===============================
$stmt = $conn->query("
    SELECT 
        cr.*,
        cs.id AS spec_id,
        cs.course_code AS spec_course_code,
        cs.course_title AS spec_course_title,
        cs.specification_version AS spec_version
    FROM course_reports cr
    LEFT JOIN course_specifications cs
        ON cs.id = cr.course_specification_id
    ORDER BY cr.id DESC
");

$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Build follow-up list
// ===============================
$followups = [];

foreach ($reports as $report) {
    $reportId = (int)$report["id"];
    $courseLabel = trim(($report["course_code"] ?? "") . " - " . ($report["course_title"] ?? ""), " -");

    $completionRate = calcCompletionRate($conn, $report);

    $gradeCount = countRowsByReport($conn, "course_report_grade_distribution", $reportId);
    $cloCount = countRowsByReport($conn, "course_report_clo_results", $reportId);
    $examCount = countRowsByReport($conn, "course_report_exam_alignment", $reportId);
    $topicsCount = countRowsByReport($conn, "course_report_topics_not_covered", $reportId);
    $previousActionsCount = countRowsByReport($conn, "course_report_previous_improvement_actions", $reportId);
    $improvementPlanCount = countRowsByReport($conn, "course_report_improvement_plan", $reportId);

    if (empty($report["spec_id"])) {
        $followups[] = [
            "report_id" => $reportId,
            "course" => $courseLabel,
            "academic_year" => $report["academic_year"] ?? "",
            "semester" => $report["semester"] ?? "",
            "issue" => "Course Report is not linked to a valid Course Specification.",
            "recommended_action" => "Edit the Course Report and select the approved Course Specification.",
            "priority" => "High",
            "completion_rate" => $completionRate
        ];
    }

    if ($completionRate < 80) {
        $followups[] = [
            "report_id" => $reportId,
            "course" => $courseLabel,
            "academic_year" => $report["academic_year"] ?? "",
            "semester" => $report["semester"] ?? "",
            "issue" => "Course Report completion rate is below 80%.",
            "recommended_action" => "Open the Completion Check page and complete missing official sections.",
            "priority" => $completionRate < 50 ? "High" : "Medium",
            "completion_rate" => $completionRate
        ];
    }

    if ($cloCount <= 0) {
        $followups[] = [
            "report_id" => $reportId,
            "course" => $courseLabel,
            "academic_year" => $report["academic_year"] ?? "",
            "semester" => $report["semester"] ?? "",
            "issue" => "CLO actual achievement rows are missing.",
            "recommended_action" => "Import CLOs from the linked Course Specification or enter CLO achievement manually.",
            "priority" => "High",
            "completion_rate" => $completionRate
        ];
    }

    if ($examCount <= 0) {
        $followups[] = [
            "report_id" => $reportId,
            "course" => $courseLabel,
            "academic_year" => $report["academic_year"] ?? "",
            "semester" => $report["semester"] ?? "",
            "issue" => "Final exam alignment rows are missing.",
            "recommended_action" => "Import exam alignment from the Course Specification and add final exam question numbers.",
            "priority" => "High",
            "completion_rate" => $completionRate
        ];
    }

    if ($gradeCount <= 0) {
        $followups[] = [
            "report_id" => $reportId,
            "course" => $courseLabel,
            "academic_year" => $report["academic_year"] ?? "",
            "semester" => $report["semester"] ?? "",
            "issue" => "Grade distribution rows are missing.",
            "recommended_action" => "Enter total, female, and male grade distribution rows.",
            "priority" => "Medium",
            "completion_rate" => $completionRate
        ];
    }

    if ($topicsCount <= 0) {
        $followups[] = [
            "report_id" => $reportId,
            "course" => $courseLabel,
            "academic_year" => $report["academic_year"] ?? "",
            "semester" => $report["semester"] ?? "",
            "issue" => "Topics not covered section is missing.",
            "recommended_action" => "Add covered/not-covered topic evidence. If all topics were covered, add a row stating that.",
            "priority" => "Medium",
            "completion_rate" => $completionRate
        ];
    }

    if ($previousActionsCount <= 0) {
        $followups[] = [
            "report_id" => $reportId,
            "course" => $courseLabel,
            "academic_year" => $report["academic_year"] ?? "",
            "semester" => $report["semester"] ?? "",
            "issue" => "Previous improvement actions are missing.",
            "recommended_action" => "Enter previous recommendations/actions and achievement percentage.",
            "priority" => "Medium",
            "completion_rate" => $completionRate
        ];
    }

    if (!hasValue($report, "student_feedback_summary")) {
        $followups[] = [
            "report_id" => $reportId,
            "course" => $courseLabel,
            "academic_year" => $report["academic_year"] ?? "",
            "semester" => $report["semester"] ?? "",
            "issue" => "Student feedback summary is missing.",
            "recommended_action" => "Enter student feedback summary and key findings.",
            "priority" => "Medium",
            "completion_rate" => $completionRate
        ];
    }

    if ($improvementPlanCount <= 0) {
        $followups[] = [
            "report_id" => $reportId,
            "course" => $courseLabel,
            "academic_year" => $report["academic_year"] ?? "",
            "semester" => $report["semester"] ?? "",
            "issue" => "Course improvement plan is missing.",
            "recommended_action" => "Add improvement recommendations, actions, and needed support.",
            "priority" => "High",
            "completion_rate" => $completionRate
        ];
    }
}

// Sort high priority first, then lowest completion
usort($followups, function($a, $b) {
    $priorityOrder = ["High" => 1, "Medium" => 2, "Low" => 3];

    $pa = $priorityOrder[$a["priority"]] ?? 9;
    $pb = $priorityOrder[$b["priority"]] ?? 9;

    if ($pa === $pb) {
        return $a["completion_rate"] <=> $b["completion_rate"];
    }

    return $pa <=> $pb;
});

$highCount = 0;

foreach ($followups as $f) {
    if ($f["priority"] === "High") {
        $highCount++;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Report Follow-up Actions</title>
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

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .summary-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
        }

        .summary-number {
            font-size: 28px;
            font-weight: bold;
            color: #007bff;
        }

        .summary-label {
            color: #555;
            font-size: 13px;
            margin-top: 6px;
        }

        .priority-high {
            background: #f8d7da;
            color: #721c24;
            padding: 5px 9px;
            border-radius: 999px;
            font-weight: bold;
            display: inline-block;
        }

        .priority-medium {
            background: #fff3cd;
            color: #856404;
            padding: 5px 9px;
            border-radius: 999px;
            font-weight: bold;
            display: inline-block;
        }

        .priority-low {
            background: #d4edda;
            color: #155724;
            padding: 5px 9px;
            border-radius: 999px;
            font-weight: bold;
            display: inline-block;
        }

        .completion-good {
            background: #d4edda;
            color: #155724;
            padding: 5px 9px;
            border-radius: 999px;
            font-weight: bold;
            display: inline-block;
        }

        .completion-warning {
            background: #fff3cd;
            color: #856404;
            padding: 5px 9px;
            border-radius: 999px;
            font-weight: bold;
            display: inline-block;
        }

        .completion-danger {
            background: #f8d7da;
            color: #721c24;
            padding: 5px 9px;
            border-radius: 999px;
            font-weight: bold;
            display: inline-block;
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

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .action-buttons a {
            display: inline-block;
            margin-bottom: 4px;
        }

        @media (max-width: 1000px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(140px, 1fr));
            }
        }

        @media (max-width: 700px) {
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
        <h1>Course Report Follow-up Actions</h1>
        <p>Identify weak or incomplete Course Reports and create follow-up tasks for responsible users.</p>
    </div>

    <div class="actions">
        <a href="course_report_dashboard.php" class="btn btn-purple">Course Report Dashboard</a>
        <a href="course_reports.php" class="btn btn-secondary">Back to Course Reports</a>
    </div>

    <?php if ($message !== ""): ?>
        <div class="alert-success"><?php echo safeText($message); ?></div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($followups)); ?></div>
            <div class="summary-label">Total Follow-up Issues</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($highCount); ?></div>
            <div class="summary-label">High Priority Issues</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($reports)); ?></div>
            <div class="summary-label">Course Reports Checked</div>
        </div>
    </div>

    <div class="panel">
        <h2>Follow-up Issues</h2>

        <table>
            <tr>
                <th>No.</th>
                <th>Course Report</th>
                <th>Issue</th>
                <th>Recommended Action</th>
                <th>Priority</th>
                <th>Completion</th>
                <th>Actions</th>
            </tr>

            <?php if (count($followups) > 0): ?>
                <?php foreach ($followups as $index => $item): ?>
                    <?php
                        $priorityClass = "priority-medium";

                        if ($item["priority"] === "High") {
                            $priorityClass = "priority-high";
                        } elseif ($item["priority"] === "Low") {
                            $priorityClass = "priority-low";
                        }

                        $completionCssClass = completionClass($item["completion_rate"]);
                    ?>
                    <tr>
                        <td><?php echo safeText($index + 1); ?></td>

                        <td>
                            <strong><?php echo safeText($item["course"]); ?></strong><br>
                            <span class="small-text">
                                ID: <?php echo safeText($item["report_id"]); ?> |
                                <?php echo safeText($item["academic_year"]); ?> |
                                <?php echo safeText($item["semester"]); ?>
                            </span>
                        </td>

                        <td><?php echo safeText($item["issue"]); ?></td>

                        <td><?php echo safeText($item["recommended_action"]); ?></td>

                        <td>
                            <span class="<?php echo safeText($priorityClass); ?>">
                                <?php echo safeText($item["priority"]); ?>
                            </span>
                        </td>

                        <td>
                            <span class="<?php echo safeText($completionCssClass); ?>">
                                <?php echo safeText($item["completion_rate"]); ?>%
                            </span>
                        </td>

                        <td class="action-buttons">
                            <a href="course_report_completion_check.php?id=<?php echo safeText($item["report_id"]); ?>" class="btn">
                                Check
                            </a>

                            <a href="manage_course_report_details.php?id=<?php echo safeText($item["report_id"]); ?>" class="btn btn-edit">
                                Details
                            </a>

                            <a href="create_course_report_followup_task.php?report_id=<?php echo safeText($item["report_id"]); ?>&issue=<?php echo urlencode($item["issue"]); ?>&recommended_action=<?php echo urlencode($item["recommended_action"]); ?>&priority=<?php echo urlencode($item["priority"]); ?>&completion_rate=<?php echo urlencode($item["completion_rate"]); ?>" class="btn">
                                Create Task
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7">No follow-up issues found. Course Reports look complete based on current checks.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>