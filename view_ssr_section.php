<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid SSR section ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Load SSR section with standard
// ===============================
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

// ===============================
// Load substandards
// ===============================
$subStmt = $conn->prepare("
    SELECT *
    FROM substandards
    WHERE standard_id = :standard_id
    ORDER BY substandard_code
");
$subStmt->execute([":standard_id" => $standard_id]);
$substandards = $subStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Evidence count
// ===============================
$evidenceCountStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM evidence_items e
    INNER JOIN substandards ss ON e.substandard_id = ss.id
    WHERE ss.standard_id = :standard_id
");
$evidenceCountStmt->execute([":standard_id" => $standard_id]);
$totalEvidence = (int)$evidenceCountStmt->fetchColumn();

// ===============================
// Covered substandards
// ===============================
$coveredStmt = $conn->prepare("
    SELECT COUNT(DISTINCT ss.id)
    FROM substandards ss
    INNER JOIN evidence_items e ON e.substandard_id = ss.id
    WHERE ss.standard_id = :standard_id
");
$coveredStmt->execute([":standard_id" => $standard_id]);
$coveredSubstandards = (int)$coveredStmt->fetchColumn();

$totalSubstandards = count($substandards);
$coverageRate = $totalSubstandards > 0 ? round(($coveredSubstandards / $totalSubstandards) * 100, 1) : 0;

// ===============================
// Load evidence items for this standard
// ===============================
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

// ===============================
// Load related tasks
// ===============================
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

// ===============================
// Helpers
// ===============================
function showText($text) {
    if ($text === null || trim((string)$text) === "") {
        return '<span class="missing">Not provided</span>';
    }

    return nl2br(htmlspecialchars((string)$text));
}

function safeText($text) {
    return htmlspecialchars((string)($text ?? ""));
}

function statusBadge($status) {
    $status = $status ?: "Draft";

    if ($status === "Approved") {
        return '<span class="badge badge-success">Approved</span>';
    }

    if ($status === "Under Review") {
        return '<span class="badge badge-warning">Under Review</span>';
    }

    if ($status === "Draft") {
        return '<span class="badge badge-info">Draft</span>';
    }

    if ($status === "Archived") {
        return '<span class="badge badge-muted">Archived</span>';
    }

    return '<span class="badge badge-muted">' . htmlspecialchars($status) . '</span>';
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>View SSR Section</title>
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

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 12px;
        }

        .summary-card {
            background: white;
            padding: 16px;
            border: 1px solid #ddd;
            border-radius: 10px;
        }

        .summary-number {
            font-size: 28px;
            font-weight: bold;
            color: #007bff;
            line-height: 1;
        }

        .summary-label {
            color: #555;
            margin-top: 8px;
            font-size: 13px;
        }

        .meta-table th {
            width: 220px;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            white-space: nowrap;
        }

        .badge-success {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-warning {
            background: #fff3cd;
            color: #664d03;
        }

        .badge-info {
            background: #cff4fc;
            color: #055160;
        }

        .badge-muted {
            background: #e9ecef;
            color: #495057;
        }

        .missing {
            color: #999;
            font-style: italic;
        }

        .narrative-box {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 16px;
            line-height: 1.6;
        }

        .narrative-box h3 {
            margin-top: 0;
        }

        .small-text {
            font-size: 12px;
            color: #555;
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
                margin: 18px;
            }

            .container {
                width: 100%;
                max-width: none;
                margin: 0;
            }

            .panel,
            .summary-card,
            .narrative-box {
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
        <h1>View SSR Section</h1>
        <p>Self-study narrative section linked to standard evidence, substandards, and improvement tasks.</p>
    </div>

    <div class="actions">
        <a href="ssr_builder.php" class="btn btn-secondary">Back to SSR Builder</a>

        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
            <a href="edit_ssr_section.php?id=<?php echo safeText($section["id"]); ?>" class="btn btn-edit">
                Edit SSR Section
            </a>
        <?php endif; ?>

        <a href="standard_evidence_report.php?standard_id=<?php echo safeText($standard_id); ?>" class="btn btn-purple">
            Evidence Map
        </a>

        <button onclick="window.print();" class="btn btn-purple">
            Print / Save as PDF
        </button>

        <a href="export_ssr_section_word.php?id=<?php echo safeText($section["id"]); ?>" class="btn">
            Export Word
        </a>
    </div>

    <div class="panel">
        <h2><?php echo safeText($section["section_title"]); ?></h2>

        <table class="meta-table">
            <tr>
                <th>Standard</th>
                <td>
                    <strong><?php echo safeText($section["standard_code"]); ?></strong>
                    -
                    <?php echo safeText($section["standard_title"]); ?>
                </td>
            </tr>

            <tr>
                <th>Standard Description</th>
                <td><?php echo showText($section["standard_description"]); ?></td>
            </tr>

            <tr>
                <th>Academic Year</th>
                <td><?php echo safeText($section["academic_year"]); ?></td>
            </tr>

            <tr>
                <th>Report Cycle</th>
                <td><?php echo safeText($section["report_cycle"]); ?></td>
            </tr>

            <tr>
                <th>Status</th>
                <td><?php echo statusBadge($section["status"]); ?></td>
            </tr>

            <tr>
                <th>Prepared By</th>
                <td><?php echo safeText($section["prepared_by"]); ?></td>
            </tr>

            <tr>
                <th>Reviewed By</th>
                <td><?php echo safeText($section["reviewed_by"]); ?></td>
            </tr>

            <tr>
                <th>Updated At</th>
                <td><?php echo safeText($section["updated_at"]); ?></td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Evidence Coverage Summary</h2>

        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-number"><?php echo safeText($totalSubstandards); ?></div>
                <div class="summary-label">Total Substandards</div>
            </div>

            <div class="summary-card">
                <div class="summary-number"><?php echo safeText($coveredSubstandards); ?></div>
                <div class="summary-label">Covered Substandards</div>
            </div>

            <div class="summary-card">
                <div class="summary-number"><?php echo safeText($coverageRate); ?>%</div>
                <div class="summary-label">Coverage Rate</div>
            </div>

            <div class="summary-card">
                <div class="summary-number"><?php echo safeText($totalEvidence); ?></div>
                <div class="summary-label">Evidence Items</div>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>SSR Narrative</h2>

        <div class="narrative-box">
            <h3>Evidence-Based Explanation</h3>
            <?php echo showText($section["evidence_based_explanation"]); ?>
        </div>

        <div class="narrative-box">
            <h3>Strengths</h3>
            <?php echo showText($section["strengths"]); ?>
        </div>

        <div class="narrative-box">
            <h3>Areas for Improvement</h3>
            <?php echo showText($section["areas_for_improvement"]); ?>
        </div>

        <div class="narrative-box">
            <h3>Action Plan</h3>
            <?php echo showText($section["action_plan"]); ?>
        </div>

        <div class="narrative-box">
            <h3>Conclusion</h3>
            <?php echo showText($section["conclusion"]); ?>
        </div>
    </div>

    <div class="panel">
        <h2>Substandards Under This Standard</h2>

        <?php if (count($substandards) > 0): ?>
            <table>
                <tr>
                    <th>Substandard</th>
                    <th>Requirement</th>
                </tr>

                <?php foreach ($substandards as $sub): ?>
                    <tr>
                        <td>
                            <strong><?php echo safeText($sub["substandard_code"]); ?></strong>
                        </td>

                        <td><?php echo showText($sub["substandard_text"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No substandards found for this standard.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
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
                        <td><?php echo safeText($evidence["id"]); ?></td>

                        <td>
                            <strong><?php echo safeText($evidence["title"]); ?></strong>
                        </td>

                        <td>
                            <strong><?php echo safeText($evidence["substandard_code"]); ?></strong><br>
                            <span class="small-text">
                                <?php echo safeText($evidence["substandard_text"]); ?>
                            </span>
                        </td>

                        <td><?php echo safeText($evidence["evidence_type"]); ?></td>

                        <td><?php echo safeText($evidence["responsible_person"]); ?></td>

                        <td><?php echo safeText($evidence["status"]); ?></td>

                        <td><?php echo safeText($evidence["review_status"]); ?></td>

                        <td><?php echo safeText($evidence["file_count"]); ?> file(s)</td>

                        <td><?php echo showText($evidence["notes"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No evidence items found for this standard.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
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
                        <td><?php echo safeText($task["id"]); ?></td>

                        <td>
                            <strong><?php echo safeText($task["task_title"]); ?></strong>
                        </td>

                        <td><?php echo safeText($task["substandard_code"]); ?></td>

                        <td><?php echo safeText($task["responsible_person"]); ?></td>

                        <td><?php echo safeText($task["due_date"]); ?></td>

                        <td><?php echo safeText($task["priority"]); ?></td>

                        <td><?php echo safeText($task["status"]); ?></td>

                        <td><?php echo showText($task["notes"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="missing">No improvement tasks found for this standard.</p>
        <?php endif; ?>
    </div>

</div>

</body>
</html>