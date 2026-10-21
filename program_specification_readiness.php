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

function isEmptyValue($value) {
    return $value === null || trim((string)$value) === "";
}

function addReadinessItem(&$items, $area, $status, $message, $action, $link) {
    $items[] = [
        "area" => $area,
        "status" => $status,
        "message" => $message,
        "action" => $action,
        "link" => $link
    ];
}

function statusClass($status) {
    if ($status === "Ready") {
        return "badge-ready";
    }

    if ($status === "Review") {
        return "badge-review";
    }

    return "badge-not-ready";
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
    "staffing" => countRows($conn, "program_specification_staffing_needs", $spec_id),
    "evidence_links" => countRows($conn, "program_specification_evidence_links", $spec_id)
];

$totalImportedCredits = sumColumn($conn, "program_specification_courses", "credit_hours", $spec_id);

/* Evidence section coverage */
$evidenceSections = [
    "Program Identity and General Information",
    "Program Mission and Goals",
    "Program Learning Outcomes",
    "Curriculum Structure",
    "Program Courses / Study Plan",
    "PLO-Course Mapping Matrix",
    "Teaching and Learning Strategies",
    "Assessment Methods and Mark Distribution",
    "Student Admission and Support",
    "Staffing Needs",
    "Learning Resources",
    "Facilities and Equipment",
    "Quality Assurance System",
    "Program Evaluation Matrix",
    "Program KPIs",
    "Tracks and Exit Points",
    "Approval and Governance Evidence"
];

$linkedEvidenceSections = 0;

