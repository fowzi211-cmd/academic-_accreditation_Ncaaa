<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid course specification ID.");
}

$spec_id = (int)$_GET["id"];

// ===============================
// Helper functions
// ===============================
function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function showText($text) {
    if ($text === null || trim((string)$text) === "") {
        return '<span class="missing">Not provided</span>';
    }

    return nl2br(htmlspecialchars((string)$text, ENT_QUOTES, "UTF-8"));
}

function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SHOW TABLES LIKE :table_name");
        $stmt->execute([":table_name" => $tableName]);
        return $stmt->fetch(PDO::FETCH_NUM) ? true : false;
    } catch (Exception $e) {
        return false;
    }
}

function columnExists($conn, $tableName, $columnName) {
    try {
        if (!tableExists($conn, $tableName)) {
            return false;
        }

        $stmt = $conn->prepare("SHOW COLUMNS FROM `$tableName` LIKE :column_name");
        $stmt->execute([":column_name" => $columnName]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ? true : false;
    } catch (Exception $e) {
        return false;
    }
}

function loadRows($conn, $table, $spec_id) {
    $allowedTables = [
        "course_specification_clos",
        "course_specification_topics",
        "course_specification_assessments",
        "course_specification_resources",
        "course_specification_facilities",
        "course_specification_quality_assessment",
        "course_specification_teaching_modes",
        "course_specification_contact_hours"
    ];

    if (!in_array($table, $allowedTables, true)) {
        return [];
    }

    if (!tableExists($conn, $table)) {
        return [];
    }

    $orderSql = "ORDER BY id ASC";

    if (columnExists($conn, $table, "display_order")) {
        $orderSql = "ORDER BY display_order ASC, id ASC";
    } elseif (columnExists($conn, $table, "sort_order")) {
        $orderSql = "ORDER BY sort_order ASC, id ASC";
    } elseif (columnExists($conn, $table, "sequence_no")) {
        $orderSql = "ORDER BY sequence_no ASC, id ASC";
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM `$table`
        WHERE course_specification_id = :course_specification_id
        $orderSql
    ");

    $stmt->execute([
        ":course_specification_id" => $spec_id
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function valueFromRow($row, $keys, $default = "") {
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== "") {
            return $row[$key];
        }
    }

    return $default;
}

// ===============================
// Load main specification
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM course_specifications
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $spec_id]);
$spec = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$spec) {
    die("Course specification not found.");
}

// ===============================
// Load child records
// ===============================
$cloRows = loadRows($conn, "course_specification_clos", $spec_id);
$topicRows = loadRows($conn, "course_specification_topics", $spec_id);
$assessmentRows = loadRows($conn, "course_specification_assessments", $spec_id);
$resourceRows = loadRows($conn, "course_specification_resources", $spec_id);
$facilityRows = loadRows($conn, "course_specification_facilities", $spec_id);
$qualityRows = loadRows($conn, "course_specification_quality_assessment", $spec_id);
$teachingModeRows = loadRows($conn, "course_specification_teaching_modes", $spec_id);
$contactHourRows = loadRows($conn, "course_specification_contact_hours", $spec_id);

// ===============================
// Load linked course reports
// ===============================
$linkedReports = [];

