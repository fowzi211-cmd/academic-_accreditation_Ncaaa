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

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "program_specification_evaluation_matrix")) {
    die("program_specification_evaluation_matrix table does not exist.");
}

/*
    Safety upgrade for older tables.
*/
addColumnIfMissing($conn, "program_specification_evaluation_matrix", "evaluation_area", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_evaluation_matrix", "evaluation_sources", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_evaluation_matrix", "evaluation_methods", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_evaluation_matrix", "evaluation_time", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_evaluation_matrix", "notes", "TEXT NULL");

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

$form = [
    "row_id" => "",
    "evaluation_area" => "",
    "evaluation_sources" => "",
    "evaluation_methods" => "",
    "evaluation_time" => "",
    "notes" => ""
];

if ($canEdit && isset($_GET["delete"]) && is_numeric($_GET["delete"])) {
    $delete_id = (int)$_GET["delete"];

    $loadStmt = $conn->prepare("
        SELECT *
        FROM program_specification_evaluation_matrix
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $loadStmt->execute([
        ":id" => $delete_id,
        ":program_specification_id" => $spec_id
    ]);
    $deleteRow = $loadStmt->fetch(PDO::FETCH_ASSOC);

    if ($deleteRow) {
        $deleteStmt = $conn->prepare("
            DELETE FROM program_specification_evaluation_matrix
            WHERE id = :id
              AND program_specification_id = :program_specification_id
        ");
        $deleteStmt->execute([
            ":id" => $delete_id,
            ":program_specification_id" => $spec_id
        ]);

        logActivitySafe(
            $conn,
            "Delete",
            "Program Specification Evaluation Matrix",
            $delete_id,
            "Deleted evaluation row: " . ($deleteRow["evaluation_area"] ?? "")
        );

        header("Location: manage_program_specification_evaluation.php?id=" . $spec_id);
        exit;
    }
}

if (isset($_GET["edit"]) && is_numeric($_GET["edit"])) {
    $edit_id = (int)$_GET["edit"];

    $editStmt = $conn->prepare("
        SELECT *
        FROM program_specification_evaluation_matrix
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $editStmt->execute([
        ":id" => $edit_id,
        ":program_specification_id" => $spec_id
    ]);
    $editRow = $editStmt->fetch(PDO::FETCH_ASSOC);

    if ($editRow) {
        $form = [
            "row_id" => $editRow["id"] ?? "",
            "evaluation_area" => $editRow["evaluation_area"] ?? "",
            "evaluation_sources" => $editRow["evaluation_sources"] ?? "",
            "evaluation_methods" => $editRow["evaluation_methods"] ?? "",
            "evaluation_time" => $editRow["evaluation_time"] ?? "",
            "notes" => $editRow["notes"] ?? ""
        ];
    }
}

if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? "");
    }

    if ($form["evaluation_area"] === "") {
        $error = "Evaluation area is required.";
    } else {
        if ($form["row_id"] !== "" && is_numeric($form["row_id"])) {
            $updateStmt = $conn->prepare("
                UPDATE program_specification_evaluation_matrix
                SET
                    evaluation_area = :evaluation_area,
                    evaluation_sources = :evaluation_sources,
                    evaluation_methods = :evaluation_methods,
                    evaluation_time = :evaluation_time,
                    notes = :notes
                WHERE id = :id
                  AND program_specification_id = :program_specification_id
            ");

            $updateStmt->execute([
                ":evaluation_area" => $form["evaluation_area"],
                ":evaluation_sources" => nullIfEmpty($form["evaluation_sources"]),
                ":evaluation_methods" => nullIfEmpty($form["evaluation_methods"]),
                ":evaluation_time" => nullIfEmpty($form["evaluation_time"]),
                ":notes" => nullIfEmpty($form["notes"]),
                ":id" => (int)$form["row_id"],
                ":program_specification_id" => $spec_id
            ]);

            logActivitySafe(
                $conn,
                "Update",
                "Program Specification Evaluation Matrix",
                (int)$form["row_id"],
                "Updated evaluation row: " . $form["evaluation_area"]
            );

            header("Location: manage_program_specification_evaluation.php?id=" . $spec_id);
            exit;
        } else {
            $insertStmt = $conn->prepare("
                INSERT INTO program_specification_evaluation_matrix
                (
                    program_specification_id,
                    evaluation_area,
                    evaluation_sources,
                    evaluation_methods,
                    evaluation_time,
                    notes
                )
                VALUES
                (
                    :program_specification_id,
                    :evaluation_area,
                    :evaluation_sources,
                    :evaluation_methods,
                    :evaluation_time,
                    :notes
                )
            ");

            $insertStmt->execute([
                ":program_specification_id" => $spec_id,
                ":evaluation_area" => $form["evaluation_area"],
                ":evaluation_sources" => nullIfEmpty($form["evaluation_sources"]),
                ":evaluation_methods" => nullIfEmpty($form["evaluation_methods"]),
                ":evaluation_time" => nullIfEmpty($form["evaluation_time"]),
                ":notes" => nullIfEmpty($form["notes"])
            ]);

            $newId = (int)$conn->lastInsertId();

            logActivitySafe(
                $conn,
                "Create",
                "Program Specification Evaluation Matrix",
                $newId,
                "Created evaluation row: " . $form["evaluation_area"]
            );

            header("Location: manage_program_specification_evaluation.php?id=" . $spec_id);
            exit;
        }
    }
}

$rowsStmt = $conn->prepare("
    SELECT *
    FROM program_specification_evaluation_matrix
    WHERE program_specification_id = :program_specification_id
    ORDER BY id ASC
");
$rowsStmt->execute([":program_specification_id" => $spec_id]);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

$areaOptions = [
    "Program Management",
    "Course Effectiveness",
    "Student Learning Outcomes",
    "Graduate Attributes",
    "Student Support Services",
    "Learning Resources",
    "Facilities and Equipment",
    "Teaching Staff Performance",
    "Employer Feedback",
    "Alumni Feedback",
    "Program KPIs",
    "Quality Assurance System",
    "Other"
];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Program Evaluation Matrix</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(160px, 1fr));
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
            grid-template-columns: repeat(2, minmax(220px, 1fr));
            gap: 14px;
        }

        textarea {
            min-height: 110px;
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

        .small-text {
            font-size: 12px;
            color: #555;
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
            min-width: 1000px;
            width: 100%;
            border-collapse: collapse;
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
        <h1>Manage Program Evaluation Matrix</h1>
        <p>Add and review evaluation areas, sources, methods, and timing for the program specification.</p>
    </div>

    <div class="actions">
        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
            Back to Specification
        </a>

        <a href="program_specification_validation.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Validate Specification
        </a>

        <a href="import_default_hpe_evaluation.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Import Default HPE Evaluation
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
            <div class="summary-number"><?php echo safeText(count($rows)); ?></div>
            <div class="summary-label">Evaluation Rows</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($canEdit ? "Yes" : "No"); ?></div>
            <div class="summary-label">Editing Allowed</div>
        </div>

        <div class="summary-card">
            <div class="summary-number">G</div>
            <div class="summary-label">Quality Assurance Section</div>
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <div class="panel">
            <h2><?php echo $form["row_id"] !== "" ? "Edit Evaluation Row" : "Add Evaluation Row"; ?></h2>

            <form method="POST" action="manage_program_specification_evaluation.php?id=<?php echo safeText($spec_id); ?>">

                <input type="hidden" name="row_id" value="<?php echo safeText($form["row_id"]); ?>">

                <div class="form-grid">
                    <div>
                        <label>Evaluation Area <span style="color:red;">*</span></label>
                        <select name="evaluation_area" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($areaOptions as $option): ?>
                                <option value="<?php echo safeText($option); ?>"
                                    <?php if ($form["evaluation_area"] === $option) echo "selected"; ?>>
                                    <?php echo safeText($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Evaluation Time</label>
                        <input type="text"
                               name="evaluation_time"
                               value="<?php echo safeText($form["evaluation_time"]); ?>"
                               placeholder="Example: End of each semester / Annually">
                    </div>
                </div>

                <label>Evaluation Sources</label>
                <textarea name="evaluation_sources" placeholder="Example: Students, faculty members, alumni, employers, course reports"><?php echo safeText($form["evaluation_sources"]); ?></textarea>

                <label>Evaluation Methods</label>
                <textarea name="evaluation_methods" placeholder="Example: Surveys, course reports, KPI analysis, direct assessment, committee review"><?php echo safeText($form["evaluation_methods"]); ?></textarea>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($form["notes"]); ?></textarea>

                <br>

                <button type="submit" class="btn">
                    <?php echo $form["row_id"] !== "" ? "Update Row" : "Add Row"; ?>
                </button>

                <?php if ($form["row_id"] !== ""): ?>
                    <a href="manage_program_specification_evaluation.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
                        Cancel Edit
                    </a>
                <?php endif; ?>

            </form>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Program Evaluation Matrix Rows</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Evaluation Area</th>
                    <th>Evaluation Sources</th>
                    <th>Evaluation Methods</th>
                    <th>Evaluation Time</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>

                <?php if (count($rows) > 0): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo safeText($row["evaluation_area"] ?? ""); ?></td>
                            <td><?php echo nl2br(safeText($row["evaluation_sources"] ?? "")); ?></td>
                            <td><?php echo nl2br(safeText($row["evaluation_methods"] ?? "")); ?></td>
                            <td><?php echo safeText($row["evaluation_time"] ?? ""); ?></td>
                            <td><?php echo nl2br(safeText($row["notes"] ?? "")); ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <a href="manage_program_specification_evaluation.php?id=<?php echo safeText($spec_id); ?>&edit=<?php echo safeText($row["id"]); ?>" class="btn btn-edit">
                                        Edit
                                    </a>

                                    <a href="manage_program_specification_evaluation.php?id=<?php echo safeText($spec_id); ?>&delete=<?php echo safeText($row["id"]); ?>"
                                       class="btn btn-delete"
                                       onclick="return confirm('Delete this evaluation row?');">
                                        Delete
                                    </a>
                                <?php else: ?>
                                    <span class="small-text">View only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6">No evaluation matrix rows entered yet.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
