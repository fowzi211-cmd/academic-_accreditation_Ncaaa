<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid SSR section ID.");
}

$id = (int) $_GET["id"];

// Load system settings
$settingsStmt = $conn->query("
    SELECT *
    FROM system_settings
    ORDER BY id ASC
    LIMIT 1
");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

// Load SSR section with standard
$stmt = $conn->prepare("
    SELECT 
        ssr.*,
        s.standard_code,
        s.standard_title,
        s.description AS standard_description
    FROM ssr_sections ssr
    INNER JOIN standards s ON ssr.standard_id = s.id
    WHERE ssr.id = :id
");
$stmt->execute([":id" => $id]);
$section = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$section) {
    die("SSR section not found.");
}

$standard_id = (int)$section["standard_id"];

// Load substandards
$subStmt = $conn->prepare("
    SELECT *
    FROM substandards
    WHERE standard_id = :standard_id
    ORDER BY substandard_code
");
$subStmt->execute([":standard_id" => $standard_id]);
$substandards = $subStmt->fetchAll(PDO::FETCH_ASSOC);

// Evidence count
$evidenceCountStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM evidence_items e
    INNER JOIN substandards ss ON e.substandard_id = ss.id
    WHERE ss.standard_id = :standard_id
");
$evidenceCountStmt->execute([":standard_id" => $standard_id]);
$totalEvidence = $evidenceCountStmt->fetchColumn();

// Covered substandards
$coveredStmt = $conn->prepare("
    SELECT COUNT(DISTINCT ss.id)
    FROM substandards ss
    INNER JOIN evidence_items e ON e.substandard_id = ss.id
    WHERE ss.standard_id = :standard_id
");
$coveredStmt->execute([":standard_id" => $standard_id]);
$coveredSubstandards = $coveredStmt->fetchColumn();

$totalSubstandards = count($substandards);
$coverageRate = $totalSubstandards > 0 ? round(($coveredSubstandards / $totalSubstandards) * 100, 1) : 0;

// Load evidence items for this standard
$evidenceStmt = $conn->prepare("
    SELECT
        e.id,
        e.title,
        e.evidence_type,
        e.responsible_person,
        e.status,
        e.academic_year,
        e.semester,
        e.review_status,
        e.notes,
        ss.substandard_code,
        ss.substandard_text,
        COUNT(ef.id) AS file_count
    FROM evidence_items e
    INNER JOIN substandards ss ON e.substandard_id = ss.id
    LEFT JOIN evidence_files ef ON e.id = ef.evidence_id
    WHERE ss.standard_id = :standard_id
    GROUP BY
        e.id,
        e.title,
        e.evidence_type,
        e.responsible_person,
        e.status,
        e.academic_year,
        e.semester,
        e.review_status,
        e.notes,
        ss.substandard_code,
        ss.substandard_text
    ORDER BY ss.substandard_code, e.id DESC
");
$evidenceStmt->execute([":standard_id" => $standard_id]);
$evidenceItems = $evidenceStmt->fetchAll(PDO::FETCH_ASSOC);

// Load related tasks
$taskStmt = $conn->prepare("
    SELECT
        t.id,
        t.task_title,
        t.responsible_person,
        t.due_date,
        t.priority,
        t.status,
        t.notes,
        ss.substandard_code
    FROM tasks t
    INNER JOIN substandards ss ON t.substandard_id = ss.id
    WHERE ss.standard_id = :standard_id
    ORDER BY
        CASE
            WHEN t.status = 'Pending' THEN 1
            WHEN t.status = 'In Progress' THEN 2
            WHEN t.status = 'Completed' THEN 3
            WHEN t.status = 'Cancelled' THEN 4
            ELSE 5
        END,
        t.due_date ASC,
        t.id DESC
");
$taskStmt->execute([":standard_id" => $standard_id]);
$tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);

function cleanText($text) {
    if ($text === null || trim((string)$text) === "") {
        return "Not provided";
    }

    return htmlspecialchars((string)$text);
}

function cleanParagraph($text) {
    if ($text === null || trim((string)$text) === "") {
        return "<em>Not provided</em>";
    }

    return nl2br(htmlspecialchars((string)$text));
}

$safeTitle = preg_replace(
    "/[^a-zA-Z0-9_\-]/",
    "_",
    $section["standard_code"] . "_" . $section["section_title"]
);

$filename = $safeTitle . "_SSR_section_" . date("Y-m-d_H-i-s") . ".doc";

header("Content-Type: application/msword; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// UTF-8 BOM for Word compatibility
echo "\xEF\xBB\xBF";
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo cleanText($section["section_title"]); ?></title>
 <link rel="stylesheet" href="assets/style.css">

    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12pt;
            line-height: 1.5;
            color: #000;
        }

        h1 {
            text-align: center;
            font-size: 20pt;
            margin-bottom: 20px;
        }

        h2 {
            font-size: 16pt;
            margin-top: 25px;
            border-bottom: 1px solid #333;
            padding-bottom: 5px;
        }

        h3 {
            font-size: 13pt;
            margin-top: 18px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }

        th, td {
            border: 1px solid #000;
            padding: 6px;
            vertical-align: top;
        }

        th {
            background-color: #e6e6e6;
            font-weight: bold;
        }

        .meta th {
            width: 220px;
        }

        .small {
            font-size: 10pt;
        }
    </style>
</head>

<body>

<h1>Self-Study Report Section</h1>

<h2>Institution and Program Information</h2>

<table class="meta">
    <tr>
        <th>Institution</th>
        <td><?php echo cleanText($settings["institution_name"] ?? ""); ?></td>
    </tr>

    <tr>
        <th>College</th>
        <td><?php echo cleanText($settings["college_name"] ?? ""); ?></td>
    </tr>

    <tr>
        <th>Department</th>
        <td><?php echo cleanText($settings["department_name"] ?? ""); ?></td>
    </tr>

    <tr>
        <th>Program</th>
        <td><?php echo cleanText($settings["program_name"] ?? ""); ?></td>
    </tr>
</table>

<h2>Section Information</h2>

<table class="meta">
    <tr>
        <th>Section Title</th>
        <td><?php echo cleanText($section["section_title"]); ?></td>
    </tr>

    <tr>
        <th>Standard</th>
        <td>
            <?php echo cleanText($section["standard_code"]); ?>
            -
            <?php echo cleanText($section["standard_title"]); ?>
        </td>
    </tr>

    <tr>
        <th>Standard Description</th>
        <td><?php echo cleanParagraph($section["standard_description"]); ?></td>
    </tr>

    <tr>
        <th>Academic Year</th>
        <td><?php echo cleanText($section["academic_year"]); ?></td>
    </tr>

    <tr>
        <th>Report Cycle</th>
        <td><?php echo cleanText($section["report_cycle"]); ?></td>
    </tr>

    <tr>
        <th>Status</th>
        <td><?php echo cleanText($section["status"]); ?></td>
    </tr>

    <tr>
        <th>Prepared By</th>
        <td><?php echo cleanText($section["prepared_by"]); ?></td>
    </tr>

    <tr>
        <th>Reviewed By</th>
        <td><?php echo cleanText($section["reviewed_by"]); ?></td>
    </tr>

    <tr>
        <th>Created At</th>
        <td><?php echo cleanText($section["created_at"]); ?></td>
    </tr>

    <tr>
        <th>Updated At</th>
        <td><?php echo cleanText($section["updated_at"]); ?></td>
    </tr>
</table>

<h2>Evidence Coverage Summary</h2>

<table>
    <tr>
        <th>Total Substandards</th>
        <th>Covered Substandards</th>
        <th>Coverage Rate</th>
        <th>Evidence Items</th>
    </tr>

    <tr>
        <td><?php echo cleanText($totalSubstandards); ?></td>
        <td><?php echo cleanText($coveredSubstandards); ?></td>
        <td><?php echo cleanText($coverageRate); ?>%</td>
        <td><?php echo cleanText($totalEvidence); ?></td>
    </tr>
</table>

<h2>SSR Narrative</h2>

<h3>Evidence-Based Explanation</h3>
<p><?php echo cleanParagraph($section["evidence_based_explanation"]); ?></p>

<h3>Strengths</h3>
<p><?php echo cleanParagraph($section["strengths"]); ?></p>

<h3>Areas for Improvement</h3>
<p><?php echo cleanParagraph($section["areas_for_improvement"]); ?></p>

<h3>Action Plan</h3>
<p><?php echo cleanParagraph($section["action_plan"]); ?></p>

<h3>Conclusion</h3>
<p><?php echo cleanParagraph($section["conclusion"]); ?></p>

<h2>Substandards Under This Standard</h2>

<?php if (count($substandards) > 0): ?>
    <table>
        <tr>
            <th>Substandard</th>
            <th>Requirement</th>
        </tr>

        <?php foreach ($substandards as $sub): ?>
            <tr>
                <td><?php echo cleanText($sub["substandard_code"]); ?></td>
                <td><?php echo cleanParagraph($sub["substandard_text"]); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php else: ?>
    <p><em>No substandards found for this standard.</em></p>
<?php endif; ?>

<h2>Evidence Items Supporting This Standard</h2>

<?php if (count($evidenceItems) > 0): ?>
    <table>
        <tr>
            <th>ID</th>
            <th>Evidence</th>
            <th>Substandard</th>
            <th>Type</th>
            <th>Responsible</th>
            <th>Status</th>
            <th>Review</th>
            <th>Files</th>
            <th>Notes</th>
        </tr>

        <?php foreach ($evidenceItems as $evidence): ?>
            <tr>
                <td><?php echo cleanText($evidence["id"]); ?></td>

                <td>
                    <strong><?php echo cleanText($evidence["title"]); ?></strong>
                </td>

                <td>
                    <?php echo cleanText($evidence["substandard_code"]); ?><br>
                    <span class="small">
                        <?php echo cleanText($evidence["substandard_text"]); ?>
                    </span>
                </td>

                <td><?php echo cleanText($evidence["evidence_type"]); ?></td>

                <td><?php echo cleanText($evidence["responsible_person"]); ?></td>

                <td><?php echo cleanText($evidence["status"]); ?></td>

                <td><?php echo cleanText($evidence["review_status"]); ?></td>

                <td><?php echo cleanText($evidence["file_count"]); ?> file(s)</td>

                <td><?php echo cleanParagraph($evidence["notes"]); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php else: ?>
    <p><em>No evidence items found for this standard.</em></p>
<?php endif; ?>

<h2>Improvement Tasks Related to This Standard</h2>

<?php if (count($tasks) > 0): ?>
    <table>
        <tr>
            <th>ID</th>
            <th>Task</th>
            <th>Substandard</th>
            <th>Responsible</th>
            <th>Due Date</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Notes</th>
        </tr>

        <?php foreach ($tasks as $task): ?>
            <tr>
                <td><?php echo cleanText($task["id"]); ?></td>

                <td>
                    <strong><?php echo cleanText($task["task_title"]); ?></strong>
                </td>

                <td><?php echo cleanText($task["substandard_code"]); ?></td>

                <td><?php echo cleanText($task["responsible_person"]); ?></td>

                <td><?php echo cleanText($task["due_date"]); ?></td>

                <td><?php echo cleanText($task["priority"]); ?></td>

                <td><?php echo cleanText($task["status"]); ?></td>

                <td><?php echo cleanParagraph($task["notes"]); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php else: ?>
    <p><em>No improvement tasks found for this standard.</em></p>
<?php endif; ?>

</body>
</html>