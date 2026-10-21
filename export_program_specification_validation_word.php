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

function addIssue(&$issues, $severity, $section, $item, $message, $action) {
    $issues[] = [
        "severity" => $severity,
        "section" => $section,
        "item" => $item,
        "message" => $message,
        "action" => $action
    ];
}

function isEmptyValue($value) {
    return $value === null || trim((string)$value) === "";
}

function severityClass($severity) {
    if ($severity === "High") {
        return "high";
    }

    if ($severity === "Medium") {
        return "medium";
    }

    return "low";
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

$issues = [];

/* Core fields */
$coreFields = [
    "specification_title" => "Specification Title",
    "program_name" => "Program Name",
    "program_code" => "Program Code",
    "qualification_level" => "Qualification Level",
    "department_name" => "Department",
    "college_name" => "College",
    "institution_name" => "Institution",
    "main_location" => "Main Location",
    "total_credit_hours" => "Total Credit Hours",
    "program_mission" => "Program Mission",
    "program_goals" => "Program Goals",
    "admission_requirements" => "Admission Requirements",
    "teaching_learning_strategy" => "Teaching and Learning Strategies Narrative",
    "assessment_strategy" => "Assessment Strategy Narrative",
    "quality_assurance_system" => "Quality Assurance System",
    "approval_council_committee" => "Approval Council / Committee",
    "approval_reference_no" => "Approval Reference No.",
    "approval_date_text" => "Approval Date Text"
];

foreach ($coreFields as $field => $label) {
    if (array_key_exists($field, $spec) && isEmptyValue($spec[$field])) {
        addIssue(
            $issues,
            "High",
            "Main Specification",
            $label,
            $label . " is missing.",
            "Complete this field in Edit Program Specification."
        );
    }
}

/* Study plan */
if (empty($spec["study_plan_id"])) {
    addIssue(
        $issues,
        "High",
        "Study Plan Link",
        "Linked Study Plan",
        "No study plan is linked to this program specification.",
        "Edit the program specification and link the approved study plan."
    );
}

/* Counts */
if ($counts["plos"] < 9) {
    addIssue(
        $issues,
        $counts["plos"] === 0 ? "High" : "Medium",
        "Program Learning Outcomes",
        "PLOs",
        "Expected at least 9 HPE PLOs. Current count: " . $counts["plos"] . ".",
        "Import default HPE PLOs or complete missing PLOs."
    );
}

if ($counts["courses"] < 50) {
    addIssue(
        $issues,
        $counts["courses"] === 0 ? "High" : "Medium",
        "Curriculum",
        "Program Courses",
        "Expected 50 study plan courses. Current count: " . $counts["courses"] . ".",
        "Import courses from the linked study plan."
    );
}

if ($declaredCredits > 0 && $totalImportedCredits > 0 && abs($declaredCredits - $totalImportedCredits) > 0.01) {
    addIssue(
        $issues,
        "High",
        "Curriculum",
        "Credit Hours",
        "Declared credits are " . $declaredCredits . ", but imported course credits total " . $totalImportedCredits . ".",
        "Review the study plan courses or update the declared total credit hours."
    );
}

if ($counts["mapping"] === 0) {
    addIssue(
        $issues,
        "High",
        "PLO-Course Mapping",
        "Mapping Matrix",
        "No PLO-course mapping records are available.",
        "Open PLO Mapping and import default mapping or enter mapping manually."
    );
}

if ($counts["kpis"] < 11) {
    addIssue(
        $issues,
        $counts["kpis"] === 0 ? "High" : "Medium",
        "Program KPIs",
        "KPIs",
        "Expected at least 11 main KPIs. Current KPI count: " . $counts["kpis"] . ".",
        "Import default HPE KPIs or complete KPI records."
    );
}

if ($counts["curriculum"] === 0) {
    addIssue($issues, "Medium", "Curriculum Structure", "Curriculum Structure", "No curriculum structure rows are available.", "Import default HPE curriculum structure.");
}

if ($counts["evaluation"] === 0) {
    addIssue($issues, "Medium", "Quality Assurance", "Evaluation Matrix", "No program evaluation matrix rows are available.", "Import default HPE evaluation matrix.");
}

if ($counts["teaching"] === 0) {
    addIssue($issues, "Medium", "Teaching and Learning", "Teaching Strategies", "No structured teaching strategy rows are available.", "Import default HPE teaching and assessment strategy records.");
}

if ($counts["assessment"] === 0) {
    addIssue($issues, "Medium", "Assessment", "Assessment Methods", "No structured assessment method rows are available.", "Import default HPE teaching and assessment strategy records.");
}

if ($counts["marks"] === 0) {
    addIssue($issues, "Medium", "Assessment", "Mark Distribution", "No mark distribution rows are available.", "Import default HPE teaching and assessment strategy records.");
}

if ($counts["staffing"] === 0) {
    addIssue($issues, "Medium", "Staffing", "Staffing Needs", "No staffing needs are available.", "Import default HPE staffing needs or enter actual staffing requirements.");
}

if ($counts["tracks"] === 0) {
    addIssue($issues, "Low", "Tracks / Pathways", "Tracks", "No tracks/pathways are recorded.", "Import default HPE tracks/exit points or document N/A.");
}

if ($counts["exit_points"] === 0) {
    addIssue($issues, "Medium", "Exit Points", "Exit Points", "No exit points or final award rows are recorded.", "Import default HPE tracks/exit points or enter approved exit awards.");
}

/* Detailed course checks */
if (tableExists($conn, "program_specification_courses")) {
    $stmt = $conn->prepare("
        SELECT *
        FROM program_specification_courses
        WHERE program_specification_id = :id
        ORDER BY level_no ASC, course_code ASC, id ASC
    ");
    $stmt->execute([":id" => $spec_id]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $courseCodeCounts = [];

    foreach ($courses as $course) {
        $courseCode = trim((string)($course["course_code"] ?? ""));
        $courseTitle = trim((string)($course["course_title"] ?? ""));
        $courseLabel = $courseCode !== "" ? $courseCode : "Course ID " . ($course["id"] ?? "");

        if ($courseCode === "") {
            addIssue($issues, "High", "Curriculum", $courseLabel, "Course code is missing.", "Fix the course record or re-import the study plan CSV.");
        }

        if ($courseTitle === "") {
            addIssue($issues, "High", "Curriculum", $courseLabel, "Course title is missing.", "Fix the course record or re-import the study plan CSV.");
        }

        if ((float)($course["credit_hours"] ?? 0) <= 0 && stripos($courseTitle, "internship") === false) {
            addIssue($issues, "Medium", "Curriculum", $courseLabel, "Course credit hours are zero or missing.", "Check whether this course should have credit hours.");
        }

        if ($courseCode !== "") {
            $normalized = strtoupper($courseCode);

            if (!isset($courseCodeCounts[$normalized])) {
                $courseCodeCounts[$normalized] = 0;
            }

            $courseCodeCounts[$normalized]++;
        }
    }

    foreach ($courseCodeCounts as $code => $count) {
        if ($count > 1) {
            addIssue($issues, "High", "Curriculum", $code, "Duplicate course code found " . $count . " times.", "Remove duplicate course records.");
        }
    }
}

/* Summary */
$totalIssues = count($issues);
$highIssues = 0;
$mediumIssues = 0;
$lowIssues = 0;

foreach ($issues as $issue) {
    if ($issue["severity"] === "High") {
        $highIssues++;
    } elseif ($issue["severity"] === "Medium") {
        $mediumIssues++;
    } elseif ($issue["severity"] === "Low") {
        $lowIssues++;
    }
}

$readinessStatus = "Ready for Review";

if ($highIssues > 0) {
    $readinessStatus = "Not Ready";
} elseif ($mediumIssues > 0 || $lowIssues > 0) {
    $readinessStatus = "Needs Minor Review";
}

$statusClass = "status-ready";
if ($readinessStatus === "Not Ready") {
    $statusClass = "status-not-ready";
} elseif ($readinessStatus === "Needs Minor Review") {
    $statusClass = "status-review";
}

$filenameBase = $spec["program_code"] ?? "program_specification_validation";
$filenameBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filenameBase);
$filename = "program_specification_validation_" . $filenameBase . "_" . date("Y-m-d_H-i-s") . ".doc";

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
    <title>Program Specification Validation Report</title>

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

        .status-ready {
            background: #d1e7dd;
            color: #0f5132;
            font-weight: bold;
        }

        .status-review {
            background: #fff3cd;
            color: #664d03;
            font-weight: bold;
        }

        .status-not-ready {
            background: #f8d7da;
            color: #842029;
            font-weight: bold;
        }

        .high {
            background: #f8d7da;
            color: #842029;
            font-weight: bold;
        }

        .medium {
            background: #fff3cd;
            color: #664d03;
            font-weight: bold;
        }

        .low {
            background: #e9ecef;
            color: #495057;
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
    <h1>Program Specification Validation Report</h1>
    <p><strong><?php echo safeText($spec["program_name"] ?? ""); ?></strong></p>
    <p><?php echo safeText($spec["college_name"] ?? ""); ?></p>
    <p><?php echo safeText($spec["institution_name"] ?? ""); ?></p>
    <p>Generated on: <?php echo date("Y-m-d H:i"); ?></p>
</div>

<h2>1. Program Specification Identity</h2>

<table>
    <tr>
        <th>Program Specification ID</th>
        <td><?php echo safeText($spec_id); ?></td>
        <th>Readiness Status</th>
        <td class="<?php echo safeText($statusClass); ?>">
            <?php echo safeText($readinessStatus); ?>
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
    <tr>
        <th>Declared Credit Hours</th>
        <td><?php echo safeText($declaredCredits); ?></td>
        <th>Imported Course Credits</th>
        <td><?php echo safeText($totalImportedCredits); ?></td>
    </tr>
</table>

<h2>2. Validation Summary</h2>

<table>
    <tr>
        <th>Total Issues</th>
        <th>High Severity</th>
        <th>Medium Severity</th>
        <th>Low Severity</th>
    </tr>
    <tr>
        <td class="center"><?php echo safeText($totalIssues); ?></td>
        <td class="center high"><?php echo safeText($highIssues); ?></td>
        <td class="center medium"><?php echo safeText($mediumIssues); ?></td>
        <td class="center low"><?php echo safeText($lowIssues); ?></td>
    </tr>
</table>

<h2>3. Module Completion Counts</h2>

<table>
    <tr>
        <th>Module</th>
        <th>Count / Value</th>
    </tr>
    <tr><td>Program Learning Outcomes</td><td><?php echo safeText($counts["plos"]); ?></td></tr>
    <tr><td>Program Courses</td><td><?php echo safeText($counts["courses"]); ?></td></tr>
    <tr><td>PLO-Course Mapping Records</td><td><?php echo safeText($counts["mapping"]); ?></td></tr>
    <tr><td>Program KPIs</td><td><?php echo safeText($counts["kpis"]); ?></td></tr>
    <tr><td>Curriculum Structure Rows</td><td><?php echo safeText($counts["curriculum"]); ?></td></tr>
    <tr><td>Evaluation Matrix Rows</td><td><?php echo safeText($counts["evaluation"]); ?></td></tr>
    <tr><td>Teaching Strategy Rows</td><td><?php echo safeText($counts["teaching"]); ?></td></tr>
    <tr><td>Assessment Method Rows</td><td><?php echo safeText($counts["assessment"]); ?></td></tr>
    <tr><td>Mark Distribution Rows</td><td><?php echo safeText($counts["marks"]); ?></td></tr>
    <tr><td>Staffing Need Rows</td><td><?php echo safeText($counts["staffing"]); ?></td></tr>
    <tr><td>Track Rows</td><td><?php echo safeText($counts["tracks"]); ?></td></tr>
    <tr><td>Exit Point Rows</td><td><?php echo safeText($counts["exit_points"]); ?></td></tr>
    <tr><td>Declared Credit Hours</td><td><?php echo safeText($declaredCredits); ?></td></tr>
    <tr><td>Imported Course Credits</td><td><?php echo safeText($totalImportedCredits); ?></td></tr>
</table>

<h2>4. Validation Issues and Corrective Actions</h2>

<table>
    <tr>
        <th>Severity</th>
        <th>Section</th>
        <th>Item</th>
        <th>Issue</th>
        <th>Recommended Corrective Action</th>
    </tr>

    <?php if (count($issues) > 0): ?>
        <?php foreach ($issues as $issue): ?>
            <tr>
                <td class="<?php echo safeText(severityClass($issue["severity"])); ?>">
                    <?php echo safeText($issue["severity"]); ?>
                </td>
                <td><?php echo safeText($issue["section"]); ?></td>
                <td><?php echo safeText($issue["item"]); ?></td>
                <td><?php echo safeText($issue["message"]); ?></td>
                <td><?php echo safeText($issue["action"]); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="5">No validation issues found. The program specification is ready for accreditation review.</td>
        </tr>
    <?php endif; ?>
</table>

<h2>5. Final Readiness Statement</h2>

<table>
    <tr>
        <th>Readiness Status</th>
        <td class="<?php echo safeText($statusClass); ?>">
            <?php echo safeText($readinessStatus); ?>
        </td>
    </tr>
    <tr>
        <th>Interpretation</th>
        <td>
            <?php if ($readinessStatus === "Ready for Review"): ?>
                The program specification has no detected validation issues and is ready for formal review.
            <?php elseif ($readinessStatus === "Needs Minor Review"): ?>
                The program specification has no high-severity issues, but medium or low issues should be reviewed before final approval.
            <?php else: ?>
                The program specification has high-severity issues that must be corrected before accreditation use.
            <?php endif; ?>
        </td>
    </tr>
</table>

</body>
</html>