if (tableExists($conn, "program_specification_evidence_links")) {
    $sectionStmt = $conn->prepare("
        SELECT DISTINCT section_name
        FROM program_specification_evidence_links
        WHERE program_specification_id = :id
    ");
    $sectionStmt->execute([":id" => $spec_id]);
    $linkedSections = $sectionStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($evidenceSections as $section) {
        if (in_array($section, $linkedSections, true)) {
            $linkedEvidenceSections++;
        }
    }
}

$totalEvidenceSections = count($evidenceSections);
$evidenceCoverage = $totalEvidenceSections > 0 ? round(($linkedEvidenceSections / $totalEvidenceSections) * 100, 1) : 0;

/* Readiness checks */
$items = [];

$coreMissing = 0;

$coreFields = [
    "specification_title",
    "program_name",
    "program_code",
    "qualification_level",
    "department_name",
    "college_name",
    "institution_name",
    "main_location",
    "total_credit_hours",
    "program_mission",
    "program_goals",
    "admission_requirements",
    "graduation_requirements",
    "teaching_learning_strategy",
    "assessment_strategy",
    "quality_assurance_system",
    "approval_council_committee",
    "approval_reference_no",
    "approval_date_text"
];

foreach ($coreFields as $field) {
    if (array_key_exists($field, $spec) && isEmptyValue($spec[$field])) {
        $coreMissing++;
    }
}

if ($coreMissing === 0) {
    addReadinessItem($items, "Main Specification Fields", "Ready", "All required main fields are completed.", "No action needed.", "edit_program_specification.php?id=" . $spec_id);
} else {
    addReadinessItem($items, "Main Specification Fields", "Not Ready", $coreMissing . " required main field(s) are missing.", "Complete missing main specification fields.", "edit_program_specification.php?id=" . $spec_id);
}

if (!empty($spec["study_plan_id"])) {
    addReadinessItem($items, "Study Plan Link", "Ready", "Program specification is linked to a study plan.", "No action needed.", "edit_program_specification.php?id=" . $spec_id);
} else {
    addReadinessItem($items, "Study Plan Link", "Not Ready", "No study plan is linked.", "Link the approved study plan.", "edit_program_specification.php?id=" . $spec_id);
}

addReadinessItem(
    $items,
    "Program Learning Outcomes",
    $counts["plos"] >= 9 ? "Ready" : ($counts["plos"] > 0 ? "Review" : "Not Ready"),
    "PLO count: " . $counts["plos"] . ". Expected at least 9.",
    "Import or complete PLOs.",
    "manage_program_specification_plos.php?id=" . $spec_id
);

addReadinessItem(
    $items,
    "Program Courses",
    $counts["courses"] >= 50 ? "Ready" : ($counts["courses"] > 0 ? "Review" : "Not Ready"),
    "Course count: " . $counts["courses"] . ". Expected 50 courses.",
    "Import courses from the approved study plan.",
    "import_program_specification_from_study_plan.php?id=" . $spec_id
);

$creditReady = ($declaredCredits > 0 && $totalImportedCredits > 0 && abs($declaredCredits - $totalImportedCredits) <= 0.01);

addReadinessItem(
    $items,
    "Credit Hour Match",
    $creditReady ? "Ready" : "Not Ready",
    "Declared credits: " . $declaredCredits . ". Imported course credits: " . $totalImportedCredits . ".",
    "Fix study plan credits or program total credits.",
    "program_specification_validation.php?id=" . $spec_id
);

addReadinessItem(
    $items,
    "PLO-Course Mapping",
    $counts["mapping"] > 0 ? "Ready" : "Not Ready",
    "Mapping records: " . $counts["mapping"] . ".",
    "Import default mapping or complete the mapping matrix.",
    "program_specification_plo_mapping.php?id=" . $spec_id
);

addReadinessItem(
    $items,
    "Program KPIs",
    $counts["kpis"] >= 11 ? "Ready" : ($counts["kpis"] > 0 ? "Review" : "Not Ready"),
    "KPI count: " . $counts["kpis"] . ". Expected at least 11 main KPIs.",
    "Import or complete program KPIs.",
    "manage_program_specification_kpis.php?id=" . $spec_id
);

addReadinessItem(
    $items,
    "Curriculum Structure",
    $counts["curriculum"] > 0 ? "Ready" : "Not Ready",
    "Curriculum structure rows: " . $counts["curriculum"] . ".",
    "Import default HPE curriculum structure.",
    "manage_program_specification_curriculum.php?id=" . $spec_id
);

addReadinessItem(
    $items,
    "Program Evaluation Matrix",
    $counts["evaluation"] > 0 ? "Ready" : "Not Ready",
    "Evaluation matrix rows: " . $counts["evaluation"] . ".",
    "Import default HPE evaluation matrix.",
    "manage_program_specification_evaluation.php?id=" . $spec_id
);

addReadinessItem(
    $items,
    "Teaching and Assessment",
    ($counts["teaching"] > 0 && $counts["assessment"] > 0 && $counts["marks"] > 0) ? "Ready" : "Review",
    "Teaching rows: " . $counts["teaching"] . ", assessment rows: " . $counts["assessment"] . ", mark rows: " . $counts["marks"] . ".",
    "Complete teaching strategies, assessment methods, and mark distribution.",
    "manage_program_specification_teaching_assessment.php?id=" . $spec_id
);

addReadinessItem(
    $items,
    "Staffing Needs",
    $counts["staffing"] > 0 ? "Ready" : "Review",
    "Staffing rows: " . $counts["staffing"] . ".",
    "Import or enter staffing needs.",
    "manage_program_specification_staffing.php?id=" . $spec_id
);

addReadinessItem(
    $items,
    "Tracks and Exit Points",
    ($counts["tracks"] > 0 && $counts["exit_points"] > 0) ? "Ready" : "Review",
    "Tracks: " . $counts["tracks"] . ", exit points: " . $counts["exit_points"] . ".",
    "Document tracks and exit points, or N/A where appropriate.",
    "manage_program_specification_tracks_exit_points.php?id=" . $spec_id
);

addReadinessItem(
    $items,
    "Evidence Coverage",
    $evidenceCoverage >= 100 ? "Ready" : ($evidenceCoverage > 0 ? "Review" : "Not Ready"),
    "Evidence section coverage: " . $evidenceCoverage . "% (" . $linkedEvidenceSections . "/" . $totalEvidenceSections . " sections).",
    "Link evidence items to all program specification sections.",
    "manage_program_specification_evidence.php?id=" . $spec_id
);

$readyCount = 0;
$reviewCount = 0;
$notReadyCount = 0;

foreach ($items as $item) {
    if ($item["status"] === "Ready") {
        $readyCount++;
    } elseif ($item["status"] === "Review") {
        $reviewCount++;
    } else {
        $notReadyCount++;
    }
}

$totalItems = count($items);
$readinessPercent = $totalItems > 0 ? round(($readyCount / $totalItems) * 100, 1) : 0;

$finalDecision = "Ready for Accreditation Review";
$finalClass = "badge-ready";

if ($notReadyCount > 0) {
    $finalDecision = "Not Ready";
    $finalClass = "badge-not-ready";
} elseif ($reviewCount > 0) {
    $finalDecision = "Ready with Minor Review";
    $finalClass = "badge-review";
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Program Specification Readiness</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(140px, 1fr));
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
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-ready {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-review {
            background: #fff3cd;
            color: #664d03;
        }

        .badge-not-ready {
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
            min-width: 1000px;
            width: 100%;
            border-collapse: collapse;
        }

        @media (max-width: 1100px) {
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
        <h1>Program Specification Readiness</h1>
        <p>Final go / no-go readiness review for accreditation use.</p>
    </div>

    <div class="actions">
        <a href="program_specification_dashboard.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">Dashboard</a>
        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">Back to Specification</a>
        <a href="program_specification_validation.php?id=<?php echo safeText($spec_id); ?>" class="btn">Validation</a>
        <a href="manage_program_specification_evidence.php?id=<?php echo safeText($spec_id); ?>" class="btn">Evidence Linkage</a>
        <a href="export_program_specification_word.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">Export Specification</a>
        <a href="export_program_specification_validation_word.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">Export Validation</a>
        <a href="export_program_specification_evidence_matrix_word.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">Export Evidence Matrix</a>
	<a href="export_program_specification_readiness_word.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Export Readiness
	</a>
    </div>

    <div class="panel">
        <h2>Readiness Decision</h2>

        <table>
            <tr>
                <th>Program Specification ID</th>
                <td><?php echo safeText($spec_id); ?></td>
                <th>Decision</th>
                <td>
                    <span class="badge <?php echo safeText($finalClass); ?>">
                        <?php echo safeText($finalDecision); ?>
                    </span>
                </td>
            </tr>

            <tr>
                <th>Specification</th>
                <td colspan="3"><?php echo safeText($spec["specification_title"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Program</th>
                <td><?php echo safeText($spec["program_name"] ?? ""); ?></td>
                <th>Program Code</th>
                <td><?php echo safeText($spec["program_code"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Declared Credits</th>
                <td><?php echo safeText($declaredCredits); ?></td>
                <th>Imported Course Credits</th>
                <td><?php echo safeText($totalImportedCredits); ?></td>
            </tr>
        </table>

        <h3>Readiness Completion</h3>
        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText($readinessPercent); ?>%;">
                <?php echo safeText($readinessPercent); ?>%
            </div>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($readyCount); ?></div>
            <div class="summary-label">Ready Areas</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($reviewCount); ?></div>
            <div class="summary-label">Need Review</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($notReadyCount); ?></div>
            <div class="summary-label">Not Ready</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($evidenceCoverage); ?>%</div>
            <div class="summary-label">Evidence Coverage</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($counts["courses"]); ?></div>
            <div class="summary-label">Imported Courses</div>
        </div>
    </div>

    <div class="panel">
        <h2>Readiness Areas</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Area</th>
                    <th>Status</th>
                    <th>Current Finding</th>
                    <th>Required Action</th>
                    <th>Fix Link</th>
                </tr>

                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo safeText($item["area"]); ?></td>
                        <td>
                            <span class="badge <?php echo safeText(statusClass($item["status"])); ?>">
                                <?php echo safeText($item["status"]); ?>
                            </span>
                        </td>
                        <td><?php echo safeText($item["message"]); ?></td>
                        <td><?php echo safeText($item["action"]); ?></td>
                        <td>
                            <a href="<?php echo safeText($item["link"]); ?>" class="btn">
                                Open
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
