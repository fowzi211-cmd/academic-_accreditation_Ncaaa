<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

if (!isset($_GET["standard_id"]) || !is_numeric($_GET["standard_id"])) {
    die("Invalid standard ID.");
}

$standard_id = (int) $_GET["standard_id"];

// Load system settings
$settingsStmt = $conn->query("
    SELECT *
    FROM system_settings
    ORDER BY id ASC
    LIMIT 1
");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

// Load selected standard
$stmt = $conn->prepare("
    SELECT *
    FROM standards
    WHERE id = :id
");
$stmt->execute([":id" => $standard_id]);
$standard = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$standard) {
    die("Standard not found.");
}

// Load substandards
$subStmt = $conn->prepare("
    SELECT *
    FROM substandards
    WHERE standard_id = :standard_id
    ORDER BY substandard_code
");
$subStmt->execute([":standard_id" => $standard_id]);
$substandards = $subStmt->fetchAll(PDO::FETCH_ASSOC);

function getEvidenceForSubstandard($conn, $substandard_id) {
    $stmt = $conn->prepare("
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
            COUNT(ef.id) AS file_count
        FROM evidence_items e
        LEFT JOIN evidence_files ef ON e.id = ef.evidence_id
        WHERE e.substandard_id = :substandard_id
        GROUP BY
            e.id,
            e.title,
            e.evidence_type,
            e.responsible_person,
            e.status,
            e.academic_year,
            e.semester,
            e.review_status,
            e.notes
        ORDER BY e.id DESC
    ");
    $stmt->execute([":substandard_id" => $substandard_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTasksForSubstandard($conn, $substandard_id) {
    $stmt = $conn->prepare("
        SELECT
            id,
            task_title,
            responsible_person,
            due_date,
            priority,
            status,
            notes
        FROM tasks
        WHERE substandard_id = :substandard_id
        ORDER BY
            CASE
                WHEN status = 'Pending' THEN 1
                WHEN status = 'In Progress' THEN 2
                WHEN status = 'Completed' THEN 3
                WHEN status = 'Cancelled' THEN 4
                ELSE 5
            END,
            due_date ASC,
            id DESC
    ");
    $stmt->execute([":substandard_id" => $substandard_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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

function coverageText($evidenceCount) {
    return $evidenceCount > 0 ? "Covered" : "Not Covered";
}

// Prepare summary
$totalSubstandards = count($substandards);
$coveredSubstandards = 0;
$totalEvidenceItems = 0;
$totalTasks = 0;

$substandardData = [];

foreach ($substandards as $sub) {
    $evidenceList = getEvidenceForSubstandard($conn, $sub["id"]);
    $taskList = getTasksForSubstandard($conn, $sub["id"]);

    if (count($evidenceList) > 0) {
        $coveredSubstandards++;
    }

    $totalEvidenceItems += count($evidenceList);
    $totalTasks += count($taskList);

    $substandardData[] = [
        "substandard" => $sub,
        "evidence" => $evidenceList,
        "tasks" => $taskList
    ];
}

$coverageRate = $totalSubstandards > 0
    ? round(($coveredSubstandards / $totalSubstandards) * 100, 1)
    : 0;

$safeTitle = preg_replace(
    "/[^a-zA-Z0-9_\-]/",
    "_",
    $standard["standard_code"] . "_" . $standard["standard_title"]
);

$filename = $safeTitle . "_evidence_report_" . date("Y-m-d_H-i-s") . ".doc";

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
    <title><?php echo cleanText($standard["standard_code"] . " Evidence Report"); ?></title>
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

        .covered {
            color: #008000;
            font-weight: bold;
        }

        .not-covered {
            color: #cc0000;
            font-weight: bold;
        }

        .small {
            font-size: 10pt;
        }
    </style>
</head>

<body>

<h1>Standard Evidence Report</h1>

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

<h2>Selected Standard</h2>

<table class="meta">
    <tr>
        <th>Standard Code</th>
        <td><?php echo cleanText($standard["standard_code"]); ?></td>
    </tr>

    <tr>
        <th>Standard Title</th>
        <td><?php echo cleanText($standard["standard_title"]); ?></td>
    </tr>

    <tr>
        <th>Description</th>
        <td><?php echo cleanParagraph($standard["description"] ?? ""); ?></td>
    </tr>
</table>

<h2>Coverage Summary</h2>

<table>
    <tr>
        <th>Total Substandards</th>
        <th>Covered Substandards</th>
        <th>Coverage Rate</th>
        <th>Total Evidence Items</th>
        <th>Related Tasks</th>
    </tr>

    <tr>
        <td><?php echo cleanText($totalSubstandards); ?></td>
        <td><?php echo cleanText($coveredSubstandards); ?></td>
        <td><?php echo cleanText($coverageRate); ?>%</td>
        <td><?php echo cleanText($totalEvidenceItems); ?></td>
        <td><?php echo cleanText($totalTasks); ?></td>
    </tr>
</table>

<h2>Substandard Coverage Details</h2>

<?php if (count($substandardData) > 0): ?>

    <?php foreach ($substandardData as $data): ?>
        <?php
            $sub = $data["substandard"];
            $evidenceList = $data["evidence"];
            $taskList = $data["tasks"];
            $isCovered = count($evidenceList) > 0;
            $coverageClass = $isCovered ? "covered" : "not-covered";
        ?>

        <h3>
            <?php echo cleanText($sub["substandard_code"]); ?>
            —
            <span class="<?php echo $coverageClass; ?>">
                <?php echo coverageText(count($evidenceList)); ?>
            </span>
        </h3>

        <p>
            <?php echo cleanParagraph($sub["substandard_text"]); ?>
        </p>

        <h4>Evidence Items</h4>

        <?php if (count($evidenceList) > 0): ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Evidence Title</th>
                    <th>Type</th>
                    <th>Responsible</th>
                    <th>Status</th>
                    <th>Academic Year</th>
                    <th>Semester</th>
                    <th>Review</th>
                    <th>Files</th>
                    <th>Notes</th>
                </tr>

                <?php foreach ($evidenceList as $evidence): ?>
                    <tr>
                        <td><?php echo cleanText($evidence["id"]); ?></td>
                        <td><?php echo cleanText($evidence["title"]); ?></td>
                        <td><?php echo cleanText($evidence["evidence_type"]); ?></td>
                        <td><?php echo cleanText($evidence["responsible_person"]); ?></td>
                        <td><?php echo cleanText($evidence["status"]); ?></td>
                        <td><?php echo cleanText($evidence["academic_year"]); ?></td>
                        <td><?php echo cleanText($evidence["semester"]); ?></td>
                        <td><?php echo cleanText($evidence["review_status"]); ?></td>
                        <td><?php echo cleanText($evidence["file_count"]); ?> file(s)</td>
                        <td><?php echo cleanParagraph($evidence["notes"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p><em>No evidence linked to this substandard.</em></p>
        <?php endif; ?>

        <h4>Related Tasks</h4>

        <?php if (count($taskList) > 0): ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Task</th>
                    <th>Responsible</th>
                    <th>Due Date</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Notes</th>
                </tr>

                <?php foreach ($taskList as $task): ?>
                    <tr>
                        <td><?php echo cleanText($task["id"]); ?></td>
                        <td><?php echo cleanText($task["task_title"]); ?></td>
                        <td><?php echo cleanText($task["responsible_person"]); ?></td>
                        <td><?php echo cleanText($task["due_date"]); ?></td>
                        <td><?php echo cleanText($task["priority"]); ?></td>
                        <td><?php echo cleanText($task["status"]); ?></td>
                        <td><?php echo cleanParagraph($task["notes"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p><em>No tasks linked to this substandard.</em></p>
        <?php endif; ?>

    <?php endforeach; ?>

<?php else: ?>

    <p><em>No substandards found under this standard.</em></p>

<?php endif; ?>

</body>
</html>