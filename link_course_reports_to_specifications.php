<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

$program_specification_id = isset($_GET["program_specification_id"]) && is_numeric($_GET["program_specification_id"])
    ? (int)$_GET["program_specification_id"]
    : 3;

$error = "";
$success = "";
$linkResults = [];

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

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

function normalizeCode($code) {
    $code = strtoupper(trim((string)$code));
    $code = preg_replace('/\s+/', '', $code);
    return $code;
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "course_specifications")) {
    die("course_specifications table does not exist.");
}

if (!tableExists($conn, "course_reports")) {
    die("course_reports table does not exist.");
}

/*
    Make sure course_reports has course_specification_id.
*/
addColumnIfMissing($conn, "course_reports", "course_specification_id", "INT NULL");

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

$courseSpecsByCode = [];

foreach ($courseSpecs as $spec) {
    $code = normalizeCode($spec["course_code"] ?? "");

    if ($code !== "") {
        if (!isset($courseSpecsByCode[$code])) {
            $courseSpecsByCode[$code] = [];
        }

        $courseSpecsByCode[$code][] = $spec;
    }
}

/*
    Load course reports.
    Keep query schema-flexible because your table may have different optional columns.
*/
$reportSelectColumns = ["cr.*"];

$reportSql = "
    SELECT " . implode(", ", $reportSelectColumns) . "
    FROM course_reports cr
    ORDER BY cr.id DESC
";
$reportStmt = $conn->query($reportSql);
$courseReports = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

