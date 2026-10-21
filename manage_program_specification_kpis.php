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

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "program_specification_kpis")) {
    die("program_specification_kpis table does not exist.");
}

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
    "kpi_id" => "",
    "kpi_no" => "",
    "kpi_code" => "",
    "kpi_name" => "",
    "target_value" => "",
    "measurement_methods" => "",
    "measurement_time" => "",
    "kpi_type" => "Main KPI",
    "notes" => ""
];

if ($canEdit && isset($_GET["delete"]) && is_numeric($_GET["delete"])) {
    $delete_id = (int)$_GET["delete"];

    $loadStmt = $conn->prepare("
        SELECT *
        FROM program_specification_kpis
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
            DELETE FROM program_specification_kpis
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
            "Program Specification KPIs",
            $delete_id,
            "Deleted KPI: " . ($deleteRow["kpi_code"] ?? "") . " - " . ($deleteRow["kpi_name"] ?? "")
        );

        header("Location: manage_program_specification_kpis.php?id=" . $spec_id);
        exit;
    }
}

if (isset($_GET["edit"]) && is_numeric($_GET["edit"])) {
    $edit_id = (int)$_GET["edit"];

    $editStmt = $conn->prepare("
        SELECT *
        FROM program_specification_kpis
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
            "kpi_id" => $editRow["id"] ?? "",
            "kpi_no" => $editRow["kpi_no"] ?? "",
            "kpi_code" => $editRow["kpi_code"] ?? "",
            "kpi_name" => $editRow["kpi_name"] ?? "",
            "target_value" => $editRow["target_value"] ?? "",
            "measurement_methods" => $editRow["measurement_methods"] ?? "",
            "measurement_time" => $editRow["measurement_time"] ?? "",
            "kpi_type" => $editRow["kpi_type"] ?? "Main KPI",
            "notes" => $editRow["notes"] ?? ""
        ];
    }
}

