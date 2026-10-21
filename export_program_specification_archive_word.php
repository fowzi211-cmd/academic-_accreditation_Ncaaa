<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

$spec_id = isset($_GET["id"]) && is_numeric($_GET["id"]) ? (int)$_GET["id"] : 3;

function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function wordText($text) {
    return nl2br(htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8"));
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

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "program_specification_archive")) {
    die("program_specification_archive table does not exist.");
}

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
$partialArchives = 0;

foreach ($archiveRows as $row) {
    $isComplete = (
        (int)($row["specification_exported"] ?? 0) === 1 &&
        (int)($row["validation_exported"] ?? 0) === 1 &&
        (int)($row["evidence_matrix_exported"] ?? 0) === 1 &&
        (int)($row["readiness_exported"] ?? 0) === 1
    );

    if ($isComplete) {
        $completeArchives++;
    } else {
        $partialArchives++;
    }
}

$filenameBase = $spec["program_code"] ?? "program_specification_archive";
$filenameBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filenameBase);
$filename = "program_specification_archive_" . $filenameBase . "_" . date("Y-m-d_H-i-s") . ".doc";

header("Content-Type: application/msword; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Program Specification Archive Report</title>

    <style>
        body {
            font-family: Arial, Tahoma, sans-serif;
            font-size: 11pt;
            line-height: 1.45;
            color: #111;
        }

        h1 {
            text-align: center;
            font-size: 20pt;
            margin-bottom: 5px;
        }

        h2 {
            background: #d9eaf7;
            border: 1px solid #9dbbd4;
            padding: 8px;
            font-size: 15pt;
            margin-top: 25px;
        }

        h3 {
            font-size: 13pt;
            color: #1f4e79;
            margin-top: 18px;
            margin-bottom: 6px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 14px;
        }

        th {
            background: #f2f2f2;
            font-weight: bold;
        }

        th, td {
            border: 1px solid #555;
            padding: 6px;
            vertical-align: top;
        }

        .center {
            text-align: center;
        }

        .complete {
            background: #d1e7dd;
            color: #0f5132;
            font-weight: bold;
        }

        .partial {
            background: #fff3cd;
            color: #664d03;
            font-weight: bold;
        }

        .missing {
            background: #f8d7da;
            color: #842029;
            font-weight: bold;
        }

        .cover {
            text-align: center;
            margin-top: 70px;
            margin-bottom: 80px;
        }

        .cover h1 {
            font-size: 24pt;
        }

        .cover p {
            font-size: 14pt;
        }

        .small {
            font-size: 9pt;
        }
    </style>
</head>

<body>

<div class="cover">
    <h1>Program Specification Archive Report</h1>
    <p><strong><?php echo safeText($spec["program_name"] ?? ""); ?></strong></p>
    <p><?php echo safeText($spec["college_name"] ?? ""); ?></p>
    <p><?php echo safeText($spec["institution_name"] ?? ""); ?></p>
    <p>Generated on: <?php echo date("Y-m-d H:i"); ?></p>
</div>

<h2>1. Program Specification Identity</h2>

<table>
    <tr>
        <th>Program Specification ID</th>
        <td><?php echo safeText($spec_id); ?></td>
        <th>Current Approval Status</th>
        <td><?php echo safeText($spec["approval_status_value"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Specification Title</th>
        <td colspan="3"><?php echo safeText($spec["specification_title"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Program Name</th>
        <td><?php echo safeText($spec["program_name"] ?? ""); ?></td>
        <th>Program Code</th>
        <td><?php echo safeText($spec["program_code"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Qualification Level</th>
        <td><?php echo safeText($spec["qualification_level"] ?? ""); ?></td>
        <th>Total Credit Hours</th>
        <td><?php echo safeText($spec["total_credit_hours"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Department</th>
        <td><?php echo safeText($spec["department_name"] ?? ""); ?></td>
        <th>College</th>
        <td><?php echo safeText($spec["college_name"] ?? ""); ?></td>
    </tr>
</table>

<h2>2. Archive Summary</h2>

<table>
    <tr>
        <th>Total Archive Records</th>
        <th>Complete Export Sets</th>
        <th>Partial Export Sets</th>
        <th>Report Generated Date</th>
    </tr>
    <tr>
        <td class="center"><?php echo safeText($totalArchives); ?></td>
        <td class="center complete"><?php echo safeText($completeArchives); ?></td>
        <td class="center partial"><?php echo safeText($partialArchives); ?></td>
        <td class="center"><?php echo date("Y-m-d H:i"); ?></td>
    </tr>
</table>

<h2>3. Archive Records</h2>

<table>
    <tr>
        <th>No.</th>
        <th>Archive Title</th>
        <th>Version</th>
        <th>Archive Date</th>
        <th>Readiness Decision</th>
        <th>Approval Status</th>
        <th>Export Set Status</th>
        <th>Exported Documents</th>
        <th>Export Dates</th>
        <th>Archive Notes</th>
    </tr>

    <?php if (count($archiveRows) > 0): ?>
        <?php $i = 1; ?>
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
                <td class="center"><?php echo safeText($i); ?></td>
                <td><?php echo safeText($row["archive_title"] ?? ""); ?></td>
                <td><?php echo safeText($row["archive_version"] ?? ""); ?></td>
                <td><?php echo safeText($row["archive_date"] ?? ""); ?></td>
                <td><?php echo safeText($row["readiness_decision"] ?? ""); ?></td>
                <td><?php echo safeText($row["approval_status"] ?? ""); ?></td>
                <td class="<?php echo $isComplete ? 'complete' : 'partial'; ?>">
                    <?php echo $isComplete ? "Complete" : "Partial"; ?>
                </td>
                <td>
                    Full Specification: <?php echo (int)($row["specification_exported"] ?? 0) === 1 ? "Yes" : "No"; ?><br>
                    Validation Report: <?php echo (int)($row["validation_exported"] ?? 0) === 1 ? "Yes" : "No"; ?><br>
                    Evidence Matrix: <?php echo (int)($row["evidence_matrix_exported"] ?? 0) === 1 ? "Yes" : "No"; ?><br>
                    Readiness Report: <?php echo (int)($row["readiness_exported"] ?? 0) === 1 ? "Yes" : "No"; ?>
                </td>
                <td>
                    Full Specification: <?php echo safeText($row["specification_export_date"] ?? ""); ?><br>
                    Validation Report: <?php echo safeText($row["validation_export_date"] ?? ""); ?><br>
                    Evidence Matrix: <?php echo safeText($row["evidence_matrix_export_date"] ?? ""); ?><br>
                    Readiness Report: <?php echo safeText($row["readiness_export_date"] ?? ""); ?>
                </td>
                <td><?php echo wordText($row["archive_notes"] ?? ""); ?></td>
            </tr>
            <?php $i++; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="10" class="missing">
                No archive records have been created yet.
            </td>
        </tr>
    <?php endif; ?>
</table>

<h2>4. Archive Interpretation</h2>

<table>
    <tr>
        <th>Archive Status</th>
        <td class="<?php echo $completeArchives > 0 ? 'complete' : 'partial'; ?>">
            <?php if ($completeArchives > 0): ?>
                At least one complete export set has been archived for this program specification.
            <?php else: ?>
                No complete export set is currently archived.
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th>Recommended Action</th>
        <td>
            <?php if ($completeArchives > 0): ?>
                Use the latest complete archive record as the formal reference for accreditation review and future version comparison.
            <?php else: ?>
                Export the full specification, validation report, evidence matrix, and readiness report, then create a complete archive record.
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th>Generated By</th>
        <td>Quality System - Program Specification Module</td>
    </tr>
</table>

</body>
</html>