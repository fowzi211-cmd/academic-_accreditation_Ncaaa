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
        return 0;
    }
    return is_numeric($value) ? (int)$value : 0;
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

function addColumnIfMissing($conn, $tableName, $columnName, $definition) {
    if (!columnExists($conn, $tableName, $columnName)) {
        requireSchemaColumn($conn, $tableName, $columnName);
    }
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "program_specification_curriculum_structure")) {
    die("program_specification_curriculum_structure table does not exist.");
}

/*
    Safety upgrade for older tables.
    This prevents old-schema crashes.
*/
addColumnIfMissing($conn, "program_specification_curriculum_structure", "structure_category", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_curriculum_structure", "requirement_type", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "program_specification_curriculum_structure", "number_of_courses", "INT DEFAULT 0");
addColumnIfMissing($conn, "program_specification_curriculum_structure", "credit_hours", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "program_specification_curriculum_structure", "percentage", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "program_specification_curriculum_structure", "notes", "TEXT NULL");

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
    "structure_category" => "",
    "requirement_type" => "",
    "number_of_courses" => "0",
    "credit_hours" => "0",
    "percentage" => "0",
    "notes" => ""
];

if ($canEdit && isset($_GET["delete"]) && is_numeric($_GET["delete"])) {
    $delete_id = (int)$_GET["delete"];

    $loadStmt = $conn->prepare("
        SELECT *
        FROM program_specification_curriculum_structure
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
            DELETE FROM program_specification_curriculum_structure
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
            "Program Specification Curriculum Structure",
            $delete_id,
            "Deleted curriculum row: " . ($deleteRow["structure_category"] ?? "")
        );

        header("Location: manage_program_specification_curriculum.php?id=" . $spec_id);
        exit;
    }
}

if (isset($_GET["edit"]) && is_numeric($_GET["edit"])) {
    $edit_id = (int)$_GET["edit"];

    $editStmt = $conn->prepare("
        SELECT *
        FROM program_specification_curriculum_structure
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
            "structure_category" => $editRow["structure_category"] ?? "",
            "requirement_type" => $editRow["requirement_type"] ?? "",
            "number_of_courses" => $editRow["number_of_courses"] ?? "0",
            "credit_hours" => $editRow["credit_hours"] ?? "0",
            "percentage" => $editRow["percentage"] ?? "0",
            "notes" => $editRow["notes"] ?? ""
        ];
    }
}

