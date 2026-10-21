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

function isReadyBadge($ready) {
    return $ready ? "badge-ready" : "badge-not-ready";
}

function isReadyText($ready) {
    return $ready ? "Ready" : "Needs Attention";
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

$counts = [
    "plos" => countRows($conn, "program_specification_plos", $spec_id),
    "courses" => countRows($conn, "program_specification_courses", $spec_id),
    "mapping" => countRows($conn, "program_specification_plo_course_mapping", $spec_id),
    "kpis" => countRows($conn, "program_specification_kpis", $spec_id),
    "curriculum" => countRows($conn, "program_specification_curriculum_structure", $spec_id),
    "evaluation" => countRows($conn, "program_specification_evaluation_matrix", $spec_id),
    "teaching" => countRows($conn, "program_specification_teaching_strategies", $spec_id),
    "assessment" => countRows($conn, "program_specification_assessment_methods", $spec_id),
    "marks" => countRows($conn, "program_specification_mark_distributions", $spec_id),
    "staffing" => countRows($conn, "program_specification_staffing_needs", $spec_id),
    "tracks" => countRows($conn, "program_specification_tracks", $spec_id),
    "exit_points" => countRows($conn, "program_specification_exit_points", $spec_id),
    "evidence_links" => countRows($conn, "program_specification_evidence_links", $spec_id)
];

$declaredCredits = (float)($spec["total_credit_hours"] ?? 0);
$totalImportedCredits = sumColumn($conn, "program_specification_courses", "credit_hours", $spec_id);

$coreReady = (
    !empty($spec["program_name"]) &&
    !empty($spec["program_code"]) &&
    !empty($spec["qualification_level"]) &&
    !empty($spec["program_mission"]) &&
    !empty($spec["program_goals"])
);

$structureReady = (
    $counts["plos"] >= 9 &&
    $counts["courses"] >= 50 &&
    $counts["mapping"] > 0 &&
    $counts["kpis"] >= 11 &&
    $counts["curriculum"] > 0
);

$qaReady = (
    $counts["evaluation"] > 0 &&
    $counts["teaching"] > 0 &&
    $counts["assessment"] > 0 &&
    $counts["marks"] > 0 &&
    $counts["staffing"] > 0
);

$creditReady = (
    $declaredCredits > 0 &&
    $totalImportedCredits > 0 &&
    abs($declaredCredits - $totalImportedCredits) <= 0.01
);

$evidenceReady = ($counts["evidence_links"] > 0);

$fullSpecReady = ($coreReady && $structureReady && $qaReady && $creditReady);
$validationReady = true;
$evidenceMatrixReady = $evidenceReady;
$readinessReady = true;

$exports = [
    [
        "sequence" => 1,
        "title" => "Full Program Specification",
        "description" => "Exports the complete program specification including identity, PLOs, curriculum, mapping, teaching, assessment, staffing, QA, KPIs, and approval data.",
        "file" => "export_program_specification_word.php?id=" . $spec_id,
        "button" => "Export Specification",
        "ready" => $fullSpecReady,
        "note" => $fullSpecReady ? "Core specification package appears ready." : "Some core or structured specification data still need review."
    ],
    [
        "sequence" => 2,
        "title" => "Validation Report",
        "description" => "Exports validation findings, missing fields, module counts, issues, and recommended corrective actions.",
        "file" => "export_program_specification_validation_word.php?id=" . $spec_id,
        "button" => "Export Validation",
        "ready" => $validationReady,
        "note" => "Can be exported anytime. Best used after completing major data entry."
    ],
    [
        "sequence" => 3,
        "title" => "Evidence Matrix",
        "description" => "Exports all linked evidence items by program specification section and identifies sections missing evidence.",
        "file" => "export_program_specification_evidence_matrix_word.php?id=" . $spec_id,
        "button" => "Export Evidence Matrix",
        "ready" => $evidenceMatrixReady,
        "note" => $evidenceMatrixReady ? "Evidence links exist." : "No evidence links found. Link evidence before relying on this export."
    ],
    [
        "sequence" => 4,
        "title" => "Readiness Review",
        "description" => "Exports the final go / no-go readiness decision for accreditation use.",
        "file" => "export_program_specification_readiness_word.php?id=" . $spec_id,
        "button" => "Export Readiness",
        "ready" => $readinessReady,
        "note" => "Can be exported anytime. Best used after validation and evidence linkage."
    ],
    [
        "sequence" => 5,
        "title" => "Archive Report",
        "description" => "Exports archive records showing finalized export sets, version notes, approval status, and readiness decision history.",
        "file" => "export_program_specification_archive_word.php?id=" . $spec_id,
        "button" => "Export Archive",
        "ready" => true,
        "note" => "Use after creating at least one archive record."
    ]
];

$readyExports = 0;

foreach ($exports as $export) {
    if (!empty($export["ready"])) {
        $readyExports++;
    }
}

$totalExports = count($exports);
$exportReadinessPercent = $totalExports > 0 ? round(($readyExports / $totalExports) * 100, 1) : 0;


$totalExports = count($exports);
$exportReadinessPercent = $totalExports > 0 ? round(($readyExports / $totalExports) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Program Specification Exports</title>
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
            min-width: 1050px;
            width: 100%;
            border-collapse: collapse;
        }

        .sequence-box {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #0d6efd;
            color: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
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
        <h1>Program Specification Exports</h1>
        <p>One place for all formal Word exports related to the program specification.</p>
    </div>

    <div class="actions">
        <a href="program_specification_dashboard.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Dashboard
        </a>

        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
            Back to Specification
        </a>

        <a href="program_specification_validation.php?id=<?php echo safeText($spec_id); ?>" class="btn">
            Validation
        </a>

        <a href="program_specification_readiness.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Readiness
        </a>

        <a href="manage_program_specification_evidence.php?id=<?php echo safeText($spec_id); ?>" class="btn">
            Evidence Linkage
        </a>
    </div>

    <div class="panel">
        <h2>Program Specification</h2>

        <table>
            <tr>
                <th>Specification ID</th>
                <td><?php echo safeText($spec_id); ?></td>
                <th>Approval Status</th>
                <td><?php echo safeText($spec["approval_status_value"] ?? ""); ?></td>
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

        <h3>Export Readiness</h3>
        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText($exportReadinessPercent); ?>%;">
                <?php echo safeText($exportReadinessPercent); ?>%
            </div>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($readyExports); ?></div>
            <div class="summary-label">Ready Exports</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalExports); ?></div>
            <div class="summary-label">Total Exports</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($counts["courses"]); ?></div>
            <div class="summary-label">Courses</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($counts["mapping"]); ?></div>
            <div class="summary-label">PLO Mappings</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($counts["evidence_links"]); ?></div>
            <div class="summary-label">Evidence Links</div>
        </div>
    </div>

    <div class="panel">
        <h2>Recommended Export Sequence</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Order</th>
                    <th>Export</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Note</th>
                    <th>Action</th>
                </tr>

                <?php foreach ($exports as $export): ?>
                    <tr>
                        <td class="center">
                            <span class="sequence-box"><?php echo safeText($export["sequence"]); ?></span>
                        </td>

                        <td><strong><?php echo safeText($export["title"]); ?></strong></td>

                        <td><?php echo safeText($export["description"]); ?></td>

                        <td>
                            <span class="badge <?php echo safeText(isReadyBadge($export["ready"])); ?>">
                                <?php echo safeText(isReadyText($export["ready"])); ?>
                            </span>
                        </td>
			

                        <td><?php echo safeText($export["note"]); ?></td>

                        <td>
                            <a href="<?php echo safeText($export["file"]); ?>" class="btn btn-purple">
                                <?php echo safeText($export["button"]); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="panel">
        <h2>Pre-Export Checklist</h2>

        <table>
            <tr>
                <th>Item</th>
                <th>Status</th>
                <th>Finding</th>
                <th>Fix</th>
            </tr>

            <tr>
                <td>Main specification fields</td>
                <td>
                    <span class="badge <?php echo safeText(isReadyBadge($coreReady)); ?>">
                        <?php echo safeText(isReadyText($coreReady)); ?>
                    </span>
                </td>
                <td>Program name, code, qualification, mission, and goals checked.</td>
                <td>
                    <a href="edit_program_specification.php?id=<?php echo safeText($spec_id); ?>" class="btn">
                        Edit
                    </a>
                </td>
            </tr>

            <tr>
                <td>Core structured data</td>
                <td>
                    <span class="badge <?php echo safeText(isReadyBadge($structureReady)); ?>">
                        <?php echo safeText(isReadyText($structureReady)); ?>
                    </span>
                </td>
                <td>
                    PLOs: <?php echo safeText($counts["plos"]); ?>,
                    Courses: <?php echo safeText($counts["courses"]); ?>,
                    Mapping: <?php echo safeText($counts["mapping"]); ?>,
                    KPIs: <?php echo safeText($counts["kpis"]); ?>,
                    Curriculum rows: <?php echo safeText($counts["curriculum"]); ?>.
                </td>
                <td>
                    <a href="program_specification_dashboard.php?id=<?php echo safeText($spec_id); ?>" class="btn">
                        Dashboard
                    </a>
                </td>
            </tr>

            <tr>
                <td>QA / teaching / assessment data</td>
                <td>
                    <span class="badge <?php echo safeText(isReadyBadge($qaReady)); ?>">
                        <?php echo safeText(isReadyText($qaReady)); ?>
                    </span>
                </td>
                <td>
                    Evaluation: <?php echo safeText($counts["evaluation"]); ?>,
                    Teaching: <?php echo safeText($counts["teaching"]); ?>,
                    Assessment: <?php echo safeText($counts["assessment"]); ?>,
                    Marks: <?php echo safeText($counts["marks"]); ?>,
                    Staffing: <?php echo safeText($counts["staffing"]); ?>.
                </td>
                <td>
                    <a href="program_specification_dashboard.php?id=<?php echo safeText($spec_id); ?>" class="btn">
                        Dashboard
                    </a>
                </td>
            </tr>

            <tr>
                <td>Credit-hour match</td>
                <td>
                    <span class="badge <?php echo safeText(isReadyBadge($creditReady)); ?>">
                        <?php echo safeText(isReadyText($creditReady)); ?>
                    </span>
                </td>
                <td>
                    Declared credits: <?php echo safeText($declaredCredits); ?>,
                    imported course credits: <?php echo safeText($totalImportedCredits); ?>.
                </td>
                <td>
                    <a href="program_specification_validation.php?id=<?php echo safeText($spec_id); ?>" class="btn">
                        Validate
                    </a>
                </td>
            </tr>

            <tr>
                <td>Evidence linkage</td>
                <td>
                    <span class="badge <?php echo safeText(isReadyBadge($evidenceReady)); ?>">
                        <?php echo safeText(isReadyText($evidenceReady)); ?>
                    </span>
                </td>
                <td>
                    Evidence links: <?php echo safeText($counts["evidence_links"]); ?>.
                </td>
                <td>
                    <a href="manage_program_specification_evidence.php?id=<?php echo safeText($spec_id); ?>" class="btn">
                        Link Evidence
                    </a>
                </td>
            </tr>
        </table>
    </div>

</div>

</body>
</html>
