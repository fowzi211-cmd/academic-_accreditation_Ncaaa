<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

$spec_id = isset($_GET["id"]) && is_numeric($_GET["id"]) ? (int)$_GET["id"] : 3;
requireProgramSpecificationAccess($conn, $spec_id);

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

function countRows($conn, $tableName, $spec_id) {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    if (!columnExists($conn, $tableName, "program_specification_id")) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM $tableName
        WHERE program_specification_id = :id
    ");
    $stmt->execute([":id" => $spec_id]);
    return (int)$stmt->fetchColumn();
}

function sumColumn($conn, $tableName, $columnName, $spec_id) {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    if (!columnExists($conn, $tableName, $columnName)) {
        return 0;
    }

    if (!columnExists($conn, $tableName, "program_specification_id")) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM($columnName), 0)
        FROM $tableName
        WHERE program_specification_id = :id
    ");
    $stmt->execute([":id" => $spec_id]);
    return (float)$stmt->fetchColumn();
}

function badgeClass($status) {
    if ($status === "Complete") {
        return "badge-complete";
    }

    if ($status === "Partial") {
        return "badge-partial";
    }

    return "badge-missing";
}

function statusByCount($count) {
    return $count > 0 ? "Complete" : "Missing";
}

function statusByMinimum($count, $minimum) {
    if ($count >= $minimum) {
        return "Complete";
    }

    if ($count > 0) {
        return "Partial";
    }

    return "Missing";
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

$statusSelect = "NULL AS approval_status_value";

if (columnExists($conn, "program_specifications", "approval_status")) {
    $statusSelect = "approval_status AS approval_status_value";
} elseif (columnExists($conn, "program_specifications", "status")) {
    $statusSelect = "status AS approval_status_value";
}

$stmt = $conn->prepare("
    SELECT *, $statusSelect
    FROM program_specifications
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $spec_id]);
$spec = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$spec) {
    die("Program specification not found.");
}

$declaredCredits = (float)($spec["total_credit_hours"] ?? 0);

$counts = [
    "plos" => countRows($conn, "program_specification_plos", $spec_id),
    "courses" => countRows($conn, "program_specification_courses", $spec_id),
    "mapping" => countRows($conn, "program_specification_plo_course_mapping", $spec_id),
    "kpis" => countRows($conn, "program_specification_kpis", $spec_id),
    "curriculum" => countRows($conn, "program_specification_curriculum_structure", $spec_id),
    "evaluation" => countRows($conn, "program_specification_evaluation_matrix", $spec_id),
    "tracks" => countRows($conn, "program_specification_tracks", $spec_id),
    "exit_points" => countRows($conn, "program_specification_exit_points", $spec_id),
    "teaching" => countRows($conn, "program_specification_teaching_strategies", $spec_id),
    "assessment" => countRows($conn, "program_specification_assessment_methods", $spec_id),
    "marks" => countRows($conn, "program_specification_mark_distributions", $spec_id),
    "staffing" => countRows($conn, "program_specification_staffing_needs", $spec_id)
];

$totalImportedCredits = sumColumn($conn, "program_specification_courses", "credit_hours", $spec_id);

$creditStatus = "Missing";
if ($declaredCredits > 0 && $totalImportedCredits > 0) {
    $creditStatus = abs($declaredCredits - $totalImportedCredits) <= 0.01 ? "Complete" : "Partial";
}