if (tableExists($conn, "course_reports") && columnExists($conn, "course_reports", "course_specification_id")) {
    $linkedStmt = $conn->prepare("
        SELECT *
        FROM course_reports
        WHERE course_specification_id = :course_specification_id
        ORDER BY id DESC
    ");
    $linkedStmt->execute([
        ":course_specification_id" => $spec_id
    ]);
    $linkedReports = $linkedStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>View Course Specification</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .actions {
            margin-bottom: 18px;
        }

        .meta-table th {
            width: 260px;
        }

        .missing {
            color: #999;
            font-style: italic;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .section-text {
            line-height: 1.6;
        }

        .status-active {
            color: #28a745;
            font-weight: bold;
        }

        .status-inactive {
            color: #dc3545;
            font-weight: bold;
        }

        .status-draft {
            color: #fd7e14;
            font-weight: bold;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .summary-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
        }

        .summary-number {
            font-size: 28px;
            font-weight: bold;
            color: #007bff;
        }

        .summary-label {
            color: #555;
            font-size: 13px;
            margin-top: 6px;
        }

        .linked-ok {
            color: #28a745;
            font-weight: bold;
        }

        .linked-warning {
            color: #dc3545;
            font-weight: bold;
        }

        @media print {
            .top-bar,
            nav,
            .actions,
            .btn {
                display: none !important;
            }

            body {
                background: white;
                margin: 20px;
            }

            .container {
                width: 100%;
                max-width: none;
                margin: 0;
            }

            .panel {
                border: 1px solid #999;
                box-shadow: none;
                page-break-inside: avoid;
            }

            table {
                font-size: 11px;
            }
        }

        @media (max-width: 900px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(160px, 1fr));
            }
        }

        @media (max-width: 600px) {
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
        <h1>View Course Specification</h1>
        <p>Review the approved course specification source data used for course reports and automated reporting.</p>
    </div>

    <div class="actions">
        <a href="course_specifications.php" class="btn btn-secondary">Back to Course Specifications</a>

        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
            <a href="edit_course_specification.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-edit">
                Edit Main Specification
            </a>

            <a href="manage_course_specification_details.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
                Manage Details
            </a>
        <?php endif; ?>

        <button onclick="window.print();" class="btn btn-purple">
            Print / Save as PDF
        </button>
    </div>

    <div class="panel">
        <h2>
            <?php echo safeText($spec["course_code"] ?? ""); ?>
            -
            <?php echo safeText($spec["course_title"] ?? ""); ?>
        </h2>

        <table class="meta-table">
            <tr>
                <th>Course Title</th>
                <td><?php echo safeText($spec["course_title"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Course Code</th>
                <td><?php echo safeText($spec["course_code"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Program</th>
                <td><?php echo safeText($spec["program_name"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Department</th>
                <td><?php echo safeText($spec["department_name"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>College</th>
                <td><?php echo safeText($spec["college_name"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Institution</th>
                <td><?php echo safeText($spec["institution_name"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Version</th>
                <td><?php echo safeText($spec["specification_version"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Last Revision Date</th>
                <td><?php echo safeText($spec["last_revision_date"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Status</th>
                <td>
                    <?php
                        $statusClass = "";
                        $status = $spec["status"] ?? "";

                        if ($status === "Active") {
                            $statusClass = "status-active";
                        } elseif ($status === "Inactive") {
                            $statusClass = "status-inactive";
                        } elseif ($status === "Draft") {
                            $statusClass = "status-draft";
                        }
                    ?>
                    <span class="<?php echo safeText($statusClass); ?>">
                        <?php echo safeText($status); ?>
                    </span>
                </td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($cloRows)); ?></div>
            <div class="summary-label">CLOs</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($topicRows)); ?></div>
            <div class="summary-label">Topics</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($assessmentRows)); ?></div>
            <div class="summary-label">Assessment Activities</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($linkedReports)); ?></div>
            <div class="summary-label">Linked Course Reports</div>
        </div>
    </div>

    <div class="panel">
        <h2>A. General Information about the Course</h2>

        <table class="meta-table">
            <tr>
                <th>Credit Hours</th>
                <td><?php echo safeText($spec["credit_hours"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Theoretical Hours</th>
                <td><?php echo safeText($spec["theoretical_hours"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Practical Hours</th>
                <td><?php echo safeText($spec["practical_hours"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Course Type A</th>
                <td><?php echo safeText($spec["course_type_a"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Course Type B</th>
                <td><?php echo safeText($spec["course_type_b"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Level / Year</th>
                <td><?php echo safeText($spec["level_year"] ?? ""); ?></td>
            </tr>
        </table>

        <h3>Course General Description</h3>
        <div class="section-text">
            <?php echo showText($spec["course_description"] ?? ""); ?>
        </div>

        <h3>Pre-requisites</h3>
        <div class="section-text">
            <?php echo showText($spec["pre_requisites"] ?? ""); ?>
        </div>

        <h3>Co-requisites</h3>
        <div class="section-text">
            <?php echo showText($spec["co_requisites"] ?? ""); ?>
        </div>

        <h3>Course Main Objectives</h3>
        <div class="section-text">
            <?php echo showText($spec["course_objectives"] ?? ""); ?>
        </div>
    </div>

    <div class="panel">
        <h2>Teaching Mode</h2>

        <?php if (count($teachingModeRows) > 0): ?>
            <table>
                <tr>
                    <th>No.</th>
                    <th>Mode of Instruction</th>
                    <th>Contact Hours</th>
                    <th>Percentage</th>
                </tr>

                <?php foreach ($teachingModeRows as $index => $row): ?>
                    <tr>
                        <td><?php echo safeText($index + 1); ?></td>
                        <td><?php echo safeText($row["mode_of_instruction"] ?? ""); ?></td>
                        <td><?php echo safeText($row["contact_hours"] ?? ""); ?></td>
                        <td><?php echo safeText($row["percentage"] ?? ""); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No teaching modes added yet.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Contact Hours</h2>

        <?php if (count($contactHourRows) > 0): ?>
            <table>
                <tr>
                    <th>No.</th>
                    <th>Activity</th>
                    <th>Contact Hours</th>
                </tr>

                <?php foreach ($contactHourRows as $index => $row): ?>
                    <tr>
                        <td><?php echo safeText($index + 1); ?></td>
                        <td><?php echo safeText($row["activity"] ?? ""); ?></td>
                        <td><?php echo safeText($row["contact_hours"] ?? ""); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No contact hours added yet.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>B. Course Learning Outcomes, Teaching Strategies and Assessment Methods</h2>

        <?php if (count($cloRows) > 0): ?>
            <table>
                <tr>
                    <th>Code</th>
                    <th>Domain</th>
                    <th>Course Learning Outcomes</th>
                    <th>Aligned PLO</th>
                    <th>Teaching Strategies</th>
                    <th>Assessment Methods</th>
                </tr>

                <?php foreach ($cloRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["clo_code"] ?? ""); ?></td>
                        <td><?php echo safeText($row["clo_domain"] ?? ""); ?></td>
                        <td><?php echo nl2br(safeText($row["clo_text"] ?? "")); ?></td>
                        <td><?php echo safeText($row["aligned_plo_code"] ?? ""); ?></td>
                        <td><?php echo nl2br(safeText($row["teaching_strategies"] ?? "")); ?></td>
                        <td><?php echo nl2br(safeText($row["assessment_methods"] ?? "")); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No CLOs added yet.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>C. Course Content</h2>

        <?php if (count($topicRows) > 0): ?>
            <table>
                <tr>
                    <th>No.</th>
                    <th>List of Topics</th>
                    <th>Contact Hours</th>
                </tr>

                <?php foreach ($topicRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["topic_no"] ?? ""); ?></td>
                        <td><?php echo nl2br(safeText($row["topic_title"] ?? "")); ?></td>
                        <td><?php echo safeText($row["contact_hours"] ?? ""); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No topics added yet.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>D. Students Assessment Activities</h2>

        <?php if (count($assessmentRows) > 0): ?>
            <table>
                <tr>
                    <th>No.</th>
                    <th>Assessment Activities</th>
                    <th>Assessment Timing</th>
                    <th>Percentage of Total Assessment Score</th>
                </tr>

                <?php foreach ($assessmentRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["assessment_no"] ?? ""); ?></td>
                        <td><?php echo safeText($row["assessment_activity"] ?? ""); ?></td>
                        <td><?php echo safeText($row["assessment_timing"] ?? ""); ?></td>
                        <td><?php echo safeText($row["percentage"] ?? ""); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No assessment activities added yet.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>E. Learning Resources and Facilities</h2>

        <h3>References and Learning Resources</h3>

        <?php if (count($resourceRows) > 0): ?>
            <table>
                <tr>
                    <th>Resource Type</th>
                    <th>Resource Text</th>
                </tr>

                <?php foreach ($resourceRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["resource_type"] ?? ""); ?></td>
                        <td><?php echo nl2br(safeText($row["resource_text"] ?? "")); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No learning resources added yet.</p>
        <?php endif; ?>

        <h3>Required Facilities and Equipment</h3>

        <?php if (count($facilityRows) > 0): ?>
            <table>
                <tr>
                    <th>Items</th>
                    <th>Resources</th>
                </tr>

                <?php foreach ($facilityRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["facility_item"] ?? ""); ?></td>
                        <td><?php echo nl2br(safeText($row["facility_resources"] ?? "")); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No facilities/equipment added yet.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>F. Assessment of Course Quality</h2>

        <?php if (count($qualityRows) > 0): ?>
            <table>
                <tr>
                    <th>Assessment Areas / Issues</th>
                    <th>Assessor</th>
                    <th>Assessment Methods</th>
                </tr>

                <?php foreach ($qualityRows as $row): ?>
                    <tr>
                        <td><?php echo nl2br(safeText($row["assessment_area"] ?? "")); ?></td>
                        <td><?php echo safeText($row["assessor"] ?? ""); ?></td>
                        <td><?php echo safeText($row["assessment_methods"] ?? ""); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No course quality assessment rows added yet.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>G. Specification Approval</h2>

        <table class="meta-table">
            <tr>
                <th>Council / Committee</th>
                <td><?php echo safeText($spec["approval_council"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Reference No.</th>
                <td><?php echo safeText($spec["approval_reference_no"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Date</th>
                <td><?php echo safeText($spec["approval_date"] ?? ""); ?></td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>H. Linked Course Reports</h2>

        <?php if (count($linkedReports) > 0): ?>
            <p class="linked-ok">
                This specification is linked to <?php echo safeText(count($linkedReports)); ?> course report(s).
            </p>

            <table>
                <tr>
                    <th>No.</th>
                    <th>Course Report</th>
                    <th>Academic Year</th>
                    <th>Semester</th>
                    <th>Coordinator</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>

                <?php foreach ($linkedReports as $index => $report): ?>
                    <tr>
                        <td><?php echo safeText($index + 1); ?></td>

                        <td>
                            <?php
                                $reportCode = valueFromRow($report, ["course_code"], valueFromRow($spec, ["course_code"], ""));
                                $reportTitle = valueFromRow($report, ["course_title"], valueFromRow($spec, ["course_title"], ""));
                                echo safeText(trim($reportCode . " - " . $reportTitle, " -"));
                            ?>
                        </td>

                        <td><?php echo safeText(valueFromRow($report, ["academic_year"], "")); ?></td>

                        <td><?php echo safeText(valueFromRow($report, ["semester"], "")); ?></td>

                        <td><?php echo safeText(valueFromRow($report, ["course_coordinator", "coordinator"], "")); ?></td>

                        <td><?php echo safeText(valueFromRow($report, ["status"], "")); ?></td>

                        <td>
                            <a href="view_course_report.php?id=<?php echo safeText($report["id"] ?? ""); ?>" class="btn btn-view">
                                View Report
                            </a>

                            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty"])): ?>
                                <a href="edit_course_report.php?id=<?php echo safeText($report["id"] ?? ""); ?>" class="btn btn-edit">
                                    Edit
                                </a>
                            <?php endif; ?>

                            <a href="export_course_report_word.php?id=<?php echo safeText($report["id"] ?? ""); ?>" class="btn btn-purple">
                                Export Word
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

        <?php else: ?>
            <p class="linked-warning">
                No Course Reports are currently linked to this Course Specification.
            </p>

            <p>
                This means the specification exists, but it has not yet been used as the official source for any Course Report.
            </p>
        <?php endif; ?>
    </div>

</div>

</body>
</html>