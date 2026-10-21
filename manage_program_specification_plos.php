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
$message = "";

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

function nullIfEmpty($value) {
    $value = trim((string)$value);
    return $value === "" ? null : $value;
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

function domainSortValue($domain) {
    if ($domain === "Knowledge and Understanding") {
        return 1;
    }

    if ($domain === "Skills") {
        return 2;
    }

    if ($domain === "Values, Autonomy, and Responsibility") {
        return 3;
    }

    return 4;
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "program_specification_plos")) {
    die("program_specification_plos table does not exist.");
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
    "plo_id" => "",
    "plo_code" => "",
    "plo_domain" => "",
    "plo_text" => "",
    "nqf_level" => "",
    "display_order" => "",
    "notes" => ""
];

if ($canEdit && isset($_GET["delete"]) && is_numeric($_GET["delete"])) {
    $delete_id = (int)$_GET["delete"];

    $loadStmt = $conn->prepare("
        SELECT *
        FROM program_specification_plos
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
            DELETE FROM program_specification_plos
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
            "Program Specification PLOs",
            $delete_id,
            "Deleted PLO: " . ($deleteRow["plo_code"] ?? "")
        );

        header("Location: manage_program_specification_plos.php?id=" . $spec_id);
        exit;
    }
}

if (isset($_GET["edit"]) && is_numeric($_GET["edit"])) {
    $edit_id = (int)$_GET["edit"];

    $editStmt = $conn->prepare("
        SELECT *
        FROM program_specification_plos
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
            "plo_id" => $editRow["id"] ?? "",
            "plo_code" => $editRow["plo_code"] ?? "",
            "plo_domain" => $editRow["plo_domain"] ?? "",
            "plo_text" => $editRow["plo_text"] ?? "",
            "nqf_level" => $editRow["nqf_level"] ?? "",
            "display_order" => $editRow["display_order"] ?? "",
            "notes" => $editRow["notes"] ?? ""
        ];
    }
}

