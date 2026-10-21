<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

$spec_id = isset($_GET["id"]) && is_numeric($_GET["id"]) ? (int)$_GET["id"] : 3;
$error = "";
$success = "";

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

if (!tableExists($conn, "program_specification_archive")) {
    requireSchemaTable($conn, "program_specification_archive");
}

addColumnIfMissing($conn, "program_specification_archive", "program_specification_id", "INT NOT NULL");
addColumnIfMissing($conn, "program_specification_archive", "archive_title", "VARCHAR(255) NOT NULL");
addColumnIfMissing($conn, "program_specification_archive", "archive_version", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "program_specification_archive", "archive_date", "DATE NULL");
addColumnIfMissing($conn, "program_specification_archive", "readiness_decision", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "program_specification_archive", "approval_status", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "program_specification_archive", "specification_exported", "TINYINT(1) DEFAULT 0");
addColumnIfMissing($conn, "program_specification_archive", "validation_exported", "TINYINT(1) DEFAULT 0");
addColumnIfMissing($conn, "program_specification_archive", "evidence_matrix_exported", "TINYINT(1) DEFAULT 0");
addColumnIfMissing($conn, "program_specification_archive", "readiness_exported", "TINYINT(1) DEFAULT 0");
addColumnIfMissing($conn, "program_specification_archive", "specification_export_date", "DATE NULL");
addColumnIfMissing($conn, "program_specification_archive", "validation_export_date", "DATE NULL");
addColumnIfMissing($conn, "program_specification_archive", "evidence_matrix_export_date", "DATE NULL");
addColumnIfMissing($conn, "program_specification_archive", "readiness_export_date", "DATE NULL");
addColumnIfMissing($conn, "program_specification_archive", "archive_notes", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_archive", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

$statusSelect = "NULL AS approval_status_value";

if (columnExists($conn, "program_specifications", "approval_status")) {
    $statusSelect = "approval_status AS approval_status_value";
} elseif (columnExists($conn, "program_specifications", "status")) {
    $statusSelect = "status AS approval_status_value";
}

$specStmt = $conn->prepare("
    SELECT *, $statusSelect
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
    "archive_id" => "",
    "archive_title" => "",
    "archive_version" => "",
    "archive_date" => date("Y-m-d"),
    "readiness_decision" => "",
    "approval_status" => $spec["approval_status_value"] ?? "",
    "specification_exported" => "1",
    "validation_exported" => "1",
    "evidence_matrix_exported" => "1",
    "readiness_exported" => "1",
    "specification_export_date" => date("Y-m-d"),
    "validation_export_date" => date("Y-m-d"),
    "evidence_matrix_export_date" => date("Y-m-d"),
    "readiness_export_date" => date("Y-m-d"),
    "archive_notes" => ""
];

if ($form["archive_title"] === "") {
    $programName = $spec["program_name"] ?? "Program Specification";
    $programCode = $spec["program_code"] ?? "";
    $form["archive_title"] = trim($programName . " " . $programCode . " Archive");
}

/* Delete archive record */
if ($canEdit && isset($_GET["delete"]) && is_numeric($_GET["delete"])) {
    $delete_id = (int)$_GET["delete"];

    $loadStmt = $conn->prepare("
        SELECT *
        FROM program_specification_archive
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
            DELETE FROM program_specification_archive
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
            "Program Specification Archive",
            $delete_id,
            "Deleted archive record: " . ($row["archive_title"] ?? "")
        );

        header("Location: program_specification_archive.php?id=" . $spec_id);
        exit;
    }
}

/* Edit archive record */
if (isset($_GET["edit"]) && is_numeric($_GET["edit"])) {
    $edit_id = (int)$_GET["edit"];

    $editStmt = $conn->prepare("
        SELECT *
        FROM program_specification_archive
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
        foreach ($form as $key => $value) {
            $form[$key] = $row[$key] ?? $value;
        }
        $form["archive_id"] = $row["id"] ?? "";
    }
}