/*
    Build matching rows.
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

    $currentSpecId = isset($report["course_specification_id"]) && is_numeric($report["course_specification_id"])
        ? (int)$report["course_specification_id"]
        : 0;

    $matchedSpecs = $reportCourseCode !== "" && isset($courseSpecsByCode[$reportCourseCode])
        ? $courseSpecsByCode[$reportCourseCode]
        : [];

    $bestMatch = null;
    $matchStatus = "No Match";
    $issue = "";

    if ($reportCourseCode === "") {
        $matchStatus = "Missing Course Code";
        $issue = "Course report has no course_code value.";
    } elseif (count($matchedSpecs) === 0) {
        $matchStatus = "No Match";
        $issue = "No course specification found with the same course code.";
    } elseif (count($matchedSpecs) === 1) {
        $bestMatch = $matchedSpecs[0];

        if ($currentSpecId > 0 && $currentSpecId === (int)$bestMatch["id"]) {
            $matchStatus = "Already Linked";
        } elseif ($currentSpecId > 0 && $currentSpecId !== (int)$bestMatch["id"]) {
            $matchStatus = "Different Link";
            $issue = "Report is linked to another course specification ID.";
        } else {
            $matchStatus = "Ready to Link";
        }
    } else {
        $matchStatus = "Multiple Matches";
        $issue = "More than one course specification has this course code.";
        $bestMatch = $matchedSpecs[0];
    }

    $rows[] = [
        "report" => $report,
        "report_id" => $reportId,
        "report_course_code" => $reportCourseCode,
        "current_spec_id" => $currentSpecId,
        "matched_specs" => $matchedSpecs,
        "best_match" => $bestMatch,
        "match_status" => $matchStatus,
        "issue" => $issue
    ];
}

/*
    Bulk link action.
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "bulk_link") {
        $selectedReportIds = $_POST["report_ids"] ?? [];

        if (!is_array($selectedReportIds) || count($selectedReportIds) === 0) {
            $error = "Select at least one report to link.";
        } else {
            $selectedMap = array_flip(array_map("strval", $selectedReportIds));

            foreach ($rows as $row) {
                $reportId = (int)$row["report_id"];

                if (!isset($selectedMap[(string)$reportId])) {
                    continue;
                }

                $bestMatch = $row["best_match"];

                if (!$bestMatch) {
                    $linkResults[] = [
                        "report_id" => $reportId,
                        "course_code" => $row["report_course_code"],
                        "status" => "Skipped",
                        "message" => "No matching course specification."
                    ];
                    continue;
                }

                $matchedSpecId = (int)$bestMatch["id"];

                try {
                    $updateStmt = $conn->prepare("
                        UPDATE course_reports
                        SET course_specification_id = :course_specification_id
                        WHERE id = :id
                    ");
                    $updateStmt->execute([
                        ":course_specification_id" => $matchedSpecId,
                        ":id" => $reportId
                    ]);

                    logActivitySafe(
                        $conn,
                        "Link",
                        "Course Report to Course Specification",
                        $reportId,
                        "Linked course report ID " . $reportId . " to course specification ID " . $matchedSpecId
                    );

                    $linkResults[] = [
                        "report_id" => $reportId,
                        "course_code" => $row["report_course_code"],
                        "status" => "Linked",
                        "message" => "Linked to course specification ID " . $matchedSpecId
                    ];
                } catch (Exception $e) {
                    $linkResults[] = [
                        "report_id" => $reportId,
                        "course_code" => $row["report_course_code"],
                        "status" => "Failed",
                        "message" => $e->getMessage()
                    ];
                }
            }

            $success = "Bulk linking completed. Review the result table.";

            /*
                Reload reports and rows after linking.
            */
            $reportStmt = $conn->query($reportSql);
            $courseReports = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

            $rows = [];

            foreach ($courseReports as $report) {
                $reportId = (int)($report["id"] ?? 0);

                $reportCourseCode = "";

                if (isset($report["course_code"])) {
                    $reportCourseCode = normalizeCode($report["course_code"]);
                } elseif (isset($report["code"])) {
                    $reportCourseCode = normalizeCode($report["code"]);
                }

                $currentSpecId = isset($report["course_specification_id"]) && is_numeric($report["course_specification_id"])
                    ? (int)$report["course_specification_id"]
                    : 0;

                $matchedSpecs = $reportCourseCode !== "" && isset($courseSpecsByCode[$reportCourseCode])
                    ? $courseSpecsByCode[$reportCourseCode]
                    : [];

                $bestMatch = null;
                $matchStatus = "No Match";
                $issue = "";

                if ($reportCourseCode === "") {
                    $matchStatus = "Missing Course Code";
                    $issue = "Course report has no course_code value.";
                } elseif (count($matchedSpecs) === 0) {
                    $matchStatus = "No Match";
                    $issue = "No course specification found with the same course code.";
                } elseif (count($matchedSpecs) === 1) {
                    $bestMatch = $matchedSpecs[0];

                    if ($currentSpecId > 0 && $currentSpecId === (int)$bestMatch["id"]) {
                        $matchStatus = "Already Linked";
                    } elseif ($currentSpecId > 0 && $currentSpecId !== (int)$bestMatch["id"]) {
                        $matchStatus = "Different Link";
                        $issue = "Report is linked to another course specification ID.";
                    } else {
                        $matchStatus = "Ready to Link";
                    }
                } else {
                    $matchStatus = "Multiple Matches";
                    $issue = "More than one course specification has this course code.";
                    $bestMatch = $matchedSpecs[0];
                }

                $rows[] = [
                    "report" => $report,
                    "report_id" => $reportId,
                    "report_course_code" => $reportCourseCode,
                    "current_spec_id" => $currentSpecId,
                    "matched_specs" => $matchedSpecs,
                    "best_match" => $bestMatch,
                    "match_status" => $matchStatus,
                    "issue" => $issue
                ];
            }
        }
    }

    if ($action === "unlink_selected") {
        $selectedReportIds = $_POST["report_ids"] ?? [];

        if (!is_array($selectedReportIds) || count($selectedReportIds) === 0) {
            $error = "Select at least one report to unlink.";
        } else {
            foreach ($selectedReportIds as $reportId) {
                if (!is_numeric($reportId)) {
                    continue;
                }

                $updateStmt = $conn->prepare("
                    UPDATE course_reports
                    SET course_specification_id = NULL
                    WHERE id = :id
                ");
                $updateStmt->execute([":id" => (int)$reportId]);

                logActivitySafe(
                    $conn,
                    "Unlink",
                    "Course Report from Course Specification",
                    (int)$reportId,
                    "Unlinked course report ID " . (int)$reportId . " from course specification."
                );
            }

            header("Location: link_course_reports_to_specifications.php?program_specification_id=" . $program_specification_id . "&unlinked=1");
            exit;
        }
    }
}

if (isset($_GET["unlinked"])) {
    $success = "Selected course reports were unlinked.";
}

/*
    Summary.
*/
$totalReports = count($rows);
$alreadyLinkedCount = 0;
$readyToLinkCount = 0;
$noMatchCount = 0;
$multipleMatchCount = 0;
$differentLinkCount = 0;
$missingCodeCount = 0;

foreach ($rows as $row) {
    if ($row["match_status"] === "Already Linked") {
        $alreadyLinkedCount++;
    } elseif ($row["match_status"] === "Ready to Link") {
        $readyToLinkCount++;
    } elseif ($row["match_status"] === "No Match") {
        $noMatchCount++;
    } elseif ($row["match_status"] === "Multiple Matches") {
        $multipleMatchCount++;
    } elseif ($row["match_status"] === "Different Link") {
        $differentLinkCount++;
    } elseif ($row["match_status"] === "Missing Course Code") {
        $missingCodeCount++;
    }
}

