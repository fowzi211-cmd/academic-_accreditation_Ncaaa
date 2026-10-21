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

function intValue($value) {
    $value = trim((string)$value);
    if ($value === "") {
        return 0;
    }

    return is_numeric($value) ? (int)$value : 0;
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "program_specification_staffing_needs")) {
    die("program_specification_staffing_needs table does not exist.");
}

/*
    Safety upgrade for older tables.
*/
addColumnIfMissing($conn, "program_specification_staffing_needs", "academic_rank", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_staffing_needs", "general_specialty", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_staffing_needs", "specific_specialty", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_staffing_needs", "special_requirements", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_staffing_needs", "required_male", "INT DEFAULT 0");
addColumnIfMissing($conn, "program_specification_staffing_needs", "required_female", "INT DEFAULT 0");
addColumnIfMissing($conn, "program_specification_staffing_needs", "required_total", "INT DEFAULT 0");
addColumnIfMissing($conn, "program_specification_staffing_needs", "notes", "TEXT NULL");

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
    "staffing_id" => "",
    "academic_rank" => "",
    "general_specialty" => "",
    "specific_specialty" => "",
    "special_requirements" => "",
    "required_male" => "0",
    "required_female" => "0",
    "required_total" => "0",
    "notes" => ""
];

/* ===============================
   Delete
   =============================== */
if ($canEdit && isset($_GET["delete"]) && is_numeric($_GET["delete"])) {
    $delete_id = (int)$_GET["delete"];

    $loadStmt = $conn->prepare("
        SELECT *
        FROM program_specification_staffing_needs
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
            DELETE FROM program_specification_staffing_needs
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
            "Program Specification Staffing Needs",
            $delete_id,
            "Deleted staffing need: " . ($row["academic_rank"] ?? "")
        );

        header("Location: manage_program_specification_staffing.php?id=" . $spec_id);
        exit;
    }
}

/* ===============================
   Load edit
   =============================== */
if (isset($_GET["edit"]) && is_numeric($_GET["edit"])) {
    $edit_id = (int)$_GET["edit"];

    $editStmt = $conn->prepare("
        SELECT *
        FROM program_specification_staffing_needs
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
        $form = [
            "staffing_id" => $row["id"] ?? "",
            "academic_rank" => $row["academic_rank"] ?? "",
            "general_specialty" => $row["general_specialty"] ?? "",
            "specific_specialty" => $row["specific_specialty"] ?? "",
            "special_requirements" => $row["special_requirements"] ?? "",
            "required_male" => $row["required_male"] ?? "0",
            "required_female" => $row["required_female"] ?? "0",
            "required_total" => $row["required_total"] ?? "0",
            "notes" => $row["notes"] ?? ""
        ];
    }
}

/* ===============================
   Save
   =============================== */
