<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// Selected standard
// ===============================
$selected_standard_id = $_GET["standard_id"] ?? "";

// ===============================
// Pagination settings for substandards
// ===============================
$recordsPerPage = 10;

$page = isset($_GET["page"]) && is_numeric($_GET["page"]) ? (int)$_GET["page"] : 1;

if ($page < 1) {
    $page = 1;
}

$offset = ($page - 1) * $recordsPerPage;

// ===============================
// Load standards
// ===============================
$standardsStmt = $conn->query("
    SELECT id, standard_code, standard_title, description
    FROM standards
    ORDER BY id
");
$standards = $standardsStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedStandard = null;
$substandards = [];
$totalSubstandards = 0;
$totalPages = 1;

if ($selected_standard_id !== "" && is_numeric($selected_standard_id)) {

    // ===============================
    // Load selected standard
    // ===============================
    $stmt = $conn->prepare("
        SELECT *
        FROM standards
        WHERE id = :id
    ");
    $stmt->execute([":id" => $selected_standard_id]);
    $selectedStandard = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedStandard) {

        // ===============================
        // Count substandards under selected standard
        // ===============================
        $countStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM substandards
            WHERE standard_id = :standard_id
        ");
        $countStmt->execute([":standard_id" => $selected_standard_id]);
        $totalSubstandards = (int)$countStmt->fetchColumn();

        $totalPages = (int)ceil($totalSubstandards / $recordsPerPage);

        if ($totalPages < 1) {
            $totalPages = 1;
        }

        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $recordsPerPage;
        }

        // Safety
        $recordsPerPage = (int)$recordsPerPage;
        $offset = (int)$offset;

        // ===============================
        // Load paginated substandards
        // ===============================
        $subStmt = $conn->prepare("
            SELECT *
            FROM substandards
            WHERE standard_id = :standard_id
            ORDER BY substandard_code
            LIMIT $recordsPerPage OFFSET $offset
        ");
        $subStmt->execute([":standard_id" => $selected_standard_id]);
        $substandards = $subStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ===============================
// Evidence for substandard
// ===============================
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

// ===============================
// Tasks for substandard
// ===============================
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

// ===============================
// Full standard summary counts
// ===============================
function getStandardSummaryCounts($conn, $standard_id) {
    $summary = [
        "total_substandards" => 0,
        "covered_substandards" => 0,
        "total_evidence_items" => 0,
        "total_tasks" => 0
    ];

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM substandards
        WHERE standard_id = :standard_id
    ");
    $stmt->execute([":standard_id" => $standard_id]);
    $summary["total_substandards"] = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT ss.id)
        FROM substandards ss
        INNER JOIN evidence_items e ON e.substandard_id = ss.id
        WHERE ss.standard_id = :standard_id
    ");
    $stmt->execute([":standard_id" => $standard_id]);
    $summary["covered_substandards"] = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare("
        SELECT COUNT(e.id)
        FROM evidence_items e
        INNER JOIN substandards ss ON e.substandard_id = ss.id
        WHERE ss.standard_id = :standard_id
    ");
    $stmt->execute([":standard_id" => $standard_id]);
    $summary["total_evidence_items"] = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare("
        SELECT COUNT(t.id)
        FROM tasks t
        INNER JOIN substandards ss ON t.substandard_id = ss.id
        WHERE ss.standard_id = :standard_id
    ");
    $stmt->execute([":standard_id" => $standard_id]);
    $summary["total_tasks"] = (int)$stmt->fetchColumn();

    return $summary;
}

// ===============================
// Helpers
// ===============================
function coverageStatus($evidenceCount) {
    if ($evidenceCount > 0) {
        return '<span class="badge badge-success">Covered</span>';
    }

    return '<span class="badge badge-danger">Not Covered</span>';
}

function safeText($text) {
    return htmlspecialchars((string)($text ?? ""));
}

function showText($text) {
    if ($text === null || trim((string)$text) === "") {
        return '<span class="missing">Not provided</span>';
    }

    return nl2br(htmlspecialchars((string)$text));
}

function buildPaginationUrl($standardId, $pageNumber) {
    return "standard_evidence_report.php?standard_id=" . urlencode((string)$standardId) . "&page=" . (int)$pageNumber;
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Standard Evidence Report</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .page-subtitle {
            color: #666;
            margin-bottom: 18px;
        }

        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        select {
            width: 600px;
            max-width: 100%;
            padding: 8px;
            box-sizing: border-box;
        }

        .substandard-box {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 18px;
            background: #fff;
        }

        .substandard-header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }

        .substandard-code {
            font-weight: bold;
            font-size: 16px;
            color: #222;
        }

        .substandard-text {
            color: #444;
            margin-top: 5px;
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

        .badge-danger {
            background: #f8d7da;
            color: #842029;
        }

        .badge-warning {
            background: #fff3cd;
            color: #664d03;
        }

        .badge-muted {
            background: #e9ecef;
            color: #495057;
        }

        .missing {
            color: #999;
            font-style: italic;
        }

        .summary-cards {
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

        .print-note {
            font-size: 12px;
            color: #666;
            margin-top: 8px;
        }

        .pagination {
            margin-top: 18px;
            margin-bottom: 20px;
            background: white;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .pagination a,
        .pagination span {
            display: inline-block;
            padding: 7px 10px;
            margin-right: 4px;
            margin-bottom: 4px;
            border-radius: 4px;
            text-decoration: none;
            border: 1px solid #ccc;
            color: #007bff;
            background: #fff;
            font-size: 13px;
        }

        .pagination a:hover {
            background: #f0f0f0;
        }

        .pagination .current-page {
            background: #007bff;
            color: white;
            border-color: #007bff;
            font-weight: bold;
        }

        .pagination .disabled {
            color: #999;
            background: #f2f2f2;
        }

        .pagination-info {
            margin-bottom: 8px;
            font-size: 13px;
            color: #555;
        }

        @media print {
            .top-bar,
            .filter-panel,
            .actions,
            .pagination {
                display: none;
            }

            body {
                background: white;
                margin: 18px;
            }

            .panel,
            .substandard-box,
            .summary-card {
                border: 1px solid #999;
                box-shadow: none;
            }

            table {
                font-size: 11px;
            }
        }

        @media (max-width: 900px) {
            .summary-cards {
                grid-template-columns: repeat(2, minmax(160px, 1fr));
            }

            .substandard-header {
                flex-direction: column;
            }
        }

        @media (max-width: 600px) {
            .summary-cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Standard Evidence Report</h1>
        <p>Accreditation evidence map by standard, substandard, uploaded evidence, and related improvement tasks.</p>
    </div>

    <div class="panel filter-panel">
        <h2>Select Standard</h2>

        <form method="GET" action="standard_evidence_report.php">
            <label>Standard</label>
            <select name="standard_id" required>
                <option value="">-- Select Standard --</option>

                <?php foreach ($standards as $standard): ?>
                    <option value="<?php echo safeText($standard["id"]); ?>"
                        <?php if ((string)$selected_standard_id === (string)$standard["id"]) echo "selected"; ?>>
                        <?php echo safeText($standard["standard_code"] . " - " . $standard["standard_title"]); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <br><br>

            <button type="submit" class="btn">Generate Report</button>
            <a href="standard_evidence_report.php" class="btn btn-secondary">Clear</a>

            <?php if ($selectedStandard): ?>
                <button type="button" onclick="window.print();" class="btn btn-purple">
                    Print / Save as PDF
                </button>

                <a href="export_standard_evidence_word.php?standard_id=<?php echo htmlspecialchars($selected_standard_id); ?>" 
                   class="btn">
                    Export Word
                </a>
            <?php endif; ?>
        </form>

        <div class="print-note">
            Tip: after generating a report, use Print / Save as PDF for committee documentation.
        </div>
    </div>

    <?php if ($selectedStandard): ?>

        <?php
            $summary = getStandardSummaryCounts($conn, $selected_standard_id);

            $totalSubstandardsAll = $summary["total_substandards"];
            $coveredSubstandards = $summary["covered_substandards"];
            $totalEvidenceItems = $summary["total_evidence_items"];
            $totalTasks = $summary["total_tasks"];

            $coverageRate = $totalSubstandardsAll > 0
                ? round(($coveredSubstandards / $totalSubstandardsAll) * 100, 1)
                : 0;

            $substandardData = [];

            foreach ($substandards as $sub) {
                $evidenceList = getEvidenceForSubstandard($conn, $sub["id"]);
                $taskList = getTasksForSubstandard($conn, $sub["id"]);

                $substandardData[] = [
                    "substandard" => $sub,
                    "evidence" => $evidenceList,
                    "tasks" => $taskList
                ];
            }
        ?>

        <div class="panel">
            <h2>
                <?php echo safeText($selectedStandard["standard_code"]); ?>
                -
                <?php echo safeText($selectedStandard["standard_title"]); ?>
            </h2>

            <p>
                <?php echo showText($selectedStandard["description"] ?? ""); ?>
            </p>

            <div class="summary-cards">
                <div class="summary-card">
                    <div class="summary-number"><?php echo safeText($totalSubstandardsAll); ?></div>
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
                    <div class="summary-number"><?php echo safeText($totalEvidenceItems); ?></div>
                    <div class="summary-label">Evidence Items</div>
                </div>
            </div>

            <div class="summary-cards">
                <div class="summary-card">
                    <div class="summary-number"><?php echo safeText($totalTasks); ?></div>
                    <div class="summary-label">Related Tasks</div>
                </div>
            </div>
        </div>

        <div class="panel">
            <h2>Substandard Coverage Details</h2>

            <div class="pagination-info">
                Page <?php echo safeText($page); ?> of <?php echo safeText($totalPages); ?>
                |
                <?php echo safeText($recordsPerPage); ?> substandards per page
                |
                Total substandards: <?php echo safeText($totalSubstandardsAll); ?>
            </div>

            <?php if (count($substandardData) > 0): ?>

                <?php foreach ($substandardData as $data): ?>
                    <?php
                        $sub = $data["substandard"];
                        $evidenceList = $data["evidence"];
                        $taskList = $data["tasks"];
                    ?>

                    <div class="substandard-box">
                        <div class="substandard-header">
                            <div>
                                <div class="substandard-code">
                                    <?php echo safeText($sub["substandard_code"]); ?>
                                </div>

                                <div class="substandard-text">
                                    <?php echo showText($sub["substandard_text"]); ?>
                                </div>
                            </div>

                            <div>
                                <?php echo coverageStatus(count($evidenceList)); ?>
                            </div>
                        </div>

                        <h3>Evidence Items</h3>

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
                                        <td><?php echo safeText($evidence["id"]); ?></td>

                                        <td>
                                            <strong><?php echo safeText($evidence["title"]); ?></strong>
                                        </td>

                                        <td><?php echo safeText($evidence["evidence_type"]); ?></td>

                                        <td><?php echo safeText($evidence["responsible_person"]); ?></td>

                                        <td>
                                            <span class="badge badge-muted">
                                                <?php echo safeText($evidence["status"]); ?>
                                            </span>
                                        </td>

                                        <td><?php echo safeText($evidence["academic_year"]); ?></td>

                                        <td><?php echo safeText($evidence["semester"]); ?></td>

                                        <td><?php echo safeText($evidence["review_status"]); ?></td>

                                        <td><?php echo safeText($evidence["file_count"]); ?> file(s)</td>

                                        <td><?php echo showText($evidence["notes"]); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php else: ?>
                            <p class="missing">No evidence linked to this substandard.</p>
                        <?php endif; ?>

                        <h3>Related Tasks</h3>

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
                                        <td><?php echo safeText($task["id"]); ?></td>

                                        <td>
                                            <strong><?php echo safeText($task["task_title"]); ?></strong>
                                        </td>

                                        <td><?php echo safeText($task["responsible_person"]); ?></td>

                                        <td><?php echo safeText($task["due_date"]); ?></td>

                                        <td>
                                            <span class="badge badge-warning">
                                                <?php echo safeText($task["priority"]); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="badge badge-muted">
                                                <?php echo safeText($task["status"]); ?>
                                            </span>
                                        </td>

                                        <td><?php echo showText($task["notes"]); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php else: ?>
                            <p class="missing">No tasks linked to this substandard.</p>
                        <?php endif; ?>
                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <p class="missing">No substandards found under this standard.</p>

            <?php endif; ?>

            <div class="pagination">
                <div class="pagination-info">
                    Page <?php echo safeText($page); ?> of <?php echo safeText($totalPages); ?>
                    |
                    <?php echo safeText($recordsPerPage); ?> substandards per page
                    |
                    Total substandards: <?php echo safeText($totalSubstandardsAll); ?>
                </div>

                <?php if ($page > 1): ?>
                    <a href="<?php echo htmlspecialchars(buildPaginationUrl($selected_standard_id, 1)); ?>">First</a>
                    <a href="<?php echo htmlspecialchars(buildPaginationUrl($selected_standard_id, $page - 1)); ?>">Previous</a>
                <?php else: ?>
                    <span class="disabled">First</span>
                    <span class="disabled">Previous</span>
                <?php endif; ?>

                <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                ?>

                <?php if ($startPage > 1): ?>
                    <a href="<?php echo htmlspecialchars(buildPaginationUrl($selected_standard_id, 1)); ?>">1</a>

                    <?php if ($startPage > 2): ?>
                        <span class="disabled">...</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current-page"><?php echo safeText($i); ?></span>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars(buildPaginationUrl($selected_standard_id, $i)); ?>">
                            <?php echo safeText($i); ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <span class="disabled">...</span>
                    <?php endif; ?>

                    <a href="<?php echo htmlspecialchars(buildPaginationUrl($selected_standard_id, $totalPages)); ?>">
                        <?php echo safeText($totalPages); ?>
                    </a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?php echo htmlspecialchars(buildPaginationUrl($selected_standard_id, $page + 1)); ?>">Next</a>
                    <a href="<?php echo htmlspecialchars(buildPaginationUrl($selected_standard_id, $totalPages)); ?>">Last</a>
                <?php else: ?>
                    <span class="disabled">Next</span>
                    <span class="disabled">Last</span>
                <?php endif; ?>
            </div>

        </div>

    <?php else: ?>

        <div class="panel">
            <p class="missing">
                Select a standard to generate the evidence report.
            </p>
        </div>

    <?php endif; ?>

</div>

</body>
</html>