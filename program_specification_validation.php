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

function addIssue(&$issues, $severity, $section, $item, $message, $action) {
    $issues[] = [
        "severity" => $severity,
        "section" => $section,
        "item" => $item,
        "message" => $message,
        "action" => $action
    ];
}

function issueClass($severity) {
    if ($severity === "High") {
        return "badge-danger";
    }

    if ($severity === "Medium") {
        return "badge-warning";
    }

    return "badge-muted";
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

/* Main fields */
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
    "graduation_requirements" => "Graduation Requirements",
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
            "Open Edit Specification and complete this field."
        );
    }
}

/* Linked study plan */
if (empty($spec["study_plan_id"])) {
    addIssue(
        $issues,
        "High",
        "Study Plan Link",
        "Linked Study Plan",
        "The program specification is not linked to a study plan.",
        "Edit the specification and select the approved study plan."
    );
}

/* Module checks */
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
        "Review study plan courses or update declared credit hours."
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
    addIssue(
        $issues,
        "Medium",
        "Curriculum Structure",
        "Curriculum Structure",
        "No curriculum structure rows are available.",
        "Import default HPE curriculum structure."
    );
}

if ($counts["evaluation"] === 0) {
    addIssue(
        $issues,
        "Medium",
        "Quality Assurance",
        "Evaluation Matrix",
        "No program evaluation matrix rows are available.",
        "Import default HPE evaluation matrix."
    );
}

if ($counts["teaching"] === 0) {
    addIssue(
        $issues,
        "Medium",
        "Teaching and Learning",
        "Teaching Strategies",
        "No structured teaching strategy rows are available.",
        "Import default HPE teaching and assessment records."
    );
}

if ($counts["assessment"] === 0) {
    addIssue(
        $issues,
        "Medium",
        "Assessment",
        "Assessment Methods",
        "No structured assessment method rows are available.",
        "Import default HPE teaching and assessment records."
    );
}

if ($counts["marks"] === 0) {
    addIssue(
        $issues,
        "Medium",
        "Assessment",
        "Mark Distribution",
        "No mark distribution rows are available.",
        "Import default HPE teaching and assessment records."
    );
}

if ($counts["staffing"] === 0) {
    addIssue(
        $issues,
        "Medium",
        "Staffing",
        "Staffing Needs",
        "No staffing needs are available.",
        "Import default HPE staffing needs or enter actual staffing requirements."
    );
}

if ($counts["tracks"] === 0) {
    addIssue(
        $issues,
        "Low",
        "Tracks / Pathways",
        "Tracks",
        "No tracks/pathways are recorded.",
        "Import default HPE tracks/exit points or document N/A."
    );
}

if ($counts["exit_points"] === 0) {
    addIssue(
        $issues,
        "Medium",
        "Exit Points",
        "Exit Points",
        "No exit points or final award rows are recorded.",
        "Import default HPE tracks/exit points or enter approved exit awards."
    );
}