if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? "");
    }

    if ($form["kpi_name"] === "") {
        $error = "KPI name is required.";
    } else {
        if ($form["kpi_id"] !== "" && is_numeric($form["kpi_id"])) {
            $updateStmt = $conn->prepare("
                UPDATE program_specification_kpis
                SET
                    kpi_no = :kpi_no,
                    kpi_code = :kpi_code,
                    kpi_name = :kpi_name,
                    target_value = :target_value,
                    measurement_methods = :measurement_methods,
                    measurement_time = :measurement_time,
                    kpi_type = :kpi_type,
                    notes = :notes
                WHERE id = :id
                  AND program_specification_id = :program_specification_id
            ");

            $updateStmt->execute([
                ":kpi_no" => $form["kpi_no"] !== "" ? (int)$form["kpi_no"] : null,
                ":kpi_code" => nullIfEmpty($form["kpi_code"]),
                ":kpi_name" => $form["kpi_name"],
                ":target_value" => nullIfEmpty($form["target_value"]),
                ":measurement_methods" => nullIfEmpty($form["measurement_methods"]),
                ":measurement_time" => nullIfEmpty($form["measurement_time"]),
                ":kpi_type" => nullIfEmpty($form["kpi_type"]) ?: "Main KPI",
                ":notes" => nullIfEmpty($form["notes"]),
                ":id" => (int)$form["kpi_id"],
                ":program_specification_id" => $spec_id
            ]);

            logActivitySafe(
                $conn,
                "Update",
                "Program Specification KPIs",
                (int)$form["kpi_id"],
                "Updated KPI: " . $form["kpi_code"] . " - " . $form["kpi_name"]
            );

            header("Location: manage_program_specification_kpis.php?id=" . $spec_id);
            exit;
        } else {
            $duplicateCount = 0;

            if ($form["kpi_code"] !== "") {
                $duplicateStmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM program_specification_kpis
                    WHERE program_specification_id = :program_specification_id
                      AND kpi_code = :kpi_code
                ");
                $duplicateStmt->execute([
                    ":program_specification_id" => $spec_id,
                    ":kpi_code" => $form["kpi_code"]
                ]);

                $duplicateCount = (int)$duplicateStmt->fetchColumn();
            }

            if ($duplicateCount > 0) {
                $error = "This KPI code already exists for this program specification.";
            } else {
                $insertStmt = $conn->prepare("
                    INSERT INTO program_specification_kpis
                    (
                        program_specification_id,
                        kpi_no,
                        kpi_code,
                        kpi_name,
                        target_value,
                        measurement_methods,
                        measurement_time,
                        kpi_type,
                        notes
                    )
                    VALUES
                    (
                        :program_specification_id,
                        :kpi_no,
                        :kpi_code,
                        :kpi_name,
                        :target_value,
                        :measurement_methods,
                        :measurement_time,
                        :kpi_type,
                        :notes
                    )
                ");

                $insertStmt->execute([
                    ":program_specification_id" => $spec_id,
                    ":kpi_no" => $form["kpi_no"] !== "" ? (int)$form["kpi_no"] : null,
                    ":kpi_code" => nullIfEmpty($form["kpi_code"]),
                    ":kpi_name" => $form["kpi_name"],
                    ":target_value" => nullIfEmpty($form["target_value"]),
                    ":measurement_methods" => nullIfEmpty($form["measurement_methods"]),
                    ":measurement_time" => nullIfEmpty($form["measurement_time"]),
                    ":kpi_type" => nullIfEmpty($form["kpi_type"]) ?: "Main KPI",
                    ":notes" => nullIfEmpty($form["notes"])
                ]);

                $newId = (int)$conn->lastInsertId();

                logActivitySafe(
                    $conn,
                    "Create",
                    "Program Specification KPIs",
                    $newId,
                    "Created KPI: " . $form["kpi_code"] . " - " . $form["kpi_name"]
                );

                header("Location: manage_program_specification_kpis.php?id=" . $spec_id);
                exit;
            }
        }
    }
}

$kpisStmt = $conn->prepare("
    SELECT *
    FROM program_specification_kpis
    WHERE program_specification_id = :program_specification_id
    ORDER BY
        CASE
            WHEN kpi_type = 'Main KPI' THEN 1
            WHEN kpi_type = 'Sub-KPI' THEN 2
            ELSE 3
        END,
        kpi_no ASC,
        kpi_code ASC,
        id ASC
");
$kpisStmt->execute([":program_specification_id" => $spec_id]);
$kpis = $kpisStmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    "total" => count($kpis),
    "main" => 0,
    "sub" => 0
];

foreach ($kpis as $kpi) {
    if ($kpi["kpi_type"] === "Main KPI") {
        $summary["main"]++;
    } elseif ($kpi["kpi_type"] === "Sub-KPI") {
        $summary["sub"]++;
    }
}

$kpiTypeOptions = ["Main KPI", "Sub-KPI", "Other"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Program Specification KPIs</title>
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
            grid-template-columns: repeat(3, minmax(180px, 1fr));
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

        .type-row {
            background: #f8f9fa;
            font-weight: bold;
        }

        .actions a {
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
        <h1>Manage Program KPIs</h1>
        <p>Add, edit, and review main KPIs and sub-KPIs for this program specification.</p>
    </div>

    <div class="actions">
        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
            Back to Specification
        </a>

        <a href="program_specifications.php" class="btn">
            Program Specifications
        </a>

        <?php if ($canEdit): ?>
            <a href="import_default_hpe_kpis.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
                Import Default HPE KPIs
            </a>
        <?php endif; ?>
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
            <div class="summary-number"><?php echo safeText($summary["total"]); ?></div>
            <div class="summary-label">Total KPIs</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($summary["main"]); ?></div>
            <div class="summary-label">Main KPIs</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($summary["sub"]); ?></div>
            <div class="summary-label">Sub-KPIs</div>
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <div class="panel">
            <h2><?php echo $form["kpi_id"] !== "" ? "Edit KPI" : "Add New KPI"; ?></h2>

            <form method="POST" action="manage_program_specification_kpis.php?id=<?php echo safeText($spec_id); ?>">

                <input type="hidden" name="kpi_id" value="<?php echo safeText($form["kpi_id"]); ?>">

                <div class="form-grid">
                    <div>
                        <label>KPI No.</label>
                        <input type="number"
                               name="kpi_no"
                               value="<?php echo safeText($form["kpi_no"]); ?>"
                               placeholder="Example: 1">
                    </div>

                    <div>
                        <label>KPI Code</label>
                        <input type="text"
                               name="kpi_code"
                               value="<?php echo safeText($form["kpi_code"]); ?>"
                               placeholder="Example: KPI-P-01">
                    </div>

                    <div>
                        <label>KPI Type</label>
                        <select name="kpi_type">
                            <?php foreach ($kpiTypeOptions as $type): ?>
                                <option value="<?php echo safeText($type); ?>"
                                    <?php if ($form["kpi_type"] === $type) echo "selected"; ?>>
                                    <?php echo safeText($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <label>KPI Name <span style="color:red;">*</span></label>
                <input type="text"
                       name="kpi_name"
                       required
                       value="<?php echo safeText($form["kpi_name"]); ?>"
                       placeholder="Example: Students' Evaluation of Quality of Learning Experience">

                <div class="form-grid">
                    <div>
                        <label>Target Value</label>
                        <input type="text"
                               name="target_value"
                               value="<?php echo safeText($form["target_value"]); ?>"
                               placeholder="Example: ≥ 4.5 / 5">
                    </div>

                    <div>
                        <label>Measurement Time</label>
                        <input type="text"
                               name="measurement_time"
                               value="<?php echo safeText($form["measurement_time"]); ?>"
                               placeholder="Example: End of each semester">
                    </div>
                </div>

                <label>Measurement Methods</label>
                <textarea name="measurement_methods"><?php echo safeText($form["measurement_methods"]); ?></textarea>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($form["notes"]); ?></textarea>

                <br>

                <button type="submit" class="btn">
                    <?php echo $form["kpi_id"] !== "" ? "Update KPI" : "Add KPI"; ?>
                </button>

                <?php if ($form["kpi_id"] !== ""): ?>
                    <a href="manage_program_specification_kpis.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
                        Cancel Edit
                    </a>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Program KPIs List</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>No.</th>
                    <th>Code</th>
                    <th>KPI</th>
                    <th>Target</th>
                    <th>Measurement Methods</th>
                    <th>Measurement Time</th>
                    <th>Type</th>
                    <th>Actions</th>
                </tr>

                <?php if (count($kpis) > 0): ?>
                    <?php
                    $currentType = "";
                    ?>

                    <?php foreach ($kpis as $kpi): ?>
                        <?php if ($currentType !== $kpi["kpi_type"]): ?>
                            <?php $currentType = $kpi["kpi_type"]; ?>
                            <tr class="type-row">
                                <td colspan="8"><?php echo safeText($currentType); ?></td>
                            </tr>
                        <?php endif; ?>

                        <tr>
                            <td><?php echo safeText($kpi["kpi_no"]); ?></td>
                            <td><strong><?php echo safeText($kpi["kpi_code"]); ?></strong></td>
                            <td><?php echo safeText($kpi["kpi_name"]); ?></td>
                            <td><?php echo safeText($kpi["target_value"]); ?></td>
                            <td><?php echo nl2br(safeText($kpi["measurement_methods"])); ?></td>
                            <td><?php echo safeText($kpi["measurement_time"]); ?></td>
                            <td><?php echo safeText($kpi["kpi_type"]); ?></td>
                            <td class="actions">
                                <?php if ($canEdit): ?>
                                    <a href="manage_program_specification_kpis.php?id=<?php echo safeText($spec_id); ?>&edit=<?php echo safeText($kpi["id"]); ?>" class="btn btn-edit">
                                        Edit
                                    </a>

                                    <a href="manage_program_specification_kpis.php?id=<?php echo safeText($spec_id); ?>&delete=<?php echo safeText($kpi["id"]); ?>"
                                       class="btn btn-delete"
                                       onclick="return confirm('Delete this KPI?');">
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
                        <td colspan="8">No KPIs entered yet.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>