$moduleStatuses = [
    [
        "module" => "Core Program Specification",
        "count" => "Main record",
        "status" => "Complete",
        "link" => "program_specification_profile.php?id=" . $spec_id,
        "action" => "Open Profile"
    ],
    [
        "module" => "Program Learning Outcomes",
        "count" => $counts["plos"],
        "status" => statusByMinimum($counts["plos"], 9),
        "link" => "manage_program_specification_plos.php?id=" . $spec_id,
        "action" => "Manage PLOs"
    ],
    [
        "module" => "Program Courses",
        "count" => $counts["courses"],
        "status" => statusByMinimum($counts["courses"], 50),
        "link" => "import_program_specification_from_study_plan.php?id=" . $spec_id,
        "action" => "Import Courses"
    ],
    [
        "module" => "PLO-Course Mapping",
        "count" => $counts["mapping"],
        "status" => statusByCount($counts["mapping"]),
        "link" => "program_specification_plo_mapping.php?id=" . $spec_id,
        "action" => "Open Mapping"
    ],
    [
        "module" => "Program KPIs",
        "count" => $counts["kpis"],
        "status" => statusByMinimum($counts["kpis"], 11),
        "link" => "manage_program_specification_kpis.php?id=" . $spec_id,
        "action" => "Manage KPIs"
    ],
    [
        "module" => "Curriculum Structure",
        "count" => $counts["curriculum"],
        "status" => statusByMinimum($counts["curriculum"], 8),
        "link" => "manage_program_specification_curriculum.php?id=" . $spec_id,
        "action" => "Manage Curriculum"
    ],
    [
        "module" => "Evaluation Matrix",
        "count" => $counts["evaluation"],
        "status" => statusByMinimum($counts["evaluation"], 8),
        "link" => "manage_program_specification_evaluation.php?id=" . $spec_id,
        "action" => "Manage Evaluation"
    ],
    [
        "module" => "Teaching Strategies",
        "count" => $counts["teaching"],
        "status" => statusByCount($counts["teaching"]),
        "link" => "manage_program_specification_teaching_assessment.php?id=" . $spec_id,
        "action" => "Teaching / Assessment"
    ],
    [
        "module" => "Assessment Methods",
        "count" => $counts["assessment"],
        "status" => statusByCount($counts["assessment"]),
        "link" => "manage_program_specification_teaching_assessment.php?id=" . $spec_id,
        "action" => "Teaching / Assessment"
    ],
    [
        "module" => "Mark Distribution",
        "count" => $counts["marks"],
        "status" => statusByCount($counts["marks"]),
        "link" => "manage_program_specification_teaching_assessment.php?id=" . $spec_id,
        "action" => "Teaching / Assessment"
    ],
    [
        "module" => "Staffing Needs",
        "count" => $counts["staffing"],
        "status" => statusByCount($counts["staffing"]),
        "link" => "manage_program_specification_staffing.php?id=" . $spec_id,
        "action" => "Manage Staffing"
    ],
    [
        "module" => "Tracks / Pathways",
        "count" => $counts["tracks"],
        "status" => statusByCount($counts["tracks"]),
        "link" => "manage_program_specification_tracks_exit_points.php?id=" . $spec_id,
        "action" => "Tracks / Exit Points"
    ],
    [
        "module" => "Exit Points",
        "count" => $counts["exit_points"],
        "status" => statusByCount($counts["exit_points"]),
        "link" => "manage_program_specification_tracks_exit_points.php?id=" . $spec_id,
        "action" => "Tracks / Exit Points"
    ],
    [
        "module" => "Credit Hour Match",
        "count" => $totalImportedCredits . " / " . $declaredCredits,
        "status" => $creditStatus,
        "link" => "program_specification_validation.php?id=" . $spec_id,
        "action" => "Validate"
    ]
];

$completeCount = 0;
$partialCount = 0;
$missingCount = 0;

foreach ($moduleStatuses as $row) {
    if ($row["status"] === "Complete") {
        $completeCount++;
    } elseif ($row["status"] === "Partial") {
        $partialCount++;
    } else {
        $missingCount++;
    }
}

$totalModules = count($moduleStatuses);
$completionPercent = $totalModules > 0 ? round(($completeCount / $totalModules) * 100, 1) : 0;

$overallStatus = "Ready";
if ($missingCount > 0) {
    $overallStatus = "Incomplete";
} elseif ($partialCount > 0) {
    $overallStatus = "Needs Review";
}