/* Save */
if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? "");
    }

    $form["specification_exported"] = isset($_POST["specification_exported"]) ? "1" : "0";
    $form["validation_exported"] = isset($_POST["validation_exported"]) ? "1" : "0";
    $form["evidence_matrix_exported"] = isset($_POST["evidence_matrix_exported"]) ? "1" : "0";
    $form["readiness_exported"] = isset($_POST["readiness_exported"]) ? "1" : "0";

    if ($form["archive_title"] === "") {
        $error = "Archive title is required.";
    } else {
        if ($form["archive_id"] !== "" && is_numeric($form["archive_id"])) {
            $stmt = $conn->prepare("
                UPDATE program_specification_archive
                SET
                    archive_title = :archive_title,
                    archive_version = :archive_version,
                    archive_date = :archive_date,
                    readiness_decision = :readiness_decision,
                    approval_status = :approval_status,
                    specification_exported = :specification_exported,
                    validation_exported = :validation_exported,
                    evidence_matrix_exported = :evidence_matrix_exported,
                    readiness_exported = :readiness_exported,
                    specification_export_date = :specification_export_date,
                    validation_export_date = :validation_export_date,
                    evidence_matrix_export_date = :evidence_matrix_export_date,
                    readiness_export_date = :readiness_export_date,
                    archive_notes = :archive_notes
                WHERE id = :id
                  AND program_specification_id = :program_specification_id
            ");

            $stmt->execute([
                ":archive_title" => $form["archive_title"],
                ":archive_version" => nullIfEmpty($form["archive_version"]),
                ":archive_date" => nullIfEmpty($form["archive_date"]),
                ":readiness_decision" => nullIfEmpty($form["readiness_decision"]),
                ":approval_status" => nullIfEmpty($form["approval_status"]),
                ":specification_exported" => (int)$form["specification_exported"],
                ":validation_exported" => (int)$form["validation_exported"],
                ":evidence_matrix_exported" => (int)$form["evidence_matrix_exported"],
                ":readiness_exported" => (int)$form["readiness_exported"],
                ":specification_export_date" => nullIfEmpty($form["specification_export_date"]),
                ":validation_export_date" => nullIfEmpty($form["validation_export_date"]),
                ":evidence_matrix_export_date" => nullIfEmpty($form["evidence_matrix_export_date"]),
                ":readiness_export_date" => nullIfEmpty($form["readiness_export_date"]),
                ":archive_notes" => nullIfEmpty($form["archive_notes"]),
                ":id" => (int)$form["archive_id"],
                ":program_specification_id" => $spec_id
            ]);

            logActivitySafe(
                $conn,
                "Update",
                "Program Specification Archive",
                (int)$form["archive_id"],
                "Updated archive record: " . $form["archive_title"]
            );

            header("Location: program_specification_archive.php?id=" . $spec_id . "&saved=1");
            exit;
        } else {
            $stmt = $conn->prepare("
                INSERT INTO program_specification_archive
                (
                    program_specification_id,
                    archive_title,
                    archive_version,
                    archive_date,
                    readiness_decision,
                    approval_status,
                    specification_exported,
                    validation_exported,
                    evidence_matrix_exported,
                    readiness_exported,
                    specification_export_date,
                    validation_export_date,
                    evidence_matrix_export_date,
                    readiness_export_date,
                    archive_notes
                )
                VALUES
                (
                    :program_specification_id,
                    :archive_title,
                    :archive_version,
                    :archive_date,
                    :readiness_decision,
                    :approval_status,
                    :specification_exported,
                    :validation_exported,
                    :evidence_matrix_exported,
                    :readiness_exported,
                    :specification_export_date,
                    :validation_export_date,
                    :evidence_matrix_export_date,
                    :readiness_export_date,
                    :archive_notes
                )
            ");

            $stmt->execute([
                ":program_specification_id" => $spec_id,
                ":archive_title" => $form["archive_title"],
                ":archive_version" => nullIfEmpty($form["archive_version"]),
                ":archive_date" => nullIfEmpty($form["archive_date"]),
                ":readiness_decision" => nullIfEmpty($form["readiness_decision"]),
                ":approval_status" => nullIfEmpty($form["approval_status"]),
                ":specification_exported" => (int)$form["specification_exported"],
                ":validation_exported" => (int)$form["validation_exported"],
                ":evidence_matrix_exported" => (int)$form["evidence_matrix_exported"],
                ":readiness_exported" => (int)$form["readiness_exported"],
                ":specification_export_date" => nullIfEmpty($form["specification_export_date"]),
                ":validation_export_date" => nullIfEmpty($form["validation_export_date"]),
                ":evidence_matrix_export_date" => nullIfEmpty($form["evidence_matrix_export_date"]),
                ":readiness_export_date" => nullIfEmpty($form["readiness_export_date"]),
                ":archive_notes" => nullIfEmpty($form["archive_notes"])
            ]);

            $newId = (int)$conn->lastInsertId();

            logActivitySafe(
                $conn,
                "Create",
                "Program Specification Archive",
                $newId,
                "Created archive record: " . $form["archive_title"]
            );

            header("Location: program_specification_archive.php?id=" . $spec_id . "&saved=1");
            exit;
        }
    }
}

if (isset($_GET["saved"])) {
    $success = "Archive record saved successfully.";
}

$rowsStmt = $conn->prepare("
    SELECT *
    FROM program_specification_archive
    WHERE program_specification_id = :program_specification_id
    ORDER BY archive_date DESC, id DESC
");
$rowsStmt->execute([":program_specification_id" => $spec_id]);
$archiveRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalArchives = count($archiveRows);
$completeArchives = 0;

foreach ($archiveRows as $row) {
    if (
        (int)($row["specification_exported"] ?? 0) === 1 &&
        (int)($row["validation_exported"] ?? 0) === 1 &&
        (int)($row["evidence_matrix_exported"] ?? 0) === 1 &&
        (int)($row["readiness_exported"] ?? 0) === 1
    ) {
        $completeArchives++;
    }
}

$readinessOptions = [
    "",
    "Ready for Accreditation Review",
    "Ready with Minor Review",
    "Not Ready"
];

$approvalOptions = [
    "",
    "Draft",
    "Under Review",
    "Approved",
    "Archived",
    "Needs Revision"
];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Program Specification Archive</title>
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

        .check-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 12px;
            margin-bottom: 12px;
        }

        .check-box {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
        }

        textarea {
            min-height: 100px;
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

        .alert-success {
            background: #d1e7dd;
            border: 1px solid #badbcc;
            color: #0f5132;
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
            min-width: 1200px;
            width: 100%;
            border-collapse: collapse;
        }

        .badge-complete {
            background: #d1e7dd;
            color: #0f5132;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-partial {
            background: #fff3cd;
            color: #664d03;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        @media (max-width: 1000px) {
            .summary-grid,
            .form-grid,
            .check-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Program Specification Archive</h1>
        <p>Record finalized exports, approval status, readiness decision, and version notes.</p>
    </div>

    <div class="actions">
        <a href="program_specification_dashboard.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">Dashboard</a>
        <a href="program_specification_exports.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">Exports</a>
        <a href="program_specification_readiness.php?id=<?php echo safeText($spec_id); ?>" class="btn">Readiness</a>
        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">Back to Specification</a>
	<a href="export_program_specification_archive_word.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Export Archive
</a>
    </div>

    <div class="panel">
        <h2>Program Specification</h2>

        <table>
            <tr>
                <th>Specification ID</th>
                <td><?php echo safeText($spec_id); ?></td>
                <th>Current Approval Status</th>
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
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalArchives); ?></div>
            <div class="summary-label">Archive Records</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($completeArchives); ?></div>
            <div class="summary-label">Complete Export Sets</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalArchives - $completeArchives); ?></div>
            <div class="summary-label">Partial Export Sets</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(date("Y")); ?></div>
            <div class="summary-label">Current Year</div>
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="alert-success">
            <?php echo safeText($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <div class="panel">
            <h2><?php echo $form["archive_id"] !== "" ? "Edit Archive Record" : "Create Archive Record"; ?></h2>

            <form method="POST" action="program_specification_archive.php?id=<?php echo safeText($spec_id); ?>">

                <input type="hidden" name="archive_id" value="<?php echo safeText($form["archive_id"]); ?>">

                <div class="form-grid">
                    <div>
                        <label>Archive Title <span style="color:red;">*</span></label>
                        <input type="text" name="archive_title" required value="<?php echo safeText($form["archive_title"]); ?>">
                    </div>

                    <div>
                        <label>Archive Version</label>
                        <input type="text" name="archive_version" value="<?php echo safeText($form["archive_version"]); ?>" placeholder="Example: v1.0 / 2026 Final">
                    </div>

                    <div>
                        <label>Archive Date</label>
                        <input type="date" name="archive_date" value="<?php echo safeText($form["archive_date"]); ?>">
                    </div>

                    <div>
                        <label>Readiness Decision</label>
                        <select name="readiness_decision">
                            <?php foreach ($readinessOptions as $option): ?>
                                <option value="<?php echo safeText($option); ?>" <?php if ($form["readiness_decision"] === $option) echo "selected"; ?>>
                                    <?php echo $option === "" ? "-- Select --" : safeText($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Approval Status</label>
                        <select name="approval_status">
                            <?php foreach ($approvalOptions as $option): ?>
                                <option value="<?php echo safeText($option); ?>" <?php if ($form["approval_status"] === $option) echo "selected"; ?>>
                                    <?php echo $option === "" ? "-- Select --" : safeText($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <h3>Exported Documents</h3>

                <div class="check-grid">
                    <div class="check-box">
                        <label>
                            <input type="checkbox" name="specification_exported" value="1" <?php if ((int)$form["specification_exported"] === 1) echo "checked"; ?>>
                            Full Specification Exported
                        </label>
                        <input type="date" name="specification_export_date" value="<?php echo safeText($form["specification_export_date"]); ?>">
                    </div>

                    <div class="check-box">
                        <label>
                            <input type="checkbox" name="validation_exported" value="1" <?php if ((int)$form["validation_exported"] === 1) echo "checked"; ?>>
                            Validation Report Exported
                        </label>
                        <input type="date" name="validation_export_date" value="<?php echo safeText($form["validation_export_date"]); ?>">
                    </div>

                    <div class="check-box">
                        <label>
                            <input type="checkbox" name="evidence_matrix_exported" value="1" <?php if ((int)$form["evidence_matrix_exported"] === 1) echo "checked"; ?>>
                            Evidence Matrix Exported
                        </label>
                        <input type="date" name="evidence_matrix_export_date" value="<?php echo safeText($form["evidence_matrix_export_date"]); ?>">
                    </div>

                    <div class="check-box">
                        <label>
                            <input type="checkbox" name="readiness_exported" value="1" <?php if ((int)$form["readiness_exported"] === 1) echo "checked"; ?>>
                            Readiness Report Exported
                        </label>
                        <input type="date" name="readiness_export_date" value="<?php echo safeText($form["readiness_export_date"]); ?>">
                    </div>
                </div>

                <label>Archive Notes</label>
                <textarea name="archive_notes" placeholder="Write version notes, approval notes, file location, or submission comments."><?php echo safeText($form["archive_notes"]); ?></textarea>

                <br>

                <button type="submit" class="btn">
                    <?php echo $form["archive_id"] !== "" ? "Update Archive Record" : "Create Archive Record"; ?>
                </button>

                <?php if ($form["archive_id"] !== ""): ?>
                    <a href="program_specification_archive.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
                        Cancel Edit
                    </a>
                <?php endif; ?>

            </form>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Archive Records</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Archive Title</th>
                    <th>Version</th>
                    <th>Archive Date</th>
                    <th>Readiness Decision</th>
                    <th>Approval Status</th>
                    <th>Export Set</th>
                    <th>Export Dates</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>

                <?php if (count($archiveRows) > 0): ?>
                    <?php foreach ($archiveRows as $row): ?>
                        <?php
                        $isComplete = (
                            (int)($row["specification_exported"] ?? 0) === 1 &&
                            (int)($row["validation_exported"] ?? 0) === 1 &&
                            (int)($row["evidence_matrix_exported"] ?? 0) === 1 &&
                            (int)($row["readiness_exported"] ?? 0) === 1
                        );
                        ?>
                        <tr>
                            <td><?php echo safeText($row["archive_title"] ?? ""); ?></td>
                            <td><?php echo safeText($row["archive_version"] ?? ""); ?></td>
                            <td><?php echo safeText($row["archive_date"] ?? ""); ?></td>
                            <td><?php echo safeText($row["readiness_decision"] ?? ""); ?></td>
                            <td><?php echo safeText($row["approval_status"] ?? ""); ?></td>
                            <td>
                                <span class="<?php echo $isComplete ? 'badge-complete' : 'badge-partial'; ?>">
                                    <?php echo $isComplete ? "Complete" : "Partial"; ?>
                                </span>
                                <br>
                                <span class="small-text">
                                    Specification: <?php echo (int)($row["specification_exported"] ?? 0) === 1 ? "Yes" : "No"; ?><br>
                                    Validation: <?php echo (int)($row["validation_exported"] ?? 0) === 1 ? "Yes" : "No"; ?><br>
                                    Evidence: <?php echo (int)($row["evidence_matrix_exported"] ?? 0) === 1 ? "Yes" : "No"; ?><br>
                                    Readiness: <?php echo (int)($row["readiness_exported"] ?? 0) === 1 ? "Yes" : "No"; ?>
                                </span>
                            </td>
                            <td>
                                <span class="small-text">
                                    Specification: <?php echo safeText($row["specification_export_date"] ?? ""); ?><br>
                                    Validation: <?php echo safeText($row["validation_export_date"] ?? ""); ?><br>
                                    Evidence: <?php echo safeText($row["evidence_matrix_export_date"] ?? ""); ?><br>
                                    Readiness: <?php echo safeText($row["readiness_export_date"] ?? ""); ?>
                                </span>
                            </td>
                            <td><?php echo nl2br(safeText($row["archive_notes"] ?? "")); ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <a href="program_specification_archive.php?id=<?php echo safeText($spec_id); ?>&edit=<?php echo safeText($row["id"]); ?>" class="btn btn-edit">
                                        Edit
                                    </a>

                                    <a href="program_specification_archive.php?id=<?php echo safeText($spec_id); ?>&delete=<?php echo safeText($row["id"]); ?>"
                                       class="btn btn-delete"
                                       onclick="return confirm('Delete this archive record?');">
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
                        <td colspan="9">No archive records created yet.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