if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? "");
    }

    if ($form["academic_rank"] === "") {
        $error = "Academic rank / role is required.";
    } else {
        $requiredMale = intValue($form["required_male"]);
        $requiredFemale = intValue($form["required_female"]);
        $requiredTotal = intValue($form["required_total"]);

        if ($requiredTotal <= 0) {
            $requiredTotal = $requiredMale + $requiredFemale;
        }

        if ($form["staffing_id"] !== "" && is_numeric($form["staffing_id"])) {
            $updateStmt = $conn->prepare("
                UPDATE program_specification_staffing_needs
                SET
                    academic_rank = :academic_rank,
                    general_specialty = :general_specialty,
                    specific_specialty = :specific_specialty,
                    special_requirements = :special_requirements,
                    required_male = :required_male,
                    required_female = :required_female,
                    required_total = :required_total,
                    notes = :notes
                WHERE id = :id
                  AND program_specification_id = :program_specification_id
            ");

            $updateStmt->execute([
                ":academic_rank" => $form["academic_rank"],
                ":general_specialty" => nullIfEmpty($form["general_specialty"]),
                ":specific_specialty" => nullIfEmpty($form["specific_specialty"]),
                ":special_requirements" => nullIfEmpty($form["special_requirements"]),
                ":required_male" => $requiredMale,
                ":required_female" => $requiredFemale,
                ":required_total" => $requiredTotal,
                ":notes" => nullIfEmpty($form["notes"]),
                ":id" => (int)$form["staffing_id"],
                ":program_specification_id" => $spec_id
            ]);

            logActivitySafe(
                $conn,
                "Update",
                "Program Specification Staffing Needs",
                (int)$form["staffing_id"],
                "Updated staffing need: " . $form["academic_rank"]
            );

            header("Location: manage_program_specification_staffing.php?id=" . $spec_id);
            exit;
        } else {
            $insertStmt = $conn->prepare("
                INSERT INTO program_specification_staffing_needs
                (
                    program_specification_id,
                    academic_rank,
                    general_specialty,
                    specific_specialty,
                    special_requirements,
                    required_male,
                    required_female,
                    required_total,
                    notes
                )
                VALUES
                (
                    :program_specification_id,
                    :academic_rank,
                    :general_specialty,
                    :specific_specialty,
                    :special_requirements,
                    :required_male,
                    :required_female,
                    :required_total,
                    :notes
                )
            ");

            $insertStmt->execute([
                ":program_specification_id" => $spec_id,
                ":academic_rank" => $form["academic_rank"],
                ":general_specialty" => nullIfEmpty($form["general_specialty"]),
                ":specific_specialty" => nullIfEmpty($form["specific_specialty"]),
                ":special_requirements" => nullIfEmpty($form["special_requirements"]),
                ":required_male" => $requiredMale,
                ":required_female" => $requiredFemale,
                ":required_total" => $requiredTotal,
                ":notes" => nullIfEmpty($form["notes"])
            ]);

            $newId = (int)$conn->lastInsertId();

            logActivitySafe(
                $conn,
                "Create",
                "Program Specification Staffing Needs",
                $newId,
                "Created staffing need: " . $form["academic_rank"]
            );

            header("Location: manage_program_specification_staffing.php?id=" . $spec_id);
            exit;
        }
    }
}

/* ===============================
   Load list
   =============================== */
$rowsStmt = $conn->prepare("
    SELECT *
    FROM program_specification_staffing_needs
    WHERE program_specification_id = :program_specification_id
    ORDER BY
        CASE
            WHEN academic_rank LIKE '%Professor%' THEN 1
            WHEN academic_rank LIKE '%Associate%' THEN 2
            WHEN academic_rank LIKE '%Assistant%' THEN 3
            WHEN academic_rank LIKE '%Lecturer%' THEN 4
            WHEN academic_rank LIKE '%Teaching Assistant%' THEN 5
            WHEN academic_rank LIKE '%Technician%' THEN 6
            WHEN academic_rank LIKE '%Administrative%' THEN 7
            ELSE 8
        END,
        id ASC
");
$rowsStmt->execute([":program_specification_id" => $spec_id]);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalMale = 0;
$totalFemale = 0;
$totalRequired = 0;

foreach ($rows as $row) {
    $totalMale += (int)($row["required_male"] ?? 0);
    $totalFemale += (int)($row["required_female"] ?? 0);
    $totalRequired += (int)($row["required_total"] ?? 0);
}

$rankOptions = [
    "Professor",
    "Associate Professor",
    "Assistant Professor",
    "Lecturer",
    "Teaching Assistant",
    "Laboratory Technician",
    "Administrative Staff",
    "Program Coordinator",
    "Quality Coordinator",
    "Academic Advisor",
    "Other"
];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Program Staffing Needs</title>
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
            min-width: 1100px;
            width: 100%;
            border-collapse: collapse;
        }

        .total-row {
            background: #f8f9fa;
            font-weight: bold;
        }

        .small-text {
            font-size: 12px;
            color: #555;
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
        <h1>Manage Program Staffing Needs</h1>
        <p>Manage academic, technical, and administrative staffing requirements for this program specification.</p>
    </div>

    <div class="actions">
        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
            Back to Specification
        </a>

        <a href="program_specification_validation.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Validate Specification
        </a>

        <a href="import_default_hpe_staffing.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Import Default HPE Staffing
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
            <div class="summary-label">Staffing Rows</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalMale); ?></div>
            <div class="summary-label">Required Male Staff</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalFemale); ?></div>
            <div class="summary-label">Required Female Staff</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalRequired); ?></div>
            <div class="summary-label">Total Required Staff</div>
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <div class="panel">
            <h2><?php echo $form["staffing_id"] !== "" ? "Edit Staffing Need" : "Add Staffing Need"; ?></h2>

            <form method="POST" action="manage_program_specification_staffing.php?id=<?php echo safeText($spec_id); ?>">

                <input type="hidden" name="staffing_id" value="<?php echo safeText($form["staffing_id"]); ?>">

                <div class="form-grid">
                    <div>
                        <label>Academic Rank / Role <span style="color:red;">*</span></label>
                        <select name="academic_rank" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($rankOptions as $option): ?>
                                <option value="<?php echo safeText($option); ?>"
                                    <?php if ($form["academic_rank"] === $option) echo "selected"; ?>>
                                    <?php echo safeText($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Required Male</label>
                        <input type="number" name="required_male" value="<?php echo safeText($form["required_male"]); ?>">
                    </div>

                    <div>
                        <label>Required Female</label>
                        <input type="number" name="required_female" value="<?php echo safeText($form["required_female"]); ?>">
                    </div>

                    <div>
                        <label>Total Required</label>
                        <input type="number" name="required_total" value="<?php echo safeText($form["required_total"]); ?>">
                    </div>
                </div>

                <label>General Specialty</label>
                <textarea name="general_specialty" placeholder="Example: Public Health, Health Promotion, Epidemiology"><?php echo safeText($form["general_specialty"]); ?></textarea>

                <label>Specific Specialty</label>
                <textarea name="specific_specialty" placeholder="Example: Health Promotion, Health Education, Community Health, Behavioral Sciences"><?php echo safeText($form["specific_specialty"]); ?></textarea>

                <label>Special Requirements</label>
                <textarea name="special_requirements" placeholder="Example: PhD preferred, teaching experience, research activity, professional certification"><?php echo safeText($form["special_requirements"]); ?></textarea>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($form["notes"]); ?></textarea>

                <br>

                <button type="submit" class="btn">
                    <?php echo $form["staffing_id"] !== "" ? "Update Staffing Need" : "Add Staffing Need"; ?>
                </button>

                <?php if ($form["staffing_id"] !== ""): ?>
                    <a href="manage_program_specification_staffing.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
                        Cancel Edit
                    </a>
                <?php endif; ?>

            </form>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Staffing Needs</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Academic Rank / Role</th>
                    <th>General Specialty</th>
                    <th>Specific Specialty</th>
                    <th>Special Requirements</th>
                    <th>Male</th>
                    <th>Female</th>
                    <th>Total</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>

                <?php if (count($rows) > 0): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo safeText($row["academic_rank"] ?? ""); ?></td>
                            <td><?php echo nl2br(safeText($row["general_specialty"] ?? "")); ?></td>
                            <td><?php echo nl2br(safeText($row["specific_specialty"] ?? "")); ?></td>
                            <td><?php echo nl2br(safeText($row["special_requirements"] ?? "")); ?></td>
                            <td><?php echo safeText($row["required_male"] ?? "0"); ?></td>
                            <td><?php echo safeText($row["required_female"] ?? "0"); ?></td>
                            <td><?php echo safeText($row["required_total"] ?? "0"); ?></td>
                            <td><?php echo nl2br(safeText($row["notes"] ?? "")); ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <a href="manage_program_specification_staffing.php?id=<?php echo safeText($spec_id); ?>&edit=<?php echo safeText($row["id"]); ?>" class="btn btn-edit">
                                        Edit
                                    </a>

                                    <a href="manage_program_specification_staffing.php?id=<?php echo safeText($spec_id); ?>&delete=<?php echo safeText($row["id"]); ?>"
                                       class="btn btn-delete"
                                       onclick="return confirm('Delete this staffing need?');">
                                        Delete
                                    </a>
                                <?php else: ?>
                                    <span class="small-text">View only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr class="total-row">
                        <td colspan="4">Total</td>
                        <td><?php echo safeText($totalMale); ?></td>
                        <td><?php echo safeText($totalFemale); ?></td>
                        <td><?php echo safeText($totalRequired); ?></td>
                        <td colspan="2"></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="9">No staffing needs entered yet.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
