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

if (!tableExists($conn, "program_specification_evidence_links")) {
    die("program_specification_evidence_links table does not exist.");
}

if (!tableExists($conn, "evidence_items")) {
    die("evidence_items table does not exist.");
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

$linksStmt = $conn->prepare("
    SELECT 
        l.*,
        e.title AS evidence_title,
        e.evidence_type,
        e.responsible_person,
        e.status,
        e.review_status,
        e.notes AS description
    FROM program_specification_evidence_links l
    LEFT JOIN evidence_items e ON l.evidence_item_id = e.id
    WHERE l.program_specification_id = :program_specification_id
    ORDER BY l.section_name ASC, e.title ASC, l.id ASC
");
$linksStmt->execute([":program_specification_id" => $spec_id]);
$links = $linksStmt->fetchAll(PDO::FETCH_ASSOC);

$linksBySection = [];
$sectionHasEvidence = [];

foreach ($sections as $section) {
    $linksBySection[$section] = [];
    $sectionHasEvidence[$section] = false;
}

foreach ($links as $link) {
    $sectionName = $link["section_name"] ?? "";

    if (!isset($linksBySection[$sectionName])) {
        $linksBySection[$sectionName] = [];
        $sectionHasEvidence[$sectionName] = false;
    }

    $linksBySection[$sectionName][] = $link;
    $sectionHasEvidence[$sectionName] = true;
}

$linkedSectionCount = 0;
$missingSectionCount = 0;

foreach ($sections as $section) {
    if (!empty($linksBySection[$section])) {
        $linkedSectionCount++;
    } else {
        $missingSectionCount++;
    }
}

$totalLinks = count($links);
$totalSections = count($sections);
$coveragePercent = $totalSections > 0 ? round(($linkedSectionCount / $totalSections) * 100, 1) : 0;

$filenameBase = $spec["program_code"] ?? "program_specification_evidence_matrix";
$filenameBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filenameBase);
$filename = "program_specification_evidence_matrix_" . $filenameBase . "_" . date("Y-m-d_H-i-s") . ".doc";

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
    <title>Program Specification Evidence Matrix</title>

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

        .linked {
            background: #d1e7dd;
            color: #0f5132;
            font-weight: bold;
        }

        .missing {
            background: #f8d7da;
            color: #842029;
            font-weight: bold;
        }

        .partial {
            background: #fff3cd;
            color: #664d03;
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
    <h1>Program Specification Evidence Matrix</h1>
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
        <th>Approval Status</th>
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

<h2>2. Evidence Coverage Summary</h2>

<table>
    <tr>
        <th>Total Sections</th>
        <th>Sections With Evidence</th>
        <th>Sections Missing Evidence</th>
        <th>Total Evidence Links</th>
        <th>Coverage %</th>
    </tr>
    <tr>
        <td class="center"><?php echo safeText($totalSections); ?></td>
        <td class="center linked"><?php echo safeText($linkedSectionCount); ?></td>
        <td class="center missing"><?php echo safeText($missingSectionCount); ?></td>
        <td class="center"><?php echo safeText($totalLinks); ?></td>
        <td class="center"><?php echo safeText($coveragePercent); ?>%</td>
    </tr>
</table>

<h2>3. Section Coverage Status</h2>

<table>
    <tr>
        <th>No.</th>
        <th>Program Specification Section</th>
        <th>Evidence Status</th>
        <th>No. of Linked Evidence Items</th>
    </tr>

    <?php $sectionNo = 1; ?>
    <?php foreach ($sections as $section): ?>
        <?php $count = count($linksBySection[$section] ?? []); ?>
        <tr>
            <td class="center"><?php echo safeText($sectionNo); ?></td>
            <td><?php echo safeText($section); ?></td>
            <td class="<?php echo $count > 0 ? 'linked' : 'missing'; ?>">
                <?php echo $count > 0 ? "Linked" : "Missing Evidence"; ?>
            </td>
            <td class="center"><?php echo safeText($count); ?></td>
        </tr>
        <?php $sectionNo++; ?>
    <?php endforeach; ?>
</table>

<h2>4. Detailed Evidence Matrix</h2>

<table>
    <tr>
        <th>No.</th>
        <th>Specification Section</th>
        <th>Evidence Title</th>
        <th>Evidence Type</th>
        <th>Evidence Status</th>
        <th>Review Status</th>
        <th>Responsible Person</th>
        <th>Link Notes</th>
    </tr>

    <?php $rowNo = 1; ?>

    <?php foreach ($sections as $section): ?>
        <?php if (!empty($linksBySection[$section])): ?>
            <?php foreach ($linksBySection[$section] as $link): ?>
                <tr>
                    <td class="center"><?php echo safeText($rowNo); ?></td>
                    <td><?php echo safeText($section); ?></td>
                    <td><?php echo safeText($link["evidence_title"] ?? "Evidence item not found"); ?></td>
                    <td><?php echo safeText($link["evidence_type"] ?? ""); ?></td>
                    <td><?php echo safeText($link["status"] ?? ""); ?></td>
                    <td><?php echo safeText($link["review_status"] ?? ""); ?></td>
                    <td><?php echo safeText($link["responsible_person"] ?? ""); ?></td>
                    <td><?php echo wordText($link["link_notes"] ?? ""); ?></td>
                </tr>
                <?php $rowNo++; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td class="center"><?php echo safeText($rowNo); ?></td>
                <td><?php echo safeText($section); ?></td>
                <td colspan="6" class="missing">
                    No evidence item linked to this section.
                </td>
            </tr>
            <?php $rowNo++; ?>
        <?php endif; ?>
    <?php endforeach; ?>
</table>

<h2>5. Sections Requiring Evidence Follow-Up</h2>

<table>
    <tr>
        <th>No.</th>
        <th>Missing Section</th>
        <th>Recommended Action</th>
    </tr>

    <?php $missingNo = 1; ?>
    <?php foreach ($sections as $section): ?>
        <?php if (empty($linksBySection[$section])): ?>
            <tr>
                <td class="center"><?php echo safeText($missingNo); ?></td>
                <td><?php echo safeText($section); ?></td>
                <td>
                    Link at least one approved evidence item from the evidence repository to this section.
                </td>
            </tr>
            <?php $missingNo++; ?>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($missingNo === 1): ?>
        <tr>
            <td colspan="3" class="linked">
                All program specification sections have at least one linked evidence item.
            </td>
        </tr>
    <?php endif; ?>
</table>

<h2>6. Evidence Matrix Statement</h2>

<table>
    <tr>
        <th>Evidence Readiness</th>
        <td class="<?php echo $missingSectionCount === 0 ? 'linked' : 'partial'; ?>">
            <?php if ($missingSectionCount === 0): ?>
                Complete. All program specification sections have linked evidence.
            <?php else: ?>
                Partial. Some sections still require linked evidence before final accreditation review.
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th>Generated By</th>
        <td>Quality System - Program Specification Module</td>
    </tr>
    <tr>
        <th>Generated Date</th>
        <td><?php echo date("Y-m-d H:i"); ?></td>
    </tr>
</table>

</body>
</html>