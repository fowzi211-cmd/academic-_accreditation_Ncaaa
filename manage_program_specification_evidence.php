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

if (!tableExists($conn, "evidence_items")) {
    die("evidence_items table does not exist.");
}

if (!tableExists($conn, "program_specification_evidence_links")) {
    requireSchemaTable($conn, "program_specification_evidence_links");
}

addColumnIfMissing($conn, "program_specification_evidence_links", "program_specification_id", "INT NOT NULL");
addColumnIfMissing($conn, "program_specification_evidence_links", "section_name", "VARCHAR(255) NOT NULL");
addColumnIfMissing($conn, "program_specification_evidence_links", "evidence_item_id", "INT NOT NULL");
addColumnIfMissing($conn, "program_specification_evidence_links", "link_notes", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_evidence_links", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

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

$sections = [
    "Program Identity and General Information",
    "Program Mission and Goals",
    "Program Learning Outcomes",
    "Curriculum Structure",
    "Program Courses / Study Plan",
    "PLO-Course Mapping Matrix",
    "Teaching and Learning Strategies",
    "Assessment Methods and Mark Distribution",
    "Student Admission and Support",
    "Staffing Needs",
    "Learning Resources",
    "Facilities and Equipment",
    "Quality Assurance System",
    "Program Evaluation Matrix",
    "Program KPIs",
    "Tracks and Exit Points",
    "Approval and Governance Evidence"
];

$form = [
    "link_id" => "",
    "section_name" => "",
    "evidence_item_id" => "",
    "link_notes" => ""
];

/* Delete */
if ($canEdit && isset($_GET["delete"]) && is_numeric($_GET["delete"])) {
    $delete_id = (int)$_GET["delete"];

    $loadStmt = $conn->prepare("
        SELECT *
        FROM program_specification_evidence_links
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
            DELETE FROM program_specification_evidence_links
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
            "Program Specification Evidence Links",
            $delete_id,
            "Deleted evidence link for section: " . ($row["section_name"] ?? "")
        );

        header("Location: manage_program_specification_evidence.php?id=" . $spec_id);
        exit;
    }
}

/* Edit loader */
if (isset($_GET["edit"]) && is_numeric($_GET["edit"])) {
    $edit_id = (int)$_GET["edit"];

    $editStmt = $conn->prepare("
        SELECT *
        FROM program_specification_evidence_links
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
            "link_id" => $row["id"] ?? "",
            "section_name" => $row["section_name"] ?? "",
            "evidence_item_id" => $row["evidence_item_id"] ?? "",
            "link_notes" => $row["link_notes"] ?? ""
        ];
    }
}

