<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

$spec_id = isset($_GET["id"]) && is_numeric($_GET["id"]) ? (int)$_GET["id"] : 3;

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
    if (!tableExists($conn, $tableName)) return 0;
    if (!columnExists($conn, $tableName, "program_specification_id")) return 0;

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM $tableName
        WHERE program_specification_id = :id
    ");
    $stmt->execute([":id" => $spec_id]);
    return (int)$stmt->fetchColumn();
}

function sumColumn($conn, $tableName, $columnName, $spec_id) {
    if (!tableExists($conn, $tableName)) return 0;
    if (!columnExists($conn, $tableName, $columnName)) return 0;
    if (!columnExists($conn, $tableName, "program_specification_id")) return 0;

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

function addReadinessItem(&$items, $area, $status, $message, $action) {
    $items[] = [
        "area" => $area,
        "status" => $status,
        "message" => $message,
        "action" => $action
    ];
}

function statusClass($status) {
    if ($status === "Ready") return "ready";
    if ($status === "Review") return "review";
    return "not-ready";
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

$items = [];

addReadinessItem(
    $items,
    "Main Specification Fields",
    $coreMissing === 0 ? "Ready" : "Not Ready",
    $coreMissing === 0 ? "All required main fields are completed." : $coreMissing . " required main field(s) are missing.",
    $coreMissing === 0 ? "No action needed." : "Complete missing main specification fields."
);

addReadinessItem(
    $items,
    "Study Plan Link",
    !empty($spec["study_plan_id"]) ? "Ready" : "Not Ready",
    !empty($spec["study_plan_id"]) ? "Program specification is linked to a study plan." : "No study plan is linked.",
    !empty($spec["study_plan_id"]) ? "No action needed." : "Link the approved study plan."
);

addReadinessItem(
    $items,
    "Program Learning Outcomes",
    $counts["plos"] >= 9 ? "Ready" : ($counts["plos"] > 0 ? "Review" : "Not Ready"),
    "PLO count: " . $counts["plos"] . ". Expected at least 9.",
    "Import or complete PLOs."
);

addReadinessItem(
    $items,
    "Program Courses",
    $counts["courses"] >= 50 ? "Ready" : ($counts["courses"] > 0 ? "Review" : "Not Ready"),
    "Course count: " . $counts["courses"] . ". Expected 50 courses.",
    "Import courses from the approved study plan."
);

$creditReady = ($declaredCredits > 0 && $totalImportedCredits > 0 && abs($declaredCredits - $totalImportedCredits) <= 0.01);

addReadinessItem(
    $items,
    "Credit Hour Match",
    $creditReady ? "Ready" : "Not Ready",
    "Declared credits: " . $declaredCredits . ". Imported course credits: " . $totalImportedCredits . ".",
    "Fix study plan credits or program total credits."
);

addReadinessItem(
    $items,
    "PLO-Course Mapping",
    $counts["mapping"] > 0 ? "Ready" : "Not Ready",
    "Mapping records: " . $counts["mapping"] . ".",
    "Import default mapping or complete the mapping matrix."
);

addReadinessItem(
    $items,
    "Program KPIs",
    $counts["kpis"] >= 11 ? "Ready" : ($counts["kpis"] > 0 ? "Review" : "Not Ready"),
    "KPI count: " . $counts["kpis"] . ". Expected at least 11 main KPIs.",
    "Import or complete program KPIs."
);

addReadinessItem(
    $items,
    "Curriculum Structure",
    $counts["curriculum"] > 0 ? "Ready" : "Not Ready",
    "Curriculum structure rows: " . $counts["curriculum"] . ".",
    "Import or complete curriculum structure."
);

addReadinessItem(
    $items,
    "Program Evaluation Matrix",
    $counts["evaluation"] > 0 ? "Ready" : "Not Ready",
    "Evaluation matrix rows: " . $counts["evaluation"] . ".",
    "Import or complete evaluation matrix."
);

addReadinessItem(
    $items,
    "Teaching and Assessment",
    ($counts["teaching"] > 0 && $counts["assessment"] > 0 && $counts["marks"] > 0) ? "Ready" : "Review",
    "Teaching rows: " . $counts["teaching"] . ", assessment rows: " . $counts["assessment"] . ", mark rows: " . $counts["marks"] . ".",
    "Complete teaching strategies, assessment methods, and mark distribution."
);

addReadinessItem(
    $items,
    "Staffing Needs",
    $counts["staffing"] > 0 ? "Ready" : "Review",
    "Staffing rows: " . $counts["staffing"] . ".",
    "Import or enter staffing needs."
);

addReadinessItem(
    $items,
    "Tracks and Exit Points",
    ($counts["tracks"] > 0 && $counts["exit_points"] > 0) ? "Ready" : "Review",
    "Tracks: " . $counts["tracks"] . ", exit points: " . $counts["exit_points"] . ".",
    "Document tracks and exit points, or N/A where appropriate."
);

addReadinessItem(
    $items,
    "Evidence Coverage",
    $evidenceCoverage >= 100 ? "Ready" : ($evidenceCoverage > 0 ? "Review" : "Not Ready"),
    "Evidence section coverage: " . $evidenceCoverage . "% (" . $linkedEvidenceSections . "/" . $totalEvidenceSections . " sections).",
    "Link evidence items to all program specification sections."
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
$finalClass = "ready";

if ($notReadyCount > 0) {
    $finalDecision = "Not Ready";
    $finalClass = "not-ready";
} elseif ($reviewCount > 0) {
    $finalDecision = "Ready with Minor Review";
    $finalClass = "review";
}

$filenameBase = $spec["program_code"] ?? "program_specification_readiness";
$filenameBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filenameBase);
$filename = "program_specification_readiness_" . $filenameBase . "_" . date("Y-m-d_H-i-s") . ".doc";

header("Content-Type: application/msword; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Program Specification Readiness Report</title>

    <style>
        body {
            font-family: Arial, Tahoma, sans-serif;
            font-size: 11pt;
            line-height: 1.45;
            color: #111;
        }

        h1 {
            text-align: center;
            font-size: 20pt;
            margin-bottom: 5px;
        }

        h2 {
            background: #d9eaf7;
            border: 1px solid #9dbbd4;
            padding: 8px;
            font-size: 15pt;
            margin-top: 25px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 14px;
        }

        th {
            background: #f2f2f2;
            font-weight: bold;
        }

        th, td {
            border: 1px solid #555;
            padding: 6px;
            vertical-align: top;
        }

        .center {
            text-align: center;
        }

        .ready {
            background: #d1e7dd;
            color: #0f5132;
            font-weight: bold;
        }

        .review {
            background: #fff3cd;
            color: #664d03;
            font-weight: bold;
        }

        .not-ready {
            background: #f8d7da;
            color: #842029;
            font-weight: bold;
        }

        .cover {
            text-align: center;
            margin-top: 70px;
            margin-bottom: 80px;
        }

        .cover h1 {
            font-size: 24pt;
        }

        .cover p {
            font-size: 14pt;
        }
    </style>
</head>

<body>

<div class="cover">
    <h1>Program Specification Readiness Report</h1>
    <p><strong><?php echo safeText($spec["program_name"] ?? ""); ?></strong></p>
    <p><?php echo safeText($spec["college_name"] ?? ""); ?></p>
    <p><?php echo safeText($spec["institution_name"] ?? ""); ?></p>
    <p>Generated on: <?php echo date("Y-m-d H:i"); ?></p>
</div>

<h2>1. Readiness Decision</h2>

<table>
    <tr>
        <th>Program Specification ID</th>
        <td><?php echo safeText($spec_id); ?></td>
        <th>Final Decision</th>
        <td class="<?php echo safeText($finalClass); ?>"><?php echo safeText($finalDecision); ?></td>
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
    <tr>
        <th>Declared Credits</th>
        <td><?php echo safeText($declaredCredits); ?></td>
        <th>Imported Course Credits</th>
        <td><?php echo safeText($totalImportedCredits); ?></td>
    </tr>
</table>

<h2>2. Readiness Summary</h2>

<table>
    <tr>
        <th>Total Areas</th>
        <th>Ready</th>
        <th>Need Review</th>
        <th>Not Ready</th>
        <th>Readiness %</th>
        <th>Evidence Coverage %</th>
    </tr>
    <tr>
        <td class="center"><?php echo safeText($totalItems); ?></td>
        <td class="center ready"><?php echo safeText($readyCount); ?></td>
        <td class="center review"><?php echo safeText($reviewCount); ?></td>
        <td class="center not-ready"><?php echo safeText($notReadyCount); ?></td>
        <td class="center"><?php echo safeText($readinessPercent); ?>%</td>
        <td class="center"><?php echo safeText($evidenceCoverage); ?>%</td>
    </tr>
</table>

<h2>3. Module Counts</h2>

<table>
    <tr>
        <th>Module</th>
        <th>Count / Value</th>
    </tr>
    <tr><td>PLOs</td><td><?php echo safeText($counts["plos"]); ?></td></tr>
    <tr><td>Courses</td><td><?php echo safeText($counts["courses"]); ?></td></tr>
    <tr><td>PLO Mapping Records</td><td><?php echo safeText($counts["mapping"]); ?></td></tr>
    <tr><td>KPIs</td><td><?php echo safeText($counts["kpis"]); ?></td></tr>
    <tr><td>Curriculum Structure Rows</td><td><?php echo safeText($counts["curriculum"]); ?></td></tr>
    <tr><td>Evaluation Matrix Rows</td><td><?php echo safeText($counts["evaluation"]); ?></td></tr>
    <tr><td>Teaching Strategy Rows</td><td><?php echo safeText($counts["teaching"]); ?></td></tr>
    <tr><td>Assessment Method Rows</td><td><?php echo safeText($counts["assessment"]); ?></td></tr>
    <tr><td>Mark Distribution Rows</td><td><?php echo safeText($counts["marks"]); ?></td></tr>
    <tr><td>Staffing Rows</td><td><?php echo safeText($counts["staffing"]); ?></td></tr>
    <tr><td>Tracks</td><td><?php echo safeText($counts["tracks"]); ?></td></tr>
    <tr><td>Exit Points</td><td><?php echo safeText($counts["exit_points"]); ?></td></tr>
    <tr><td>Evidence Links</td><td><?php echo safeText($counts["evidence_links"]); ?></td></tr>
    <tr><td>Declared Credit Hours</td><td><?php echo safeText($declaredCredits); ?></td></tr>
    <tr><td>Imported Course Credits</td><td><?php echo safeText($totalImportedCredits); ?></td></tr>
</table>

<h2>4. Readiness Area Findings</h2>

<table>
    <tr>
        <th>No.</th>
        <th>Area</th>
        <th>Status</th>
        <th>Current Finding</th>
        <th>Required Action</th>
    </tr>

    <?php $i = 1; ?>
    <?php foreach ($items as $item): ?>
        <tr>
            <td class="center"><?php echo safeText($i); ?></td>
            <td><?php echo safeText($item["area"]); ?></td>
            <td class="<?php echo safeText(statusClass($item["status"])); ?>">
                <?php echo safeText($item["status"]); ?>
            </td>
            <td><?php echo safeText($item["message"]); ?></td>
            <td><?php echo safeText($item["action"]); ?></td>
        </tr>
        <?php $i++; ?>
    <?php endforeach; ?>
</table>

<h2>5. Final Statement</h2>

<table>
    <tr>
        <th>Final Readiness Decision</th>
        <td class="<?php echo safeText($finalClass); ?>">
            <?php echo safeText($finalDecision); ?>
        </td>
    </tr>
    <tr>
        <th>Interpretation</th>
        <td>
            <?php if ($finalDecision === "Ready for Accreditation Review"): ?>
                The program specification package is ready for formal accreditation review.
            <?php elseif ($finalDecision === "Ready with Minor Review"): ?>
                The program specification package is substantially ready, but review-level items should be checked before final submission.
            <?php else: ?>
                The program specification package is not ready. Not-ready items must be corrected before formal accreditation use.
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th>Generated By</th>
        <td>Quality System - Program Specification Module</td>
    </tr>
    <tr>
        <th>Generated Date</th>
        <td><?php echo date("Y-m-d H:i"); ?></td>
    </tr>
</table>

</body>
</html>