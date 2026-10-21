<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

$program_specification_id = isset($_GET["program_specification_id"]) && is_numeric($_GET["program_specification_id"])
    ? (int)$_GET["program_specification_id"]
    : 3;

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

function normalizeCode($code) {
    $code = strtoupper(trim((string)$code));
    $code = preg_replace('/\s+/', '', $code);
    return $code;
}

function getValue($row, $fields) {
    foreach ($fields as $field) {
        if (isset($row[$field]) && trim((string)$row[$field]) !== "") {
            return $row[$field];
        }
    }
    return "";
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "course_reports")) {
    die("course_reports table does not exist.");
}

if (!tableExists($conn, "course_specifications")) {
    die("course_specifications table does not exist.");
}

if (!columnExists($conn, "course_reports", "course_specification_id")) {
    die("course_reports.course_specification_id column does not exist. Run the linking page first.");
}

/*
    Load program specification.
*/
$programStmt = $conn->prepare("
    SELECT *
    FROM program_specifications
    WHERE id = :id
    LIMIT 1
");
$programStmt->execute([":id" => $program_specification_id]);
$programSpec = $programStmt->fetch(PDO::FETCH_ASSOC);

if (!$programSpec) {
    die("Program specification not found.");
}

/*
    Load course specifications for this program.
*/
$specStmt = $conn->prepare("
    SELECT *
    FROM course_specifications
    WHERE program_specification_id = :program_specification_id
    ORDER BY course_code ASC, id ASC
");
$specStmt->execute([":program_specification_id" => $program_specification_id]);
$courseSpecs = $specStmt->fetchAll(PDO::FETCH_ASSOC);

$courseSpecsById = [];
$courseSpecsByCode = [];

foreach ($courseSpecs as $spec) {
    $id = (int)($spec["id"] ?? 0);
    $code = normalizeCode($spec["course_code"] ?? "");

    if ($id > 0) {
        $courseSpecsById[$id] = $spec;
    }

    if ($code !== "") {
        if (!isset($courseSpecsByCode[$code])) {
            $courseSpecsByCode[$code] = [];
        }

        $courseSpecsByCode[$code][] = $spec;
    }
}

/*
    Load all course reports.
*/
$reportStmt = $conn->query("
    SELECT *
    FROM course_reports
    ORDER BY id DESC
");
$courseReports = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

/*
    Build report rows.
*/
$rows = [];

foreach ($courseReports as $report) {
    $reportId = (int)($report["id"] ?? 0);

    $reportCourseCode = "";

    if (isset($report["course_code"])) {
        $reportCourseCode = normalizeCode($report["course_code"]);
    } elseif (isset($report["code"])) {
        $reportCourseCode = normalizeCode($report["code"]);
    }

    $reportTitle = getValue($report, ["course_title", "title", "report_title", "course_name", "name"]);
    $academicYear = getValue($report, ["academic_year", "year", "report_year"]);
    $semester = getValue($report, ["semester", "term", "report_semester"]);

    $linkedSpecId = isset($report["course_specification_id"]) && is_numeric($report["course_specification_id"])
        ? (int)$report["course_specification_id"]
        : 0;

    $linkedSpec = null;

    if ($linkedSpecId > 0 && isset($courseSpecsById[$linkedSpecId])) {
        $linkedSpec = $courseSpecsById[$linkedSpecId];
    }

    $matchedSpecs = [];

    if ($reportCourseCode !== "" && isset($courseSpecsByCode[$reportCourseCode])) {
        $matchedSpecs = $courseSpecsByCode[$reportCourseCode];
    }

    $matchedSpec = count($matchedSpecs) > 0 ? $matchedSpecs[0] : null;

    $linkStatus = "Unlinked";
    $issue = "";

    if ($reportCourseCode === "") {
        $linkStatus = "Missing Course Code";
        $issue = "The report has no course code, so automatic matching is not possible.";
    } elseif ($linkedSpec && $matchedSpec && (int)$linkedSpec["id"] === (int)$matchedSpec["id"]) {
        $linkStatus = "Linked Correctly";
    } elseif ($linkedSpec && !$matchedSpec) {
        $linkStatus = "Linked but No Code Match";
        $issue = "The report is linked to a course specification, but no specification was found with the same course code.";
    } elseif ($linkedSpec && $matchedSpec && (int)$linkedSpec["id"] !== (int)$matchedSpec["id"]) {
        $linkStatus = "Mismatched Link";
        $issue = "The report is linked to a different course specification than the one matching its course code.";
    } elseif (!$linkedSpec && $matchedSpec) {
        $linkStatus = "Ready to Link";
        $issue = "A matching course specification exists, but the report is not linked yet.";
    } else {
        $linkStatus = "No Matching Specification";
        $issue = "No course specification was found with the same course code.";
    }

    if (count($matchedSpecs) > 1) {
        $linkStatus = "Multiple Matching Specifications";
        $issue = "More than one course specification has this course code. Manual review is required.";
    }

    $rows[] = [
        "report_id" => $reportId,
        "report_course_code" => $reportCourseCode,
        "report_title" => $reportTitle,
        "academic_year" => $academicYear,
        "semester" => $semester,
        "linked_spec_id" => $linkedSpecId,
        "linked_spec_code" => $linkedSpec["course_code"] ?? "",
        "linked_spec_title" => $linkedSpec["course_title"] ?? "",
        "matched_spec_id" => $matchedSpec["id"] ?? "",
        "matched_spec_code" => $matchedSpec["course_code"] ?? "",
        "matched_spec_title" => $matchedSpec["course_title"] ?? "",
        "matched_spec_status" => $matchedSpec["status"] ?? "",
        "match_count" => count($matchedSpecs),
        "link_status" => $linkStatus,
        "issue" => $issue
    ];
}

/*
    Summary counts.
*/
$totalReports = count($rows);
$linkedCorrectlyCount = 0;
$readyToLinkCount = 0;
$unlinkedCount = 0;
$mismatchedCount = 0;
$multipleMatchCount = 0;
$noMatchCount = 0;
$missingCodeCount = 0;

foreach ($rows as $row) {
    if ($row["link_status"] === "Linked Correctly") {
        $linkedCorrectlyCount++;
    } elseif ($row["link_status"] === "Ready to Link") {
        $readyToLinkCount++;
    } elseif ($row["link_status"] === "Unlinked") {
        $unlinkedCount++;
    } elseif ($row["link_status"] === "Mismatched Link" || $row["link_status"] === "Linked but No Code Match") {
        $mismatchedCount++;
    } elseif ($row["link_status"] === "Multiple Matching Specifications") {
        $multipleMatchCount++;
    } elseif ($row["link_status"] === "Missing Course Code") {
        $missingCodeCount++;
    } else {
        $noMatchCount++;
    }
}

$linkCoveragePercent = $totalReports > 0 ? round(($linkedCorrectlyCount / $totalReports) * 100, 1) : 0;

$overallDecision = "Not Ready";

if ($totalReports > 0 && $linkedCorrectlyCount === $totalReports) {
    $overallDecision = "Complete";
} elseif ($linkedCorrectlyCount > 0) {
    $overallDecision = "Partially Linked";
}

$programCode = $programSpec["program_code"] ?? "program";
$programCode = preg_replace('/[^A-Za-z0-9_\-]/', '_', $programCode);

$filename = "course_report_specification_linkage_" . $programCode . "_" . date("Y-m-d_H-i-s") . ".doc";

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
    <title>Course Report to Course Specification Linkage Report</title>

    <style>
        body {
            font-family: Arial, Tahoma, sans-serif;
            font-size: 11pt;
            line-height: 1.45;
            color: #111;
        }

        h1 {
            text-align: center;
            font-size: 22pt;
            margin-bottom: 6px;
        }

        h2 {
            background: #d9eaf7;
            border: 1px solid #9dbbd4;
            padding: 8px;
            font-size: 15pt;
            margin-top: 24px;
        }

        h3 {
            font-size: 13pt;
            color: #1f4e79;
            margin-top: 16px;
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

        .ok {
            background: #d1e7dd;
            color: #0f5132;
            font-weight: bold;
        }

        .warning {
            background: #fff3cd;
            color: #664d03;
            font-weight: bold;
        }

        .danger {
            background: #f8d7da;
            color: #842029;
            font-weight: bold;
        }

        .neutral {
            background: #e2e3e5;
            color: #41464b;
            font-weight: bold;
        }

        .small {
            font-size: 9pt;
        }
    </style>
</head>

<body>

<div class="cover">
    <h1>Course Report to Course Specification Linkage Report</h1>
    <p><strong><?php echo safeText($programSpec["program_name"] ?? ""); ?></strong></p>
    <p><?php echo safeText($programSpec["college_name"] ?? ""); ?></p>
    <p><?php echo safeText($programSpec["institution_name"] ?? ""); ?></p>
    <p>Generated on: <?php echo date("Y-m-d H:i"); ?></p>
</div>

<h2>1. Program Specification Identity</h2>

<table>
    <tr>
        <th>Program Specification ID</th>
        <td><?php echo safeText($program_specification_id); ?></td>
        <th>Program Code</th>
        <td><?php echo safeText($programSpec["program_code"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Program Name</th>
        <td colspan="3"><?php echo safeText($programSpec["program_name"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Department</th>
        <td><?php echo safeText($programSpec["department_name"] ?? ""); ?></td>
        <th>College</th>
        <td><?php echo safeText($programSpec["college_name"] ?? ""); ?></td>
    </tr>
    <tr>
        <th>Institution</th>
        <td><?php echo safeText($programSpec["institution_name"] ?? ""); ?></td>
        <th>Qualification Level</th>
        <td><?php echo safeText($programSpec["qualification_level"] ?? ""); ?></td>
    </tr>
</table>

<h2>2. Linkage Summary</h2>

<table>
    <tr>
        <th>Total Course Reports</th>
        <th>Linked Correctly</th>
        <th>Ready to Link</th>
        <th>Mismatched</th>
        <th>Multiple Matches</th>
        <th>No Match</th>
        <th>Missing Code</th>
        <th>Link Coverage</th>
        <th>Overall Decision</th>
    </tr>
    <tr>
        <td class="center"><?php echo safeText($totalReports); ?></td>
        <td class="center ok"><?php echo safeText($linkedCorrectlyCount); ?></td>
        <td class="center warning"><?php echo safeText($readyToLinkCount); ?></td>
        <td class="center danger"><?php echo safeText($mismatchedCount); ?></td>
        <td class="center warning"><?php echo safeText($multipleMatchCount); ?></td>
        <td class="center danger"><?php echo safeText($noMatchCount); ?></td>
        <td class="center danger"><?php echo safeText($missingCodeCount); ?></td>
        <td class="center"><?php echo safeText($linkCoveragePercent); ?>%</td>
        <td class="center"><strong><?php echo safeText($overallDecision); ?></strong></td>
    </tr>
</table>

<h2>3. Linkage Logic</h2>

<table>
    <tr>
        <th>Linkage Rule</th>
        <th>Description</th>
    </tr>
    <tr>
        <td>Matching Key</td>
        <td>Course reports are matched to course specifications using normalized course code.</td>
    </tr>
    <tr>
        <td>Correct Link</td>
        <td>The course report course_specification_id points to the course specification with the same course code.</td>
    </tr>
    <tr>
        <td>Ready to Link</td>
        <td>A matching course specification exists, but the course report is not yet linked.</td>
    </tr>
    <tr>
        <td>Mismatched Link</td>
        <td>The course report is linked to a different course specification than the one matching its course code.</td>
    </tr>
    <tr>
        <td>No Match</td>
        <td>No course specification exists with the same course code.</td>
    </tr>
</table>

<h2>4. Detailed Course Report Linkage Status</h2>

<table>
    <tr>
        <th>No.</th>
        <th>Report ID</th>
        <th>Report Course Code</th>
        <th>Report Course Title</th>
        <th>Academic Year</th>
        <th>Semester</th>
        <th>Current Linked Spec ID</th>
        <th>Current Linked Spec</th>
        <th>Matched Spec ID</th>
        <th>Matched Spec</th>
        <th>Spec Status</th>
        <th>Match Count</th>
        <th>Link Status</th>
        <th>Issue</th>
    </tr>

    <?php if (count($rows) > 0): ?>
        <?php $i = 1; ?>
        <?php foreach ($rows as $row): ?>
            <?php
            $statusClass = "danger";

            if ($row["link_status"] === "Linked Correctly") {
                $statusClass = "ok";
            } elseif ($row["link_status"] === "Ready to Link" || $row["link_status"] === "Multiple Matching Specifications") {
                $statusClass = "warning";
            } elseif ($row["link_status"] === "Unlinked") {
                $statusClass = "neutral";
            }
            ?>
            <tr>
                <td class="center"><?php echo safeText($i); ?></td>
                <td class="center"><?php echo safeText($row["report_id"]); ?></td>
                <td><strong><?php echo safeText($row["report_course_code"]); ?></strong></td>
                <td><?php echo safeText($row["report_title"]); ?></td>
                <td><?php echo safeText($row["academic_year"]); ?></td>
                <td><?php echo safeText($row["semester"]); ?></td>
                <td class="center"><?php echo $row["linked_spec_id"] > 0 ? safeText($row["linked_spec_id"]) : "-"; ?></td>
                <td>
                    <?php if ($row["linked_spec_id"] > 0): ?>
                        <strong><?php echo safeText($row["linked_spec_code"]); ?></strong><br>
                        <?php echo safeText($row["linked_spec_title"]); ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td class="center"><?php echo safeText($row["matched_spec_id"] ?: "-"); ?></td>
                <td>
                    <?php if ($row["matched_spec_id"] !== ""): ?>
                        <strong><?php echo safeText($row["matched_spec_code"]); ?></strong><br>
                        <?php echo safeText($row["matched_spec_title"]); ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td><?php echo safeText($row["matched_spec_status"]); ?></td>
                <td class="center"><?php echo safeText($row["match_count"]); ?></td>
                <td class="<?php echo safeText($statusClass); ?>"><?php echo safeText($row["link_status"]); ?></td>
                <td><?php echo wordText($row["issue"] !== "" ? $row["issue"] : "OK"); ?></td>
            </tr>
            <?php $i++; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="14" class="danger">No course reports found.</td>
        </tr>
    <?php endif; ?>
</table>

<h2>5. Recommended Actions</h2>

<table>
    <tr>
        <th>Condition</th>
        <th>Recommended Action</th>
    </tr>
    <tr>
        <td>Ready to Link</td>
        <td>Use the linkage page to bulk link course reports to matching course specifications.</td>
    </tr>
    <tr>
        <td>Mismatched Link</td>
        <td>Review the current link and relink the report to the correct course specification.</td>
    </tr>
    <tr>
        <td>Multiple Matching Specifications</td>
        <td>Remove duplicate course specification records or manually select the correct specification.</td>
    </tr>
    <tr>
        <td>No Match</td>
        <td>Upload/import the missing course specification or correct the course code.</td>
    </tr>
    <tr>
        <td>Missing Course Code</td>
        <td>Update the course report record with the correct course code.</td>
    </tr>
</table>

</body>
</html>