/* Detailed course checks */
if (tableExists($conn, "program_specification_courses")) {
    $courseStmt = $conn->prepare("
        SELECT *
        FROM program_specification_courses
        WHERE program_specification_id = :id
        ORDER BY level_no ASC, course_code ASC, id ASC
    ");
    $courseStmt->execute([":id" => $spec_id]);
    $courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

    $courseCodeCounts = [];

    foreach ($courses as $course) {
        $courseCode = trim((string)($course["course_code"] ?? ""));
        $courseTitle = trim((string)($course["course_title"] ?? ""));
        $courseLabel = $courseCode !== "" ? $courseCode : "Course ID " . ($course["id"] ?? "");

        if ($courseCode === "") {
            addIssue(
                $issues,
                "High",
                "Curriculum",
                $courseLabel,
                "Course code is missing.",
                "Fix the course record or re-import the study plan CSV."
            );
        }

        if ($courseTitle === "") {
            addIssue(
                $issues,
                "High",
                "Curriculum",
                $courseLabel,
                "Course title is missing.",
                "Fix the course record or re-import the study plan CSV."
            );
        }

        if ((float)($course["credit_hours"] ?? 0) <= 0 && stripos($courseTitle, "internship") === false) {
            addIssue(
                $issues,
                "Medium",
                "Curriculum",
                $courseLabel,
                "Course credit hours are zero or missing.",
                "Check whether this course should have credit hours."
            );
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
            addIssue(
                $issues,
                "High",
                "Curriculum",
                $code,
                "Duplicate course code found " . $count . " times.",
                "Remove duplicate course records."
            );
        }
    }
}

/* Totals */
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

$validationStatus = "Passed";

if ($highIssues > 0) {
    $validationStatus = "Failed";
} elseif ($mediumIssues > 0 || $lowIssues > 0) {
    $validationStatus = "Needs Review";
}

$statusClass = "status-pass";

if ($validationStatus === "Failed") {
    $statusClass = "status-fail";
} elseif ($validationStatus === "Needs Review") {
    $statusClass = "status-review";
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Program Specification Validation</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(130px, 1fr));
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
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-danger {
            background: #f8d7da;
            color: #842029;
        }

        .badge-warning {
            background: #fff3cd;
            color: #664d03;
        }

        .badge-muted {
            background: #e9ecef;
            color: #495057;
        }

        .status-pass {
            background: #d1e7dd;
            color: #0f5132;
        }

        .status-review {
            background: #fff3cd;
            color: #664d03;
        }

        .status-fail {
            background: #f8d7da;
            color: #842029;
        }

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .wide-table {
            min-width: 1200px;
            width: 100%;
            border-collapse: collapse;
        }

        @media (max-width: 1100px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(130px, 1fr));
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
        <h1>Program Specification Validation</h1>
        <p>Checks completeness, curriculum consistency, PLOs, KPIs, mappings, staffing, teaching, assessment, and QA readiness.</p>
    </div>

    <div class="actions">

        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
            Back to Specification
        </a>

        <a href="program_specification_dashboard.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Dashboard
        </a>

        <a href="edit_program_specification.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-edit">
            Edit Specification
        </a>

        <a href="manage_program_specification_plos.php?id=<?php echo safeText($spec_id); ?>" class="btn">
            Manage PLOs
        </a>

        <a href="manage_program_specification_kpis.php?id=<?php echo safeText($spec_id); ?>" class="btn">
            Manage KPIs
        </a>

        <a href="import_program_specification_from_study_plan.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Import Courses
        </a>

        <a href="program_specification_plo_mapping.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            PLO Mapping
        </a>

        <a href="manage_program_specification_curriculum.php?id=<?php echo safeText($spec_id); ?>" class="btn">
            Curriculum Structure
        </a>

        <a href="manage_program_specification_evaluation.php?id=<?php echo safeText($spec_id); ?>" class="btn">
            Evaluation Matrix
        </a>

        <a href="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>" class="btn">
            Teaching / Assessment
        </a>

        <a href="manage_program_specification_staffing.php?id=<?php echo safeText($spec_id); ?>" class="btn">
            Staffing Needs
        </a>

        <a href="manage_program_specification_tracks_exit_points.php?id=<?php echo safeText($spec_id); ?>" class="btn">
            Tracks / Exit Points
        </a>

        <a href="export_program_specification_word.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Export Word
        </a>

        <a href="export_program_specification_validation_word.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Export Validation Report
        </a>

    </div>

    <div class="panel">
        <h2>Program Specification</h2>

        <table>
            <tr>
                <th>Specification ID</th>
                <td><?php echo safeText($spec_id); ?></td>
                <th>Validation Status</th>
                <td>
                    <span class="badge <?php echo safeText($statusClass); ?>">
                        <?php echo safeText($validationStatus); ?>
                    </span>
                </td>
            </tr>

            <tr>
                <th>Specification</th>
                <td><?php echo safeText($spec["specification_title"] ?? ""); ?></td>
                <th>Program</th>
                <td><?php echo safeText($spec["program_name"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Program Code</th>
                <td><?php echo safeText($spec["program_code"] ?? ""); ?></td>
                <th>Total Credit Hours</th>
                <td><?php echo safeText($spec["total_credit_hours"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Imported Course Credits</th>
                <td><?php echo safeText($totalImportedCredits); ?></td>
                <th>Approval Status</th>
                <td><?php echo safeText($spec["approval_status_value"] ?? ""); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($counts["plos"]); ?></div>
            <div class="summary-label">PLOs</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($counts["courses"]); ?></div>
            <div class="summary-label">Courses</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($counts["mapping"]); ?></div>
            <div class="summary-label">Mappings</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($counts["kpis"]); ?></div>
            <div class="summary-label">KPIs</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalIssues); ?></div>
            <div class="summary-label">Total Issues</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($highIssues); ?></div>
            <div class="summary-label">High Severity</div>
        </div>
    </div>

    <div class="panel">
        <h2>Module Counts</h2>

        <table>
            <tr>
                <th>PLOs</th>
                <td><?php echo safeText($counts["plos"]); ?></td>
                <th>Courses</th>
                <td><?php echo safeText($counts["courses"]); ?></td>
            </tr>

            <tr>
                <th>PLO Mapping Records</th>
                <td><?php echo safeText($counts["mapping"]); ?></td>
                <th>KPIs</th>
                <td><?php echo safeText($counts["kpis"]); ?></td>
            </tr>

            <tr>
                <th>Curriculum Rows</th>
                <td><?php echo safeText($counts["curriculum"]); ?></td>
                <th>Evaluation Rows</th>
                <td><?php echo safeText($counts["evaluation"]); ?></td>
            </tr>

            <tr>
                <th>Teaching Strategies</th>
                <td><?php echo safeText($counts["teaching"]); ?></td>
                <th>Assessment Methods</th>
                <td><?php echo safeText($counts["assessment"]); ?></td>
            </tr>

            <tr>
                <th>Mark Distribution Rows</th>
                <td><?php echo safeText($counts["marks"]); ?></td>
                <th>Staffing Rows</th>
                <td><?php echo safeText($counts["staffing"]); ?></td>
            </tr>

            <tr>
                <th>Tracks</th>
                <td><?php echo safeText($counts["tracks"]); ?></td>
                <th>Exit Points</th>
                <td><?php echo safeText($counts["exit_points"]); ?></td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Validation Issues</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Severity</th>
                    <th>Section</th>
                    <th>Item</th>
                    <th>Issue</th>
                    <th>Recommended Action</th>
                </tr>

                <?php if (count($issues) > 0): ?>
                    <?php foreach ($issues as $issue): ?>
                        <tr>
                            <td>
                                <span class="badge <?php echo safeText(issueClass($issue["severity"])); ?>">
                                    <?php echo safeText($issue["severity"]); ?>
                                </span>
                            </td>
                            <td><?php echo safeText($issue["section"]); ?></td>
                            <td><?php echo safeText($issue["item"]); ?></td>
                            <td><?php echo safeText($issue["message"]); ?></td>
                            <td><?php echo safeText($issue["action"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">
                            No validation issues found. The program specification is ready for export and accreditation review.
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