if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? "");
    }

    if ($form["plo_code"] === "") {
        $error = "PLO code is required.";
    } elseif ($form["plo_domain"] === "") {
        $error = "PLO domain is required.";
    } elseif ($form["plo_text"] === "") {
        $error = "PLO text is required.";
    } else {
        if ($form["plo_id"] !== "" && is_numeric($form["plo_id"])) {
            $updateStmt = $conn->prepare("
                UPDATE program_specification_plos
                SET
                    plo_code = :plo_code,
                    plo_domain = :plo_domain,
                    plo_text = :plo_text,
                    nqf_level = :nqf_level,
                    display_order = :display_order,
                    notes = :notes
                WHERE id = :id
                  AND program_specification_id = :program_specification_id
            ");

            $updateStmt->execute([
                ":plo_code" => $form["plo_code"],
                ":plo_domain" => $form["plo_domain"],
                ":plo_text" => $form["plo_text"],
                ":nqf_level" => nullIfEmpty($form["nqf_level"]),
                ":display_order" => $form["display_order"] !== "" ? (int)$form["display_order"] : null,
                ":notes" => nullIfEmpty($form["notes"]),
                ":id" => (int)$form["plo_id"],
                ":program_specification_id" => $spec_id
            ]);

            logActivitySafe(
                $conn,
                "Update",
                "Program Specification PLOs",
                (int)$form["plo_id"],
                "Updated PLO: " . $form["plo_code"]
            );

            header("Location: manage_program_specification_plos.php?id=" . $spec_id);
            exit;
        } else {
            $duplicateStmt = $conn->prepare("
                SELECT COUNT(*)
                FROM program_specification_plos
                WHERE program_specification_id = :program_specification_id
                  AND plo_code = :plo_code
            ");
            $duplicateStmt->execute([
                ":program_specification_id" => $spec_id,
                ":plo_code" => $form["plo_code"]
            ]);

            if ((int)$duplicateStmt->fetchColumn() > 0) {
                $error = "This PLO code already exists for this program specification.";
            } else {
                $insertStmt = $conn->prepare("
                    INSERT INTO program_specification_plos
                    (
                        program_specification_id,
                        plo_code,
                        plo_domain,
                        plo_text,
                        nqf_level,
                        display_order,
                        notes
                    )
                    VALUES
                    (
                        :program_specification_id,
                        :plo_code,
                        :plo_domain,
                        :plo_text,
                        :nqf_level,
                        :display_order,
                        :notes
                    )
                ");

                $insertStmt->execute([
                    ":program_specification_id" => $spec_id,
                    ":plo_code" => $form["plo_code"],
                    ":plo_domain" => $form["plo_domain"],
                    ":plo_text" => $form["plo_text"],
                    ":nqf_level" => nullIfEmpty($form["nqf_level"]),
                    ":display_order" => $form["display_order"] !== "" ? (int)$form["display_order"] : null,
                    ":notes" => nullIfEmpty($form["notes"])
                ]);

                $newId = (int)$conn->lastInsertId();

                logActivitySafe(
                    $conn,
                    "Create",
                    "Program Specification PLOs",
                    $newId,
                    "Created PLO: " . $form["plo_code"]
                );

                header("Location: manage_program_specification_plos.php?id=" . $spec_id);
                exit;
            }
        }
    }
}

$plosStmt = $conn->prepare("
    SELECT *
    FROM program_specification_plos
    WHERE program_specification_id = :program_specification_id
    ORDER BY
        CASE
            WHEN plo_domain = 'Knowledge and Understanding' THEN 1
            WHEN plo_domain = 'Skills' THEN 2
            WHEN plo_domain = 'Values, Autonomy, and Responsibility' THEN 3
            ELSE 4
        END,
        display_order ASC,
        plo_code ASC,
        id ASC
");
$plosStmt->execute([":program_specification_id" => $spec_id]);
$plos = $plosStmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    "total" => count($plos),
    "knowledge" => 0,
    "skills" => 0,
    "values" => 0
];

foreach ($plos as $plo) {
    if ($plo["plo_domain"] === "Knowledge and Understanding") {
        $summary["knowledge"]++;
    } elseif ($plo["plo_domain"] === "Skills") {
        $summary["skills"]++;
    } elseif ($plo["plo_domain"] === "Values, Autonomy, and Responsibility") {
        $summary["values"]++;
    }
}

$domainOptions = [
    "Knowledge and Understanding",
    "Skills",
    "Values, Autonomy, and Responsibility"
];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Program Specification PLOs</title>
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
            grid-template-columns: repeat(2, minmax(220px, 1fr));
            gap: 14px;
        }

        textarea {
            min-height: 130px;
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

        .domain-row {
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
        <h1>Manage Program Learning Outcomes</h1>
        <p>Add, edit, and review PLOs for this program specification.</p>
    </div>

    <div class="actions">
        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
            Back to Specification
        </a>

        <a href="program_specifications.php" class="btn">
            Program Specifications
        </a>

        <?php if ($canEdit): ?>
            <a href="import_default_hpe_plos.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
                Import Default HPE PLOs
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
            <div class="summary-label">Total PLOs</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($summary["knowledge"]); ?></div>
            <div class="summary-label">Knowledge</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($summary["skills"]); ?></div>
            <div class="summary-label">Skills</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($summary["values"]); ?></div>
            <div class="summary-label">Values</div>
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <div class="panel">
            <h2><?php echo $form["plo_id"] !== "" ? "Edit PLO" : "Add New PLO"; ?></h2>

            <form method="POST" action="manage_program_specification_plos.php?id=<?php echo safeText($spec_id); ?>">

                <input type="hidden" name="plo_id" value="<?php echo safeText($form["plo_id"]); ?>">

                <div class="form-grid">
                    <div>
                        <label>PLO Code <span style="color:red;">*</span></label>
                        <input type="text"
                               name="plo_code"
                               required
                               value="<?php echo safeText($form["plo_code"]); ?>"
                               placeholder="Example: K1">
                    </div>

                    <div>
                        <label>PLO Domain <span style="color:red;">*</span></label>
                        <select name="plo_domain" required>
                            <option value="">-- Select Domain --</option>
                            <?php foreach ($domainOptions as $domain): ?>
                                <option value="<?php echo safeText($domain); ?>"
                                    <?php if ($form["plo_domain"] === $domain) echo "selected"; ?>>
                                    <?php echo safeText($domain); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>NQF Level</label>
                        <input type="text"
                               name="nqf_level"
                               value="<?php echo safeText($form["nqf_level"]); ?>"
                               placeholder="Optional">
                    </div>

                    <div>
                        <label>Display Order</label>
                        <input type="number"
                               name="display_order"
                               value="<?php echo safeText($form["display_order"]); ?>"
                               placeholder="Example: 1">
                    </div>
                </div>

                <label>PLO Text <span style="color:red;">*</span></label>
                <textarea name="plo_text" required><?php echo safeText($form["plo_text"]); ?></textarea>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($form["notes"]); ?></textarea>

                <br>

                <button type="submit" class="btn">
                    <?php echo $form["plo_id"] !== "" ? "Update PLO" : "Add PLO"; ?>
                </button>

                <?php if ($form["plo_id"] !== ""): ?>
                    <a href="manage_program_specification_plos.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
                        Cancel Edit
                    </a>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Program Learning Outcomes List</h2>

        <table>
            <tr>
                <th>Code</th>
                <th>Domain</th>
                <th>PLO Text</th>
                <th>NQF Level</th>
                <th>Order</th>
                <th>Actions</th>
            </tr>

            <?php if (count($plos) > 0): ?>
                <?php
                $currentDomain = "";
                ?>

                <?php foreach ($plos as $plo): ?>
                    <?php if ($currentDomain !== $plo["plo_domain"]): ?>
                        <?php $currentDomain = $plo["plo_domain"]; ?>
                        <tr class="domain-row">
                            <td colspan="6"><?php echo safeText($currentDomain); ?></td>
                        </tr>
                    <?php endif; ?>

                    <tr>
                        <td><strong><?php echo safeText($plo["plo_code"]); ?></strong></td>
                        <td><?php echo safeText($plo["plo_domain"]); ?></td>
                        <td><?php echo nl2br(safeText($plo["plo_text"])); ?></td>
                        <td><?php echo safeText($plo["nqf_level"]); ?></td>
                        <td><?php echo safeText($plo["display_order"]); ?></td>
                        <td class="actions">
                            <?php if ($canEdit): ?>
                                <a href="manage_program_specification_plos.php?id=<?php echo safeText($spec_id); ?>&edit=<?php echo safeText($plo["id"]); ?>" class="btn btn-edit">
                                    Edit
                                </a>

                                <a href="manage_program_specification_plos.php?id=<?php echo safeText($spec_id); ?>&delete=<?php echo safeText($plo["id"]); ?>"
                                   class="btn btn-delete"
                                   onclick="return confirm('Delete this PLO? This may affect PLO-course mapping later.');">
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
                    <td colspan="6">No PLOs entered yet.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>