<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid program specification ID.");
}

$spec_id = (int)$_GET["id"];
$error = "";

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

function addColumnIfMissing($conn, $tableName, $columnName, $definition) {
    if (!columnExists($conn, $tableName, $columnName)) {
        requireSchemaColumn($conn, $tableName, $columnName);
    }
}

function nullIfEmpty($value) {
    $value = trim((string)$value);
    return $value === "" ? null : $value;
}

function numberValue($value) {
    $value = trim((string)$value);
    if ($value === "") {
        return 0;
    }

    $value = str_replace(",", ".", $value);
    return is_numeric($value) ? (float)$value : 0;
}

function intValue($value) {
    $value = trim((string)$value);
    if ($value === "") {
        return null;
    }

    return is_numeric($value) ? (int)$value : null;
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "program_specification_teaching_strategies")) {
    die("program_specification_teaching_strategies table does not exist.");
}

if (!tableExists($conn, "program_specification_assessment_methods")) {
    die("program_specification_assessment_methods table does not exist.");
}

if (!tableExists($conn, "program_specification_mark_distributions")) {
    die("program_specification_mark_distributions table does not exist.");
}

/*
    Safety upgrade for older tables.
*/
addColumnIfMissing($conn, "program_specification_teaching_strategies", "strategy_name", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_teaching_strategies", "strategy_description", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_teaching_strategies", "related_plo_codes", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_teaching_strategies", "curricular_or_extra", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "program_specification_teaching_strategies", "notes", "TEXT NULL");

addColumnIfMissing($conn, "program_specification_assessment_methods", "assessment_method", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_assessment_methods", "assessment_type", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "program_specification_assessment_methods", "assessment_description", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_assessment_methods", "related_plo_codes", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_assessment_methods", "notes", "TEXT NULL");

addColumnIfMissing($conn, "program_specification_mark_distributions", "course_type", "VARCHAR(150) NULL");
addColumnIfMissing($conn, "program_specification_mark_distributions", "assessment_method", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_mark_distributions", "marks", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "program_specification_mark_distributions", "percentage", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "program_specification_mark_distributions", "display_order", "INT NULL");
addColumnIfMissing($conn, "program_specification_mark_distributions", "notes", "TEXT NULL");

$specStmt = $conn->prepare("
    SELECT *
    FROM program_specifications
    WHERE id = :id
    LIMIT 1
");
$specStmt->execute([":id" => $spec_id]);
$spec = $specStmt->fetch(PDO::FETCH_ASSOC);

if (!$spec) {
    die("Program specification not found.");
}

$canEdit = hasRole(["Admin", "Quality Coordinator", "Program Leader"]);

$teachingForm = [
    "teaching_id" => "",
    "strategy_name" => "",
    "strategy_description" => "",
    "related_plo_codes" => "",
    "curricular_or_extra" => "Curricular",
    "notes" => ""
];

$assessmentForm = [
    "assessment_id" => "",
    "assessment_method" => "",
    "assessment_type" => "Direct",
    "assessment_description" => "",
    "related_plo_codes" => "",
    "notes" => ""
];

$markForm = [
    "mark_id" => "",
    "course_type" => "",
    "assessment_method" => "",
    "marks" => "0",
    "percentage" => "0",
    "display_order" => "",
    "notes" => ""
];

/* ===============================
   Delete handlers
   =============================== */
if ($canEdit && isset($_GET["delete_teaching"]) && is_numeric($_GET["delete_teaching"])) {
    $delete_id = (int)$_GET["delete_teaching"];

    $loadStmt = $conn->prepare("
        SELECT *
        FROM program_specification_teaching_strategies
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $loadStmt->execute([
        ":id" => $delete_id,
        ":program_specification_id" => $spec_id
    ]);
    $row = $loadStmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $deleteStmt = $conn->prepare("
            DELETE FROM program_specification_teaching_strategies
            WHERE id = :id
              AND program_specification_id = :program_specification_id
        ");
        $deleteStmt->execute([
            ":id" => $delete_id,
            ":program_specification_id" => $spec_id
        ]);

        logActivitySafe($conn, "Delete", "Program Specification Teaching Strategies", $delete_id, "Deleted teaching strategy: " . ($row["strategy_name"] ?? ""));

        header("Location: manage_program_specification_teaching_assessment.php?id=" . $spec_id);
        exit;
    }
}

if ($canEdit && isset($_GET["delete_assessment"]) && is_numeric($_GET["delete_assessment"])) {
    $delete_id = (int)$_GET["delete_assessment"];

    $loadStmt = $conn->prepare("
        SELECT *
        FROM program_specification_assessment_methods
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $loadStmt->execute([
        ":id" => $delete_id,
        ":program_specification_id" => $spec_id
    ]);
    $row = $loadStmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $deleteStmt = $conn->prepare("
            DELETE FROM program_specification_assessment_methods
            WHERE id = :id
              AND program_specification_id = :program_specification_id
        ");
        $deleteStmt->execute([
            ":id" => $delete_id,
            ":program_specification_id" => $spec_id
        ]);

        logActivitySafe($conn, "Delete", "Program Specification Assessment Methods", $delete_id, "Deleted assessment method: " . ($row["assessment_method"] ?? ""));

        header("Location: manage_program_specification_teaching_assessment.php?id=" . $spec_id);
        exit;
    }
}

if ($canEdit && isset($_GET["delete_mark"]) && is_numeric($_GET["delete_mark"])) {
    $delete_id = (int)$_GET["delete_mark"];

    $loadStmt = $conn->prepare("
        SELECT *
        FROM program_specification_mark_distributions
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $loadStmt->execute([
        ":id" => $delete_id,
        ":program_specification_id" => $spec_id
    ]);
    $row = $loadStmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $deleteStmt = $conn->prepare("
            DELETE FROM program_specification_mark_distributions
            WHERE id = :id
              AND program_specification_id = :program_specification_id
        ");
        $deleteStmt->execute([
            ":id" => $delete_id,
            ":program_specification_id" => $spec_id
        ]);

        logActivitySafe($conn, "Delete", "Program Specification Mark Distribution", $delete_id, "Deleted mark distribution: " . ($row["assessment_method"] ?? ""));

        header("Location: manage_program_specification_teaching_assessment.php?id=" . $spec_id);
        exit;
    }
}

/* ===============================
   Edit loaders
   =============================== */
if (isset($_GET["edit_teaching"]) && is_numeric($_GET["edit_teaching"])) {
    $edit_id = (int)$_GET["edit_teaching"];

    $editStmt = $conn->prepare("
        SELECT *
        FROM program_specification_teaching_strategies
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $editStmt->execute([
        ":id" => $edit_id,
        ":program_specification_id" => $spec_id
    ]);
    $row = $editStmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $teachingForm = [
            "teaching_id" => $row["id"] ?? "",
            "strategy_name" => $row["strategy_name"] ?? "",
            "strategy_description" => $row["strategy_description"] ?? "",
            "related_plo_codes" => $row["related_plo_codes"] ?? "",
            "curricular_or_extra" => $row["curricular_or_extra"] ?? "Curricular",
            "notes" => $row["notes"] ?? ""
        ];
    }
}

if (isset($_GET["edit_assessment"]) && is_numeric($_GET["edit_assessment"])) {
    $edit_id = (int)$_GET["edit_assessment"];

    $editStmt = $conn->prepare("
        SELECT *
        FROM program_specification_assessment_methods
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $editStmt->execute([
        ":id" => $edit_id,
        ":program_specification_id" => $spec_id
    ]);
    $row = $editStmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $assessmentForm = [
            "assessment_id" => $row["id"] ?? "",
            "assessment_method" => $row["assessment_method"] ?? "",
            "assessment_type" => $row["assessment_type"] ?? "Direct",
            "assessment_description" => $row["assessment_description"] ?? "",
            "related_plo_codes" => $row["related_plo_codes"] ?? "",
            "notes" => $row["notes"] ?? ""
        ];
    }
}

if (isset($_GET["edit_mark"]) && is_numeric($_GET["edit_mark"])) {
    $edit_id = (int)$_GET["edit_mark"];

    $editStmt = $conn->prepare("
        SELECT *
        FROM program_specification_mark_distributions
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $editStmt->execute([
        ":id" => $edit_id,
        ":program_specification_id" => $spec_id
    ]);
    $row = $editStmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $markForm = [
            "mark_id" => $row["id"] ?? "",
            "course_type" => $row["course_type"] ?? "",
            "assessment_method" => $row["assessment_method"] ?? "",
            "marks" => $row["marks"] ?? "0",
            "percentage" => $row["percentage"] ?? "0",
            "display_order" => $row["display_order"] ?? "",
            "notes" => $row["notes"] ?? ""
        ];
    }
}

/* ===============================
   POST handlers
   =============================== */
if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST") {
    $formType = trim($_POST["form_type"] ?? "");

    if ($formType === "teaching") {
        foreach ($teachingForm as $key => $value) {
            $teachingForm[$key] = trim($_POST[$key] ?? "");
        }

        if ($teachingForm["strategy_name"] === "") {
            $error = "Teaching strategy name is required.";
        } else {
            if ($teachingForm["teaching_id"] !== "" && is_numeric($teachingForm["teaching_id"])) {
                $stmt = $conn->prepare("
                    UPDATE program_specification_teaching_strategies
                    SET
                        strategy_name = :strategy_name,
                        strategy_description = :strategy_description,
                        related_plo_codes = :related_plo_codes,
                        curricular_or_extra = :curricular_or_extra,
                        notes = :notes
                    WHERE id = :id
                      AND program_specification_id = :program_specification_id
                ");

                $stmt->execute([
                    ":strategy_name" => $teachingForm["strategy_name"],
                    ":strategy_description" => nullIfEmpty($teachingForm["strategy_description"]),
                    ":related_plo_codes" => nullIfEmpty($teachingForm["related_plo_codes"]),
                    ":curricular_or_extra" => nullIfEmpty($teachingForm["curricular_or_extra"]),
                    ":notes" => nullIfEmpty($teachingForm["notes"]),
                    ":id" => (int)$teachingForm["teaching_id"],
                    ":program_specification_id" => $spec_id
                ]);

                logActivitySafe($conn, "Update", "Program Specification Teaching Strategies", (int)$teachingForm["teaching_id"], "Updated teaching strategy: " . $teachingForm["strategy_name"]);

                header("Location: manage_program_specification_teaching_assessment.php?id=" . $spec_id);
                exit;
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO program_specification_teaching_strategies
                    (
                        program_specification_id,
                        strategy_name,
                        strategy_description,
                        related_plo_codes,
                        curricular_or_extra,
                        notes
                    )
                    VALUES
                    (
                        :program_specification_id,
                        :strategy_name,
                        :strategy_description,
                        :related_plo_codes,
                        :curricular_or_extra,
                        :notes
                    )
                ");

                $stmt->execute([
                    ":program_specification_id" => $spec_id,
                    ":strategy_name" => $teachingForm["strategy_name"],
                    ":strategy_description" => nullIfEmpty($teachingForm["strategy_description"]),
                    ":related_plo_codes" => nullIfEmpty($teachingForm["related_plo_codes"]),
                    ":curricular_or_extra" => nullIfEmpty($teachingForm["curricular_or_extra"]),
                    ":notes" => nullIfEmpty($teachingForm["notes"])
                ]);

                logActivitySafe($conn, "Create", "Program Specification Teaching Strategies", (int)$conn->lastInsertId(), "Created teaching strategy: " . $teachingForm["strategy_name"]);

                header("Location: manage_program_specification_teaching_assessment.php?id=" . $spec_id);
                exit;
            }
        }
    }

    if ($formType === "assessment") {
        foreach ($assessmentForm as $key => $value) {
            $assessmentForm[$key] = trim($_POST[$key] ?? "");
        }

        if ($assessmentForm["assessment_method"] === "") {
            $error = "Assessment method is required.";
        } else {
            if ($assessmentForm["assessment_id"] !== "" && is_numeric($assessmentForm["assessment_id"])) {
                $stmt = $conn->prepare("
                    UPDATE program_specification_assessment_methods
                    SET
                        assessment_method = :assessment_method,
                        assessment_type = :assessment_type,
                        assessment_description = :assessment_description,
                        related_plo_codes = :related_plo_codes,
                        notes = :notes
                    WHERE id = :id
                      AND program_specification_id = :program_specification_id
                ");

                $stmt->execute([
                    ":assessment_method" => $assessmentForm["assessment_method"],
                    ":assessment_type" => nullIfEmpty($assessmentForm["assessment_type"]),
                    ":assessment_description" => nullIfEmpty($assessmentForm["assessment_description"]),
                    ":related_plo_codes" => nullIfEmpty($assessmentForm["related_plo_codes"]),
                    ":notes" => nullIfEmpty($assessmentForm["notes"]),
                    ":id" => (int)$assessmentForm["assessment_id"],
                    ":program_specification_id" => $spec_id
                ]);

                logActivitySafe($conn, "Update", "Program Specification Assessment Methods", (int)$assessmentForm["assessment_id"], "Updated assessment method: " . $assessmentForm["assessment_method"]);

                header("Location: manage_program_specification_teaching_assessment.php?id=" . $spec_id);
                exit;
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO program_specification_assessment_methods
                    (
                        program_specification_id,
                        assessment_method,
                        assessment_type,
                        assessment_description,
                        related_plo_codes,
                        notes
                    )
                    VALUES
                    (
                        :program_specification_id,
                        :assessment_method,
                        :assessment_type,
                        :assessment_description,
                        :related_plo_codes,
                        :notes
                    )
                ");

                $stmt->execute([
                    ":program_specification_id" => $spec_id,
                    ":assessment_method" => $assessmentForm["assessment_method"],
                    ":assessment_type" => nullIfEmpty($assessmentForm["assessment_type"]),
                    ":assessment_description" => nullIfEmpty($assessmentForm["assessment_description"]),
                    ":related_plo_codes" => nullIfEmpty($assessmentForm["related_plo_codes"]),
                    ":notes" => nullIfEmpty($assessmentForm["notes"])
                ]);

                logActivitySafe($conn, "Create", "Program Specification Assessment Methods", (int)$conn->lastInsertId(), "Created assessment method: " . $assessmentForm["assessment_method"]);

                header("Location: manage_program_specification_teaching_assessment.php?id=" . $spec_id);
                exit;
            }
        }
    }

    if ($formType === "mark") {
        foreach ($markForm as $key => $value) {
            $markForm[$key] = trim($_POST[$key] ?? "");
        }

        if ($markForm["course_type"] === "") {
            $error = "Course type is required.";
        } elseif ($markForm["assessment_method"] === "") {
            $error = "Assessment method is required.";
        } else {
            if ($markForm["mark_id"] !== "" && is_numeric($markForm["mark_id"])) {
                $stmt = $conn->prepare("
                    UPDATE program_specification_mark_distributions
                    SET
                        course_type = :course_type,
                        assessment_method = :assessment_method,
                        marks = :marks,
                        percentage = :percentage,
                        display_order = :display_order,
                        notes = :notes
                    WHERE id = :id
                      AND program_specification_id = :program_specification_id
                ");

                $stmt->execute([
                    ":course_type" => $markForm["course_type"],
                    ":assessment_method" => $markForm["assessment_method"],
                    ":marks" => numberValue($markForm["marks"]),
                    ":percentage" => numberValue($markForm["percentage"]),
                    ":display_order" => intValue($markForm["display_order"]),
                    ":notes" => nullIfEmpty($markForm["notes"]),
                    ":id" => (int)$markForm["mark_id"],
                    ":program_specification_id" => $spec_id
                ]);

                logActivitySafe($conn, "Update", "Program Specification Mark Distribution", (int)$markForm["mark_id"], "Updated mark distribution: " . $markForm["course_type"] . " - " . $markForm["assessment_method"]);

                header("Location: manage_program_specification_teaching_assessment.php?id=" . $spec_id);
                exit;
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO program_specification_mark_distributions
                    (
                        program_specification_id,
                        course_type,
                        assessment_method,
                        marks,
                        percentage,
                        display_order,
                        notes
                    )
                    VALUES
                    (
                        :program_specification_id,
                        :course_type,
                        :assessment_method,
                        :marks,
                        :percentage,
                        :display_order,
                        :notes
                    )
                ");

                $stmt->execute([
                    ":program_specification_id" => $spec_id,
                    ":course_type" => $markForm["course_type"],
                    ":assessment_method" => $markForm["assessment_method"],
                    ":marks" => numberValue($markForm["marks"]),
                    ":percentage" => numberValue($markForm["percentage"]),
                    ":display_order" => intValue($markForm["display_order"]),
                    ":notes" => nullIfEmpty($markForm["notes"])
                ]);

                logActivitySafe($conn, "Create", "Program Specification Mark Distribution", (int)$conn->lastInsertId(), "Created mark distribution: " . $markForm["course_type"] . " - " . $markForm["assessment_method"]);

                header("Location: manage_program_specification_teaching_assessment.php?id=" . $spec_id);
                exit;
            }
        }
    }
}

/* ===============================
   Load rows
   =============================== */
$teachingStmt = $conn->prepare("
    SELECT *
    FROM program_specification_teaching_strategies
    WHERE program_specification_id = :program_specification_id
    ORDER BY strategy_name ASC, id ASC
");
$teachingStmt->execute([":program_specification_id" => $spec_id]);
$teachingRows = $teachingStmt->fetchAll(PDO::FETCH_ASSOC);

$assessmentStmt = $conn->prepare("
    SELECT *
    FROM program_specification_assessment_methods
    WHERE program_specification_id = :program_specification_id
    ORDER BY assessment_type ASC, assessment_method ASC, id ASC
");
$assessmentStmt->execute([":program_specification_id" => $spec_id]);
$assessmentRows = $assessmentStmt->fetchAll(PDO::FETCH_ASSOC);

$markStmt = $conn->prepare("
    SELECT *
    FROM program_specification_mark_distributions
    WHERE program_specification_id = :program_specification_id
    ORDER BY course_type ASC, display_order ASC, id ASC
");
$markStmt->execute([":program_specification_id" => $spec_id]);
$markRows = $markStmt->fetchAll(PDO::FETCH_ASSOC);

$courseTypeOptions = [
    "Theoretical Course",
    "Practical Course",
    "Theory and Practical Course",
    "Training Course",
    "Graduation Project",
    "Internship",
    "Other"
];

$assessmentTypeOptions = [
    "Direct",
    "Indirect",
    "Direct and Indirect"
];

$curricularOptions = [
    "Curricular",
    "Extracurricular",
    "Curricular and Extracurricular"
];

$markTotals = [];
foreach ($markRows as $row) {
    $type = $row["course_type"] ?? "Other";
    if (!isset($markTotals[$type])) {
        $markTotals[$type] = 0;
    }
    $markTotals[$type] += (float)($row["marks"] ?? 0);
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Teaching and Assessment Strategies</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(150px, 1fr));
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 14px;
        }

        textarea {
            min-height: 105px;
        }

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
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

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .type-row {
            background: #f8f9fa;
            font-weight: bold;
        }

        @media (max-width: 900px) {
            .summary-grid,
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Teaching and Assessment Strategies</h1>
        <p>Manage teaching strategies, assessment methods, and mark distribution for this program specification.</p>
    </div>

    <div class="actions">
        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
            Back to Specification
        </a>

        <a href="program_specification_validation.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Validate Specification
        </a>

        <a href="import_default_hpe_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Import Default HPE Teaching / Assessment
        </a>
    </div>

    <div class="panel">
        <h2>Program Specification</h2>

        <table>
            <tr>
                <th>Specification</th>
                <td><?php echo safeText($spec["specification_title"] ?? ""); ?></td>
                <th>Program</th>
                <td><?php echo safeText($spec["program_name"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Program Code</th>
                <td><?php echo safeText($spec["program_code"] ?? ""); ?></td>
                <th>Qualification</th>
                <td><?php echo safeText($spec["qualification_level"] ?? ""); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($teachingRows)); ?></div>
            <div class="summary-label">Teaching Strategies</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($assessmentRows)); ?></div>
            <div class="summary-label">Assessment Methods</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($markRows)); ?></div>
            <div class="summary-label">Mark Distribution Rows</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($canEdit ? "Yes" : "No"); ?></div>
            <div class="summary-label">Editing Allowed</div>
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>

        <div class="panel">
            <h2><?php echo $teachingForm["teaching_id"] !== "" ? "Edit Teaching Strategy" : "Add Teaching Strategy"; ?></h2>

            <form method="POST" action="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>">
                <input type="hidden" name="form_type" value="teaching">
                <input type="hidden" name="teaching_id" value="<?php echo safeText($teachingForm["teaching_id"]); ?>">

                <div class="form-grid">
                    <div>
                        <label>Strategy Name <span style="color:red;">*</span></label>
                        <input type="text" name="strategy_name" required value="<?php echo safeText($teachingForm["strategy_name"]); ?>">
                    </div>

                    <div>
                        <label>Related PLO Codes</label>
                        <input type="text" name="related_plo_codes" value="<?php echo safeText($teachingForm["related_plo_codes"]); ?>" placeholder="Example: K1, K2, S1, S2">
                    </div>

                    <div>
                        <label>Curricular / Extra</label>
                        <select name="curricular_or_extra">
                            <?php foreach ($curricularOptions as $option): ?>
                                <option value="<?php echo safeText($option); ?>" <?php if ($teachingForm["curricular_or_extra"] === $option) echo "selected"; ?>>
                                    <?php echo safeText($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <label>Strategy Description</label>
                <textarea name="strategy_description"><?php echo safeText($teachingForm["strategy_description"]); ?></textarea>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($teachingForm["notes"]); ?></textarea>

                <br>

                <button type="submit" class="btn">
                    <?php echo $teachingForm["teaching_id"] !== "" ? "Update Teaching Strategy" : "Add Teaching Strategy"; ?>
                </button>

                <?php if ($teachingForm["teaching_id"] !== ""): ?>
                    <a href="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
                        Cancel Edit
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <div class="panel">
            <h2><?php echo $assessmentForm["assessment_id"] !== "" ? "Edit Assessment Method" : "Add Assessment Method"; ?></h2>

            <form method="POST" action="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>">
                <input type="hidden" name="form_type" value="assessment">
                <input type="hidden" name="assessment_id" value="<?php echo safeText($assessmentForm["assessment_id"]); ?>">

                <div class="form-grid">
                    <div>
                        <label>Assessment Method <span style="color:red;">*</span></label>
                        <input type="text" name="assessment_method" required value="<?php echo safeText($assessmentForm["assessment_method"]); ?>">
                    </div>

                    <div>
                        <label>Assessment Type</label>
                        <select name="assessment_type">
                            <?php foreach ($assessmentTypeOptions as $option): ?>
                                <option value="<?php echo safeText($option); ?>" <?php if ($assessmentForm["assessment_type"] === $option) echo "selected"; ?>>
                                    <?php echo safeText($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Related PLO Codes</label>
                        <input type="text" name="related_plo_codes" value="<?php echo safeText($assessmentForm["related_plo_codes"]); ?>" placeholder="Example: K1, S1, V1">
                    </div>
                </div>

                <label>Assessment Description</label>
                <textarea name="assessment_description"><?php echo safeText($assessmentForm["assessment_description"]); ?></textarea>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($assessmentForm["notes"]); ?></textarea>

                <br>

                <button type="submit" class="btn">
                    <?php echo $assessmentForm["assessment_id"] !== "" ? "Update Assessment Method" : "Add Assessment Method"; ?>
                </button>

                <?php if ($assessmentForm["assessment_id"] !== ""): ?>
                    <a href="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
                        Cancel Edit
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <div class="panel">
            <h2><?php echo $markForm["mark_id"] !== "" ? "Edit Mark Distribution" : "Add Mark Distribution"; ?></h2>

            <form method="POST" action="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>">
                <input type="hidden" name="form_type" value="mark">
                <input type="hidden" name="mark_id" value="<?php echo safeText($markForm["mark_id"]); ?>">

                <div class="form-grid">
                    <div>
                        <label>Course Type <span style="color:red;">*</span></label>
                        <select name="course_type" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($courseTypeOptions as $option): ?>
                                <option value="<?php echo safeText($option); ?>" <?php if ($markForm["course_type"] === $option) echo "selected"; ?>>
                                    <?php echo safeText($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Assessment Method <span style="color:red;">*</span></label>
                        <input type="text" name="assessment_method" required value="<?php echo safeText($markForm["assessment_method"]); ?>">
                    </div>

                    <div>
                        <label>Display Order</label>
                        <input type="number" name="display_order" value="<?php echo safeText($markForm["display_order"]); ?>">
                    </div>

                    <div>
                        <label>Marks</label>
                        <input type="number" step="0.01" name="marks" value="<?php echo safeText($markForm["marks"]); ?>">
                    </div>

                    <div>
                        <label>Percentage</label>
                        <input type="number" step="0.01" name="percentage" value="<?php echo safeText($markForm["percentage"]); ?>">
                    </div>
                </div>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($markForm["notes"]); ?></textarea>

                <br>

                <button type="submit" class="btn">
                    <?php echo $markForm["mark_id"] !== "" ? "Update Mark Distribution" : "Add Mark Distribution"; ?>
                </button>

                <?php if ($markForm["mark_id"] !== ""): ?>
                    <a href="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
                        Cancel Edit
                    </a>
                <?php endif; ?>
            </form>
        </div>

    <?php endif; ?>

    <div class="panel">
        <h2>Teaching Strategies</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Strategy</th>
                    <th>Description</th>
                    <th>Related PLOs</th>
                    <th>Curricular / Extra</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>

                <?php if (count($teachingRows) > 0): ?>
                    <?php foreach ($teachingRows as $row): ?>
                        <tr>
                            <td><?php echo safeText($row["strategy_name"] ?? ""); ?></td>
                            <td><?php echo nl2br(safeText($row["strategy_description"] ?? "")); ?></td>
                            <td><?php echo safeText($row["related_plo_codes"] ?? ""); ?></td>
                            <td><?php echo safeText($row["curricular_or_extra"] ?? ""); ?></td>
                            <td><?php echo nl2br(safeText($row["notes"] ?? "")); ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <a href="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>&edit_teaching=<?php echo safeText($row["id"]); ?>" class="btn btn-edit">Edit</a>
                                    <a href="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>&delete_teaching=<?php echo safeText($row["id"]); ?>" class="btn btn-delete" onclick="return confirm('Delete this teaching strategy?');">Delete</a>
                                <?php else: ?>
                                    <span class="small-text">View only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6">No teaching strategies entered yet.</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="panel">
        <h2>Assessment Methods</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Method</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Related PLOs</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>

                <?php if (count($assessmentRows) > 0): ?>
                    <?php foreach ($assessmentRows as $row): ?>
                        <tr>
                            <td><?php echo safeText($row["assessment_method"] ?? ""); ?></td>
                            <td><?php echo safeText($row["assessment_type"] ?? ""); ?></td>
                            <td><?php echo nl2br(safeText($row["assessment_description"] ?? "")); ?></td>
                            <td><?php echo safeText($row["related_plo_codes"] ?? ""); ?></td>
                            <td><?php echo nl2br(safeText($row["notes"] ?? "")); ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <a href="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>&edit_assessment=<?php echo safeText($row["id"]); ?>" class="btn btn-edit">Edit</a>
                                    <a href="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>&delete_assessment=<?php echo safeText($row["id"]); ?>" class="btn btn-delete" onclick="return confirm('Delete this assessment method?');">Delete</a>
                                <?php else: ?>
                                    <span class="small-text">View only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6">No assessment methods entered yet.</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="panel">
        <h2>Mark Distribution</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Course Type</th>
                    <th>Assessment Method</th>
                    <th>Marks</th>
                    <th>Percentage</th>
                    <th>Order</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>

                <?php if (count($markRows) > 0): ?>
                    <?php
                    $currentType = "";
                    ?>
                    <?php foreach ($markRows as $row): ?>
                        <?php if ($currentType !== $row["course_type"]): ?>
                            <?php
                            $currentType = $row["course_type"];
                            $typeTotal = $markTotals[$currentType] ?? 0;
                            ?>
                            <tr class="type-row">
                                <td colspan="7"><?php echo safeText($currentType); ?> | Total Marks: <?php echo safeText($typeTotal); ?></td>
                            </tr>
                        <?php endif; ?>

                        <tr>
                            <td><?php echo safeText($row["course_type"] ?? ""); ?></td>
                            <td><?php echo safeText($row["assessment_method"] ?? ""); ?></td>
                            <td><?php echo safeText($row["marks"] ?? ""); ?></td>
                            <td><?php echo safeText($row["percentage"] ?? ""); ?>%</td>
                            <td><?php echo safeText($row["display_order"] ?? ""); ?></td>
                            <td><?php echo nl2br(safeText($row["notes"] ?? "")); ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <a href="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>&edit_mark=<?php echo safeText($row["id"]); ?>" class="btn btn-edit">Edit</a>
                                    <a href="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>&delete_mark=<?php echo safeText($row["id"]); ?>" class="btn btn-delete" onclick="return confirm('Delete this mark distribution row?');">Delete</a>
                                <?php else: ?>
                                    <span class="small-text">View only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7">No mark distribution rows entered yet.</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
