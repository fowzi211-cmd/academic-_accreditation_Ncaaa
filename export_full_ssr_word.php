<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// Load system settings
$settingsStmt = $conn->query("
    SELECT *
    FROM system_settings
    ORDER BY id ASC
    LIMIT 1
");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

// Load all standards with SSR sections
$sectionsStmt = $conn->query("
    SELECT 
        s.id AS standard_id,
        s.standard_code,
        s.standard_title,
        s.description AS standard_description,

        ssr.id AS ssr_id,
        ssr.academic_year,
        ssr.report_cycle,
        ssr.section_title,
        ssr.evidence_based_explanation,
        ssr.strengths,
        ssr.areas_for_improvement,
        ssr.action_plan,
        ssr.conclusion,
        ssr.status,
        ssr.prepared_by,
        ssr.reviewed_by,
        ssr.created_at,
        ssr.updated_at

    FROM standards s
    LEFT JOIN ssr_sections ssr ON ssr.standard_id = s.id
    ORDER BY s.id
");
$sections = $sectionsStmt->fetchAll(PDO::FETCH_ASSOC);

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

function getSubstandards($conn, $standard_id) {
    $stmt = $conn->prepare("
        SELECT *
        FROM substandards
        WHERE standard_id = :standard_id
        ORDER BY substandard_code
    ");
    $stmt->execute([":standard_id" => $standard_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getEvidenceItems($conn, $standard_id) {
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
    $stmt->execute([":standard_id" => $standard_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTasks($conn, $standard_id) {
    $stmt = $conn->prepare("
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
    $stmt->execute([":standard_id" => $standard_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCoverageSummary($conn, $standard_id) {
    $subStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM substandards
        WHERE standard_id = :standard_id
    ");
    $subStmt->execute([":standard_id" => $standard_id]);
    $totalSubstandards = (int)$subStmt->fetchColumn();

    $coveredStmt = $conn->prepare("
        SELECT COUNT(DISTINCT ss.id)
        FROM substandards ss
        INNER JOIN evidence_items e ON e.substandard_id = ss.id
        WHERE ss.standard_id = :standard_id
    ");
    $coveredStmt->execute([":standard_id" => $standard_id]);
    $coveredSubstandards = (int)$coveredStmt->fetchColumn();

    $evidenceStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM evidence_items e
        INNER JOIN substandards ss ON e.substandard_id = ss.id
        WHERE ss.standard_id = :standard_id
    ");
    $evidenceStmt->execute([":standard_id" => $standard_id]);
    $evidenceCount = (int)$evidenceStmt->fetchColumn();

    $coverageRate = $totalSubstandards > 0
        ? round(($coveredSubstandards / $totalSubstandards) * 100, 1)
        : 0;

    return [
        "totalSubstandards" => $totalSubstandards,
        "coveredSubstandards" => $coveredSubstandards,
        "coverageRate" => $coverageRate,
        "evidenceCount" => $evidenceCount
    ];
}

$academicYear = $settings["default_academic_year"] ?? date("Y");
$programName = $settings["program_name"] ?? "Program";

$safeTitle = preg_replace("/[^a-zA-Z0-9_\-]/", "_", $programName . "_Full_SSR_" . $academicYear);
$filename = $safeTitle . "_" . date("Y-m-d_H-i-s") . ".doc";

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
    <title>Full Self-Study Report</title>
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
            font-size: 22pt;
            margin-bottom: 20px;
        }

        h2 {
            font-size: 17pt;
            margin-top: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 5px;
        }

        h3 {
            font-size: 14pt;
            margin-top: 22px;
            border-bottom: 1px solid #777;
            padding-bottom: 4px;
        }

        h4 {
            font-size: 12.5pt;
            margin-top: 16px;
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

        .page-break {
            page-break-before: always;
        }

        .small {
            font-size: 10pt;
        }

        .section-block {
            margin-bottom: 18px;
        }

        .not-started {
            color: #cc0000;
            font-weight: bold;
        }

        .approved {
            color: #008000;
            font-weight: bold;
        }
    </style>
</head>

<body>

<h1>Full Program Self-Study Report</h1>

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

    <tr>
        <th>Default Academic Year</th>
        <td><?php echo cleanText($settings["default_academic_year"] ?? ""); ?></td>
    </tr>

    <tr>
        <th>Default Semester</th>
        <td><?php echo cleanText($settings["default_semester"] ?? ""); ?></td>
    </tr>

    <tr>
        <th>Generated At</th>
        <td><?php echo date("Y-m-d H:i:s"); ?></td>
    </tr>
</table>

<h2>SSR Sections Overview</h2>

<table>
    <tr>
        <th>Standard</th>
        <th>SSR Section</th>
        <th>Status</th>
        <th>Academic Year</th>
        <th>Report Cycle</th>
        <th>Prepared By</th>
        <th>Reviewed By</th>
    </tr>

    <?php foreach ($sections as $section): ?>
        <tr>
            <td>
                <strong><?php echo cleanText($section["standard_code"]); ?></strong><br>
                <?php echo cleanText($section["standard_title"]); ?>
            </td>

            <td>
                <?php if (!empty($section["ssr_id"])): ?>
                    <?php echo cleanText($section["section_title"]); ?>
                <?php else: ?>
                    <span class="not-started">No SSR section created</span>
                <?php endif; ?>
            </td>

            <td>
                <?php if (!empty($section["status"])): ?>
                    <?php echo cleanText($section["status"]); ?>
                <?php else: ?>
                    <span class="not-started">Not Started</span>
                <?php endif; ?>
            </td>

            <td><?php echo cleanText($section["academic_year"]); ?></td>
            <td><?php echo cleanText($section["report_cycle"]); ?></td>
            <td><?php echo cleanText($section["prepared_by"]); ?></td>
            <td><?php echo cleanText($section["reviewed_by"]); ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<?php foreach ($sections as $index => $section): ?>
    <?php
        $standardId = (int)$section["standard_id"];
        $summary = getCoverageSummary($conn, $standardId);
        $substandards = getSubstandards($conn, $standardId);
        $evidenceItems = getEvidenceItems($conn, $standardId);
        $tasks = getTasks($conn, $standardId);
    ?>

    <div class="page-break"></div>

    <h2>
        <?php echo cleanText($section["standard_code"]); ?>
        -
        <?php echo cleanText($section["standard_title"]); ?>
    </h2>

    <h3>Standard Description</h3>
    <p><?php echo cleanParagraph($section["standard_description"]); ?></p>

    <h3>SSR Section Information</h3>

    <table class="meta">
        <tr>
            <th>Section Title</th>
            <td>
                <?php if (!empty($section["ssr_id"])): ?>
                    <?php echo cleanText($section["section_title"]); ?>
                <?php else: ?>
                    <span class="not-started">No SSR section created for this standard.</span>
                <?php endif; ?>
            </td>
        </tr>

        <tr>
            <th>Status</th>
            <td><?php echo cleanText($section["status"]); ?></td>
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
            <th>Prepared By</th>
            <td><?php echo cleanText($section["prepared_by"]); ?></td>
        </tr>

        <tr>
            <th>Reviewed By</th>
            <td><?php echo cleanText($section["reviewed_by"]); ?></td>
        </tr>
    </table>

    <h3>Evidence Coverage Summary</h3>

    <table>
        <tr>
            <th>Total Substandards</th>
            <th>Covered Substandards</th>
            <th>Coverage Rate</th>
            <th>Evidence Items</th>
            <th>Related Tasks</th>
        </tr>

        <tr>
            <td><?php echo cleanText($summary["totalSubstandards"]); ?></td>
            <td><?php echo cleanText($summary["coveredSubstandards"]); ?></td>
            <td><?php echo cleanText($summary["coverageRate"]); ?>%</td>
            <td><?php echo cleanText($summary["evidenceCount"]); ?></td>
            <td><?php echo cleanText(count($tasks)); ?></td>
        </tr>
    </table>

    <h3>SSR Narrative</h3>

    <?php if (!empty($section["ssr_id"])): ?>

        <h4>Evidence-Based Explanation</h4>
        <p><?php echo cleanParagraph($section["evidence_based_explanation"]); ?></p>

        <h4>Strengths</h4>
        <p><?php echo cleanParagraph($section["strengths"]); ?></p>

        <h4>Areas for Improvement</h4>
        <p><?php echo cleanParagraph($section["areas_for_improvement"]); ?></p>

        <h4>Action Plan</h4>
        <p><?php echo cleanParagraph($section["action_plan"]); ?></p>

        <h4>Conclusion</h4>
        <p><?php echo cleanParagraph($section["conclusion"]); ?></p>

    <?php else: ?>

        <p><em>No SSR narrative has been written for this standard.</em></p>

    <?php endif; ?>

    <h3>Substandards</h3>

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

    <h3>Evidence Items Supporting This Standard</h3>

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

    <h3>Improvement Tasks Related to This Standard</h3>

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
                    <td><?php echo cleanText($task["task_title"]); ?></td>
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

<?php endforeach; ?>

</body>
</html>