if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? "");
    }

    if ($form["structure_category"] === "") {
        $error = "Curriculum structure category is required.";
    } else {
        if ($form["row_id"] !== "" && is_numeric($form["row_id"])) {
            $updateStmt = $conn->prepare("
                UPDATE program_specification_curriculum_structure
                SET
                    structure_category = :structure_category,
                    requirement_type = :requirement_type,
                    number_of_courses = :number_of_courses,
                    credit_hours = :credit_hours,
                    percentage = :percentage,
                    notes = :notes
                WHERE id = :id
                  AND program_specification_id = :program_specification_id
            ");

            $updateStmt->execute([
                ":structure_category" => $form["structure_category"],
                ":requirement_type" => nullIfEmpty($form["requirement_type"]),
                ":number_of_courses" => intValue($form["number_of_courses"]),
                ":credit_hours" => numberValue($form["credit_hours"]),
                ":percentage" => numberValue($form["percentage"]),
                ":notes" => nullIfEmpty($form["notes"]),
                ":id" => (int)$form["row_id"],
                ":program_specification_id" => $spec_id
            ]);

            logActivitySafe(
                $conn,
                "Update",
                "Program Specification Curriculum Structure",
                (int)$form["row_id"],
                "Updated curriculum row: " . $form["structure_category"]
            );

            header("Location: manage_program_specification_curriculum.php?id=" . $spec_id);
            exit;
        } else {
            $insertStmt = $conn->prepare("
                INSERT INTO program_specification_curriculum_structure
                (
                    program_specification_id,
                    structure_category,
                    requirement_type,
                    number_of_courses,
                    credit_hours,
                    percentage,
                    notes
                )
                VALUES
                (
                    :program_specification_id,
                    :structure_category,
                    :requirement_type,
                    :number_of_courses,
                    :credit_hours,
                    :percentage,
                    :notes
                )
            ");

            $insertStmt->execute([
                ":program_specification_id" => $spec_id,
                ":structure_category" => $form["structure_category"],
                ":requirement_type" => nullIfEmpty($form["requirement_type"]),
                ":number_of_courses" => intValue($form["number_of_courses"]),
                ":credit_hours" => numberValue($form["credit_hours"]),
                ":percentage" => numberValue($form["percentage"]),
                ":notes" => nullIfEmpty($form["notes"])
            ]);

            $newId = (int)$conn->lastInsertId();

            logActivitySafe(
                $conn,
                "Create",
                "Program Specification Curriculum Structure",
                $newId,
                "Created curriculum row: " . $form["structure_category"]
            );

            header("Location: manage_program_specification_curriculum.php?id=" . $spec_id);
            exit;
        }
    }
}

$rowsStmt = $conn->prepare("
    SELECT *
    FROM program_specification_curriculum_structure
    WHERE program_specification_id = :program_specification_id
    ORDER BY
        CASE
            WHEN structure_category LIKE '%Institution%' THEN 1
            WHEN structure_category LIKE '%University%' THEN 1
            WHEN structure_category LIKE '%College%' THEN 2
            WHEN structure_category LIKE '%Program%' THEN 3
            WHEN structure_category LIKE '%Capstone%' THEN 4
            WHEN structure_category LIKE '%Project%' THEN 4
            WHEN structure_category LIKE '%Internship%' THEN 5
            WHEN structure_category LIKE '%Total%' THEN 9
            ELSE 8
        END,
        id ASC
");
$rowsStmt->execute([":program_specification_id" => $spec_id]);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCourses = 0;
$totalCredits = 0;
$totalPercentage = 0;

foreach ($rows as $row) {
    if (stripos((string)$row["structure_category"], "total") === false) {
        $totalCourses += (int)($row["number_of_courses"] ?? 0);
        $totalCredits += (float)($row["credit_hours"] ?? 0);
        $totalPercentage += (float)($row["percentage"] ?? 0);
    }
}

$declaredCredits = (float)($spec["total_credit_hours"] ?? 0);

$categoryOptions = [
    "Institution / University Requirements",
    "College Requirements",
    "Program Requirements",
    "Capstone / Graduation Project",
    "Field Training / Internship",
    "Total"
];

$requirementOptions = [
    "Required",
    "Elective",
    "Required + Elective",
    "N/A"
];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Curriculum Structure</title>
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

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .total-row {
            background: #f8f9fa;
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
        <h1>Manage Curriculum Structure</h1>
        <p>Add and review the curriculum structure rows used in the NCAAA program specification.</p>
    </div>

    <div class="actions">
        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
            Back to Specification
        </a>

        <a href="program_specification_validation.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Validate Specification
        </a>

        <a href="import_default_hpe_curriculum.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Import Default HPE Curriculum
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
                <th>Declared Credit Hours</th>
                <td><?php echo safeText($spec["total_credit_hours"] ?? ""); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($rows)); ?></div>
            <div class="summary-label">Structure Rows</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalCourses); ?></div>
            <div class="summary-label">Courses Excluding Total Row</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalCredits); ?></div>
            <div class="summary-label">Credits Excluding Total Row</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($declaredCredits); ?></div>
            <div class="summary-label">Declared Program Credits</div>
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <div class="panel">
            <h2><?php echo $form["row_id"] !== "" ? "Edit Curriculum Row" : "Add Curriculum Row"; ?></h2>

            <form method="POST" action="manage_program_specification_curriculum.php?id=<?php echo safeText($spec_id); ?>">

                <input type="hidden" name="row_id" value="<?php echo safeText($form["row_id"]); ?>">

                <div class="form-grid">
                    <div>
                        <label>Structure Category <span style="color:red;">*</span></label>
                        <select name="structure_category" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($categoryOptions as $option): ?>
                                <option value="<?php echo safeText($option); ?>"
                                    <?php if ($form["structure_category"] === $option) echo "selected"; ?>>
                                    <?php echo safeText($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Requirement Type</label>
                        <select name="requirement_type">
                            <option value="">-- Select --</option>
                            <?php foreach ($requirementOptions as $option): ?>
                                <option value="<?php echo safeText($option); ?>"
                                    <?php if ($form["requirement_type"] === $option) echo "selected"; ?>>
                                    <?php echo safeText($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Number of Courses</label>
                        <input type="number" name="number_of_courses" value="<?php echo safeText($form["number_of_courses"]); ?>">
                    </div>

                    <div>
                        <label>Credit Hours</label>
                        <input type="number" step="0.01" name="credit_hours" value="<?php echo safeText($form["credit_hours"]); ?>">
                    </div>

                    <div>
                        <label>Percentage</label>
                        <input type="number" step="0.01" name="percentage" value="<?php echo safeText($form["percentage"]); ?>">
                    </div>
                </div>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($form["notes"]); ?></textarea>

                <br>

                <button type="submit" class="btn">
                    <?php echo $form["row_id"] !== "" ? "Update Row" : "Add Row"; ?>
                </button>

                <?php if ($form["row_id"] !== ""): ?>
                    <a href="manage_program_specification_curriculum.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
                        Cancel Edit
                    </a>
                <?php endif; ?>

            </form>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Curriculum Structure Rows</h2>

        <table>
            <tr>
                <th>Category</th>
                <th>Required / Elective</th>
                <th>No. of Courses</th>
                <th>Credit Hours</th>
                <th>Percentage</th>
                <th>Notes</th>
                <th>Actions</th>
            </tr>

            <?php if (count($rows) > 0): ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $isTotalRow = stripos((string)$row["structure_category"], "total") !== false;
                    ?>
                    <tr class="<?php echo $isTotalRow ? 'total-row' : ''; ?>">
                        <td><?php echo safeText($row["structure_category"] ?? ""); ?></td>
                        <td><?php echo safeText($row["requirement_type"] ?? ""); ?></td>
                        <td><?php echo safeText($row["number_of_courses"] ?? ""); ?></td>
                        <td><?php echo safeText($row["credit_hours"] ?? ""); ?></td>
                        <td><?php echo safeText($row["percentage"] ?? ""); ?>%</td>
                        <td><?php echo nl2br(safeText($row["notes"] ?? "")); ?></td>
                        <td>
                            <?php if ($canEdit): ?>
                                <a href="manage_program_specification_curriculum.php?id=<?php echo safeText($spec_id); ?>&edit=<?php echo safeText($row["id"]); ?>" class="btn btn-edit">
                                    Edit
                                </a>

                                <a href="manage_program_specification_curriculum.php?id=<?php echo safeText($spec_id); ?>&delete=<?php echo safeText($row["id"]); ?>"
                                   class="btn btn-delete"
                                   onclick="return confirm('Delete this curriculum structure row?');">
                                    Delete
                                </a>
                            <?php else: ?>
                                <span class="small-text">View only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <tr class="total-row">
                    <td colspan="2">Calculated Total Excluding Total Row</td>
                    <td><?php echo safeText($totalCourses); ?></td>
                    <td><?php echo safeText($totalCredits); ?></td>
                    <td><?php echo safeText(round($totalPercentage, 2)); ?>%</td>
                    <td colspan="2"></td>
                </tr>

            <?php else: ?>
                <tr>
                    <td colspan="7">No curriculum structure rows entered yet.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>