/* Save */
if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? "");
    }

    if ($form["section_name"] === "") {
        $error = "Section name is required.";
    } elseif ($form["evidence_item_id"] === "" || !is_numeric($form["evidence_item_id"])) {
        $error = "Evidence item is required.";
    } else {
        $evidenceCheck = $conn->prepare("
            SELECT COUNT(*)
            FROM evidence_items
            WHERE id = :id
        ");
        $evidenceCheck->execute([":id" => (int)$form["evidence_item_id"]]);

        if ((int)$evidenceCheck->fetchColumn() === 0) {
            $error = "Selected evidence item does not exist.";
        } else {
            if ($form["link_id"] !== "" && is_numeric($form["link_id"])) {
                $stmt = $conn->prepare("
                    UPDATE program_specification_evidence_links
                    SET
                        section_name = :section_name,
                        evidence_item_id = :evidence_item_id,
                        link_notes = :link_notes
                    WHERE id = :id
                      AND program_specification_id = :program_specification_id
                ");

                $stmt->execute([
                    ":section_name" => $form["section_name"],
                    ":evidence_item_id" => (int)$form["evidence_item_id"],
                    ":link_notes" => nullIfEmpty($form["link_notes"]),
                    ":id" => (int)$form["link_id"],
                    ":program_specification_id" => $spec_id
                ]);

                logActivitySafe(
                    $conn,
                    "Update",
                    "Program Specification Evidence Links",
                    (int)$form["link_id"],
                    "Updated evidence link for section: " . $form["section_name"]
                );

                header("Location: manage_program_specification_evidence.php?id=" . $spec_id);
                exit;
            } else {
                $duplicateStmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM program_specification_evidence_links
                    WHERE program_specification_id = :program_specification_id
                      AND section_name = :section_name
                      AND evidence_item_id = :evidence_item_id
                ");
                $duplicateStmt->execute([
                    ":program_specification_id" => $spec_id,
                    ":section_name" => $form["section_name"],
                    ":evidence_item_id" => (int)$form["evidence_item_id"]
                ]);

                if ((int)$duplicateStmt->fetchColumn() > 0) {
                    $error = "This evidence item is already linked to this section.";
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO program_specification_evidence_links
                        (
                            program_specification_id,
                            section_name,
                            evidence_item_id,
                            link_notes
                        )
                        VALUES
                        (
                            :program_specification_id,
                            :section_name,
                            :evidence_item_id,
                            :link_notes
                        )
                    ");

                    $stmt->execute([
                        ":program_specification_id" => $spec_id,
                        ":section_name" => $form["section_name"],
                        ":evidence_item_id" => (int)$form["evidence_item_id"],
                        ":link_notes" => nullIfEmpty($form["link_notes"])
                    ]);

                    $newId = (int)$conn->lastInsertId();

                    logActivitySafe(
                        $conn,
                        "Create",
                        "Program Specification Evidence Links",
                        $newId,
                        "Created evidence link for section: " . $form["section_name"]
                    );

                    header("Location: manage_program_specification_evidence.php?id=" . $spec_id);
                    exit;
                }
            }
        }
    }
}

/* Evidence items */
$evidenceStmt = $conn->query("
    SELECT 
        id,
        title AS evidence_title,
        evidence_type,
        responsible_person,
        status
    FROM evidence_items
    ORDER BY title ASC, id ASC
");
$evidenceItems = $evidenceStmt->fetchAll(PDO::FETCH_ASSOC);

/* Links */
$linksStmt = $conn->prepare("
    SELECT 
        l.*,
        e.title AS evidence_title,
        e.evidence_type,
        e.responsible_person,
        e.status
    FROM program_specification_evidence_links l
    LEFT JOIN evidence_items e ON l.evidence_item_id = e.id
    WHERE l.program_specification_id = :program_specification_id
    ORDER BY l.section_name ASC, e.title ASC, l.id ASC
");$linksStmt->execute([":program_specification_id" => $spec_id]);
$links = $linksStmt->fetchAll(PDO::FETCH_ASSOC);

$linkedSectionCount = 0;
$sectionHasEvidence = [];

foreach ($sections as $section) {
    $sectionHasEvidence[$section] = false;
}

foreach ($links as $link) {
    if (isset($sectionHasEvidence[$link["section_name"]])) {
        $sectionHasEvidence[$link["section_name"]] = true;
    }
}

foreach ($sectionHasEvidence as $hasEvidence) {
    if ($hasEvidence) {
        $linkedSectionCount++;
    }
}

$unlinkedSectionCount = count($sections) - $linkedSectionCount;
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Program Specification Evidence Linkage</title>
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

        .section-row {
            background: #f8f9fa;
            font-weight: bold;
        }

        .badge-linked {
            background: #d1e7dd;
            color: #0f5132;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-missing {
            background: #f8d7da;
            color: #842029;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
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
        <h1>Program Specification Evidence Linkage</h1>
        <p>Link program specification sections to evidence repository items.</p>
    </div>

    <div class="actions">
        <a href="program_specification_dashboard.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Dashboard
        </a>

        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
            Back to Specification
        </a>

        <a href="program_specification_validation.php?id=<?php echo safeText($spec_id); ?>" class="btn">
            Validate
        </a>

        <a href="export_program_specification_evidence_matrix_word.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Export Evidence Matrix
        </a>
    </div>

    <div class="panel">
        <h2>Program Specification</h2>

        <table>
            <tr>
                <th>Specification ID</th>
                <td><?php echo safeText($spec_id); ?></td>
                <th>Program</th>
                <td><?php echo safeText($spec["program_name"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Specification</th>
                <td><?php echo safeText($spec["specification_title"] ?? ""); ?></td>
                <th>Program Code</th>
                <td><?php echo safeText($spec["program_code"] ?? ""); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($sections)); ?></div>
            <div class="summary-label">Specification Sections</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($links)); ?></div>
            <div class="summary-label">Evidence Links</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($linkedSectionCount); ?></div>
            <div class="summary-label">Sections With Evidence</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($unlinkedSectionCount); ?></div>
            <div class="summary-label">Sections Without Evidence</div>
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <div class="panel">
            <h2><?php echo $form["link_id"] !== "" ? "Edit Evidence Link" : "Add Evidence Link"; ?></h2>

            <form method="POST" action="manage_program_specification_evidence.php?id=<?php echo safeText($spec_id); ?>">

                <input type="hidden" name="link_id" value="<?php echo safeText($form["link_id"]); ?>">

                <div class="form-grid">
                    <div>
                        <label>Specification Section <span style="color:red;">*</span></label>
                        <select name="section_name" required>
                            <option value="">-- Select Section --</option>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?php echo safeText($section); ?>"
                                    <?php if ($form["section_name"] === $section) echo "selected"; ?>>
                                    <?php echo safeText($section); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Evidence Item <span style="color:red;">*</span></label>
                        <select name="evidence_item_id" required>
                            <option value="">-- Select Evidence --</option>
                            <?php foreach ($evidenceItems as $evidence): ?>
                                <option value="<?php echo safeText($evidence["id"]); ?>"
                                    <?php if ((string)$form["evidence_item_id"] === (string)$evidence["id"]) echo "selected"; ?>>
                                    <?php echo safeText($evidence["evidence_title"]); ?>
                                    <?php if (!empty($evidence["evidence_type"])): ?>
                                        | <?php echo safeText($evidence["evidence_type"]); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <label>Link Notes</label>
                <textarea name="link_notes" placeholder="Explain how this evidence supports the selected section."><?php echo safeText($form["link_notes"]); ?></textarea>

                <br>

                <button type="submit" class="btn">
                    <?php echo $form["link_id"] !== "" ? "Update Link" : "Add Link"; ?>
                </button>

                <?php if ($form["link_id"] !== ""): ?>
                    <a href="manage_program_specification_evidence.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
                        Cancel Edit
                    </a>
                <?php endif; ?>

            </form>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Section Coverage</h2>

        <table>
            <tr>
                <th>Section</th>
                <th>Status</th>
            </tr>

            <?php foreach ($sections as $section): ?>
                <tr>
                    <td><?php echo safeText($section); ?></td>
                    <td>
                        <?php if ($sectionHasEvidence[$section]): ?>
                            <span class="badge-linked">Linked</span>
                        <?php else: ?>
                            <span class="badge-missing">Missing Evidence</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Evidence Links</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Section</th>
                    <th>Evidence Title</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Responsible</th>
                    <th>Link Notes</th>
                    <th>Actions</th>
                </tr>

                <?php if (count($links) > 0): ?>
                    <?php
                    $currentSection = "";
                    ?>

                    <?php foreach ($links as $link): ?>
                        <?php if ($currentSection !== $link["section_name"]): ?>
                            <?php $currentSection = $link["section_name"]; ?>
                            <tr class="section-row">
                                <td colspan="7"><?php echo safeText($currentSection); ?></td>
                            </tr>
                        <?php endif; ?>

                        <tr>
                            <td><?php echo safeText($link["section_name"] ?? ""); ?></td>
                            <td><?php echo safeText($link["evidence_title"] ?? "Evidence item not found"); ?></td>
                            <td><?php echo safeText($link["evidence_type"] ?? ""); ?></td>
                            <td><?php echo safeText($link["status"] ?? ""); ?></td>
                            <td><?php echo safeText($link["responsible_person"] ?? ""); ?></td>
                            <td><?php echo nl2br(safeText($link["link_notes"] ?? "")); ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <a href="manage_program_specification_evidence.php?id=<?php echo safeText($spec_id); ?>&edit=<?php echo safeText($link["id"]); ?>" class="btn btn-edit">
                                        Edit
                                    </a>

                                    <a href="manage_program_specification_evidence.php?id=<?php echo safeText($spec_id); ?>&delete=<?php echo safeText($link["id"]); ?>"
                                       class="btn btn-delete"
                                       onclick="return confirm('Delete this evidence link?');">
                                        Delete
                                    </a>
                                <?php else: ?>
                                    View only
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="7">No evidence links entered yet.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