$overallClass = "badge-complete";
if ($overallStatus === "Incomplete") {
    $overallClass = "badge-missing";
} elseif ($overallStatus === "Needs Review") {
    $overallClass = "badge-partial";
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Program Specification Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(140px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .dashboard-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
        }

        .dashboard-number {
            font-size: 26px;
            font-weight: bold;
            color: #007bff;
        }

        .dashboard-label {
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

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-complete {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-partial {
            background: #fff3cd;
            color: #664d03;
        }

        .badge-missing {
            background: #f8d7da;
            color: #842029;
        }

        .progress-wrap {
            background: #e9ecef;
            border-radius: 999px;
            height: 24px;
            overflow: hidden;
            border: 1px solid #ccc;
        }

        .progress-bar {
            height: 24px;
            background: #0d6efd;
            color: white;
            text-align: center;
            line-height: 24px;
            font-weight: bold;
            font-size: 12px;
        }

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .wide-table {
            min-width: 950px;
            width: 100%;
            border-collapse: collapse;
        }

        .quick-links {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 10px;
        }

        .quick-links a {
            text-align: center;
            padding: 12px;
        }

        @media (max-width: 1100px) {
            .dashboard-grid,
            .quick-links {
                grid-template-columns: repeat(2, minmax(140px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .dashboard-grid,
            .quick-links {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Program Specification Dashboard</h1>
        <p>Control panel for the full program specification record and all linked structured tables.</p>
    </div>

    <div class="actions">
	<a href="program_specification_readiness.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Readiness
</a>
	<a href="course_specification_workflow.php?program_specification_id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Course Spec Workflow
</a>
        <a href="program_specifications.php" class="btn btn-secondary">Back to Program Specifications</a>
        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn">Profile</a>
        <a href="program_specification_validation.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">Validate</a>
        <a href="export_program_specification_word.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">Export Word</a>
        <a href="edit_program_specification.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-edit">Edit Main Specification</a>
	<a href="export_program_specification_validation_word.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Export Validation Report
</a>
	<a href="manage_program_specification_evidence.php?id=<?php echo safeText($spec_id); ?>" class="btn">
    Evidence Linkage
</a>
	<a href="program_specification_readiness.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Readiness Review
</a>
	<a href="export_program_specification_readiness_word.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Export Readiness
</a>
	<a href="program_specification_exports.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Exports
</a>
	<a href="program_specification_archive.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Archive
</a>
	<a href="course_specification_import_status.php?program_specification_id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Course Spec Import Status
</a>
	<a href="course_specification_export_center.php?program_specification_id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Course Spec Export Center
</a>
	<a href="course_specification_package_dashboard.php?program_specification_id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Course Spec Package Dashboard
</a>

<a href="course_specification_import_status.php?program_specification_id=<?php echo safeText($spec_id); ?>" class="btn">
    Course Spec Import Status
</a>

<a href="course_specification_export_center.php?program_specification_id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Course Spec Export Center
</a>

<a href="course_specification_archive.php?program_specification_id=<?php echo safeText($spec_id); ?>" class="btn">
    Course Spec Archive
</a>

    </div>

    <div class="panel">
        <h2>Program Specification</h2>

        <table>
            <tr>
                <th>Specification ID</th>
                <td><?php echo safeText($spec_id); ?></td>
                <th>Status</th>
                <td>
                    <span class="badge <?php echo safeText($overallClass); ?>">
                        <?php echo safeText($overallStatus); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>Specification Title</th>
                <td colspan="3"><?php echo safeText($spec["specification_title"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Program Name</th>
                <td><?php echo safeText($spec["program_name"] ?? ""); ?></td>
                <th>Program Code</th>
                <td><?php echo safeText($spec["program_code"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Qualification Level</th>
                <td><?php echo safeText($spec["qualification_level"] ?? ""); ?></td>
                <th>Approval Status</th>
                <td><?php echo safeText($spec["approval_status_value"] ?? ""); ?></td>
            </tr>
        </table>

        <h3>Overall Completion</h3>
        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText($completionPercent); ?>%;">
                <?php echo safeText($completionPercent); ?>%
            </div>
        </div>
    </div>

    <div class="dashboard-grid">
        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($counts["plos"]); ?></div>
            <div class="dashboard-label">PLOs</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($counts["courses"]); ?></div>
            <div class="dashboard-label">Courses</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($counts["mapping"]); ?></div>
            <div class="dashboard-label">PLO Mappings</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($counts["kpis"]); ?></div>
            <div class="dashboard-label">KPIs</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($counts["curriculum"]); ?></div>
            <div class="dashboard-label">Curriculum Rows</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($counts["evaluation"]); ?></div>
            <div class="dashboard-label">Evaluation Rows</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($counts["teaching"]); ?></div>
            <div class="dashboard-label">Teaching Strategies</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($counts["assessment"]); ?></div>
            <div class="dashboard-label">Assessment Methods</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($counts["staffing"]); ?></div>
            <div class="dashboard-label">Staffing Rows</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($totalImportedCredits); ?></div>
            <div class="dashboard-label">Imported Credits</div>
        </div>
    </div>

    <div class="panel">
        <h2>Quick Links</h2>

        <div class="quick-links">
            <a href="manage_program_specification_plos.php?id=<?php echo safeText($spec_id); ?>" class="btn">Manage PLOs</a>
            <a href="manage_program_specification_kpis.php?id=<?php echo safeText($spec_id); ?>" class="btn">Manage KPIs</a>
            <a href="import_program_specification_from_study_plan.php?id=<?php echo safeText($spec_id); ?>" class="btn">Import Courses</a>
            <a href="program_specification_plo_mapping.php?id=<?php echo safeText($spec_id); ?>" class="btn">PLO Mapping</a>

            <a href="manage_program_specification_curriculum.php?id=<?php echo safeText($spec_id); ?>" class="btn">Curriculum Structure</a>
            <a href="manage_program_specification_evaluation.php?id=<?php echo safeText($spec_id); ?>" class="btn">Evaluation Matrix</a>
            <a href="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>" class="btn">Teaching / Assessment</a>
            <a href="manage_program_specification_staffing.php?id=<?php echo safeText($spec_id); ?>" class="btn">Staffing Needs</a>

            <a href="manage_program_specification_tracks_exit_points.php?id=<?php echo safeText($spec_id); ?>" class="btn">Tracks / Exit Points</a>
            <a href="program_specification_validation.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">Validation</a>
            <a href="export_program_specification_word.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">Export Word</a>
            <a href="edit_program_specification.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-edit">Edit Main Record</a>
		<a href="program_specification_exports.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">Export Center</a>
	<a href="program_specification_archive.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">Archive Records</a>
	<a href="course_specification_validation.php?program_specification_id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">    Course Spec Validation</a>
	<a href="link_course_reports_to_specifications.php?program_specification_id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">Link Course Reports</a>
	<a href="course_report_workflow.php?program_specification_id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Course Report Workflow
</a>
		<a href="course_report_workflow.php?program_specification_id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Course Report Workflow
</a>
        </div>
    </div>

    <div class="panel">
        <h2>Module Status</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Module</th>
                    <th>Count / Value</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>

                <?php foreach ($moduleStatuses as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["module"]); ?></td>
                        <td><?php echo safeText($row["count"]); ?></td>
                        <td>
                            <span class="badge <?php echo safeText(badgeClass($row["status"])); ?>">
                                <?php echo safeText($row["status"]); ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?php echo safeText($row["link"]); ?>" class="btn">
                                <?php echo safeText($row["action"]); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