$linkCoveragePercent = $totalReports > 0 ? round(($alreadyLinkedCount / $totalReports) * 100, 1) : 0;

function statusBadgeClass($status) {
    if ($status === "Already Linked") {
        return "badge-ok";
    }

    if ($status === "Ready to Link") {
        return "badge-core";
    }

    if ($status === "Different Link" || $status === "Multiple Matches") {
        return "badge-warning";
    }

    return "badge-danger";
}

function getReportTitle($report) {
    $candidates = [
        "course_title",
        "title",
        "report_title",
        "course_name",
        "name"
    ];

    foreach ($candidates as $field) {
        if (isset($report[$field]) && trim((string)$report[$field]) !== "") {
            return $report[$field];
        }
    }

    return "";
}

function getReportAcademicYear($report) {
    $candidates = [
        "academic_year",
        "year",
        "report_year"
    ];

    foreach ($candidates as $field) {
        if (isset($report[$field]) && trim((string)$report[$field]) !== "") {
            return $report[$field];
        }
    }

    return "";
}

function getReportSemester($report) {
    $candidates = [
        "semester",
        "term",
        "report_semester"
    ];

    foreach ($candidates as $field) {
        if (isset($report[$field]) && trim((string)$report[$field]) !== "") {
            return $report[$field];
        }
    }

    return "";
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Link Course Reports to Specifications</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(130px, 1fr));
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
            font-size: 25px;
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

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .alert-success {
            background: #d1e7dd;
            border: 1px solid #badbcc;
            color: #0f5132;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .wide-table {
            min-width: 1450px;
            width: 100%;
            border-collapse: collapse;
        }

        .badge-ok {
            background: #d1e7dd;
            color: #0f5132;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }

        .badge-core {
            background: #cff4fc;
            color: #055160;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }

        .badge-warning {
            background: #fff3cd;
            color: #664d03;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }

        .badge-danger {
            background: #f8d7da;
            color: #842029;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .issue-text {
            font-size: 12px;
            color: #842029;
            line-height: 1.4;
        }

        .progress-wrap {
            background: #e9ecef;
            border-radius: 999px;
            height: 24px;
            overflow: hidden;
            border: 1px solid #ccc;
            margin-bottom: 12px;
        }

        .progress-bar {
            height: 24px;
            background: #0d6efd;
            color: white;
            text-align: center;
            line-height: 24px;
            font-weight: bold;
            font-size: 12px;
        }

        @media (max-width: 1100px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(130px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Link Course Reports to Course Specifications</h1>
        <p>Automatically link course reports to imported/approved course specifications using course code.</p>
    </div>

    <div class="actions">
        <a href="program_specification_dashboard.php?id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Program Spec Dashboard
        </a>

        <a href="course_specification_package_dashboard.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Course Spec Package
        </a>

        <a href="course_reports.php" class="btn">
            Course Reports
        </a>

        <a href="course_specifications.php" class="btn">
            Course Specifications
        </a>

        <a href="course_specification_validation.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Course Spec Validation
        </a>
	<a href="export_course_report_specification_linkage_word.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
    Export Linkage Report
</a>
    </div>

    <div class="panel">
        <h2>Program Specification</h2>

        <table>
            <tr>
                <th>Program Specification ID</th>
                <td><?php echo safeText($program_specification_id); ?></td>
                <th>Program</th>
                <td><?php echo safeText($programSpec["program_name"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Program Code</th>
                <td><?php echo safeText($programSpec["program_code"] ?? ""); ?></td>
                <th>College</th>
                <td><?php echo safeText($programSpec["college_name"] ?? ""); ?></td>
            </tr>
        </table>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error"><?php echo safeText($error); ?></div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="alert-success"><?php echo safeText($success); ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Link Coverage</h2>

        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText($linkCoveragePercent); ?>%;">
                <?php echo safeText($linkCoveragePercent); ?>%
            </div>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalReports); ?></div>
            <div class="summary-label">Course Reports</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($alreadyLinkedCount); ?></div>
            <div class="summary-label">Already Linked</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($readyToLinkCount); ?></div>
            <div class="summary-label">Ready to Link</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($differentLinkCount); ?></div>
            <div class="summary-label">Different Link</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($multipleMatchCount); ?></div>
            <div class="summary-label">Multiple Matches</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($noMatchCount + $missingCodeCount); ?></div>
            <div class="summary-label">No Match / Missing Code</div>
        </div>
    </div>

    <?php if (!empty($linkResults)): ?>
        <div class="panel">
            <h2>Bulk Link Results</h2>

            <table>
                <tr>
                    <th>Report ID</th>
                    <th>Course Code</th>
                    <th>Status</th>
                    <th>Message</th>
                </tr>

                <?php foreach ($linkResults as $result): ?>
                    <tr>
                        <td><?php echo safeText($result["report_id"]); ?></td>
                        <td><?php echo safeText($result["course_code"]); ?></td>
                        <td><?php echo safeText($result["status"]); ?></td>
                        <td><?php echo safeText($result["message"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Course Report Link Review</h2>

        <form method="POST" action="link_course_reports_to_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>">

            <div class="actions">
                <button type="submit"
                        name="action"
                        value="bulk_link"
                        class="btn btn-purple"
                        onclick="return confirm('Link selected course reports to matching course specifications?');">
                    Link Selected
                </button>

                <button type="submit"
                        name="action"
                        value="unlink_selected"
                        class="btn btn-delete"
                        onclick="return confirm('Unlink selected course reports from course specifications?');">
                    Unlink Selected
                </button>
            </div>

            <div class="wide-table-wrapper">
                <table class="wide-table">
                    <tr>
                        <th>Select</th>
                        <th>Report ID</th>
                        <th>Report Course Code</th>
                        <th>Report Course Title</th>
                        <th>Academic Year</th>
                        <th>Semester</th>
                        <th>Current Spec ID</th>
                        <th>Matched Spec ID</th>
                        <th>Matched Spec Course</th>
                        <th>Spec Status</th>
                        <th>Match Status</th>
                        <th>Issue</th>
                        <th>Actions</th>
                    </tr>

                    <?php if (count($rows) > 0): ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $report = $row["report"];
                            $bestMatch = $row["best_match"];
                            $status = $row["match_status"];
                            $badgeClass = statusBadgeClass($status);

                            $checked = "";
                            if ($status === "Ready to Link" || $status === "Different Link") {
                                $checked = "checked";
                            }
                            ?>
                            <tr>
                                <td>
                                    <?php if ($bestMatch || $row["current_spec_id"] > 0): ?>
                                        <input type="checkbox"
                                               name="report_ids[]"
                                               value="<?php echo safeText($row["report_id"]); ?>"
                                               <?php echo $checked; ?>>
                                    <?php endif; ?>
                                </td>

                                <td><?php echo safeText($row["report_id"]); ?></td>

                                <td>
                                    <strong><?php echo safeText($row["report_course_code"]); ?></strong>
                                </td>

                                <td><?php echo safeText(getReportTitle($report)); ?></td>

                                <td><?php echo safeText(getReportAcademicYear($report)); ?></td>

                                <td><?php echo safeText(getReportSemester($report)); ?></td>

                                <td>
                                    <?php echo $row["current_spec_id"] > 0 ? safeText($row["current_spec_id"]) : "-"; ?>
                                </td>

                                <td>
                                    <?php echo $bestMatch ? safeText($bestMatch["id"] ?? "") : "-"; ?>
                                </td>

                                <td>
                                    <?php if ($bestMatch): ?>
                                        <strong><?php echo safeText($bestMatch["course_code"] ?? ""); ?></strong><br>
                                        <?php echo safeText($bestMatch["course_title"] ?? ""); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php echo $bestMatch ? safeText($bestMatch["status"] ?? "") : "-"; ?>
                                </td>

                                <td>
                                    <span class="<?php echo safeText($badgeClass); ?>">
                                        <?php echo safeText($status); ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($row["issue"] !== ""): ?>
                                        <div class="issue-text">
                                            <?php echo safeText($row["issue"]); ?>
                                        </div>

                                        <?php if ($status === "Multiple Matches" && count($row["matched_specs"]) > 1): ?>
                                            <div class="small-text">
                                                Matches:
                                                <?php foreach ($row["matched_specs"] as $match): ?>
                                                    <br>
                                                    ID <?php echo safeText($match["id"] ?? ""); ?> -
                                                    <?php echo safeText($match["course_code"] ?? ""); ?> -
                                                    <?php echo safeText($match["course_title"] ?? ""); ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="small-text">OK</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($row["current_spec_id"] > 0): ?>
                                        <a href="course_specification_profile.php?id=<?php echo safeText($row["current_spec_id"]); ?>" class="btn">
                                            Current Spec
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($bestMatch): ?>
                                        <a href="course_specification_profile.php?id=<?php echo safeText($bestMatch["id"]); ?>" class="btn btn-purple">
                                            Matched Spec
                                        </a>
                                    <?php endif; ?>

                                    <?php if (isset($report["id"])): ?>
                                        <a href="view_course_report.php?id=<?php echo safeText($report["id"]); ?>" class="btn">
                                            View Report
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="13">No course reports found.</td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>

        </form>
    </div>

</div>

</body>
</html>
