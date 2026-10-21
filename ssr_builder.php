<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// Pagination settings
// ===============================
$recordsPerPage = 10;

$page = isset($_GET["page"]) && is_numeric($_GET["page"]) ? (int)$_GET["page"] : 1;

if ($page < 1) {
    $page = 1;
}

$offset = ($page - 1) * $recordsPerPage;

// ===============================
// Count total standards
// ===============================
$countStmt = $conn->query("
    SELECT COUNT(*)
    FROM standards
");

$totalRecords = (int)$countStmt->fetchColumn();

$totalPages = (int)ceil($totalRecords / $recordsPerPage);

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
// Load paginated standards and SSR sections
// ===============================
$sql = "
    SELECT 
        s.id AS standard_id,
        s.standard_code,
        s.standard_title,
        s.description,

        ssr.id AS ssr_id,
        ssr.academic_year,
        ssr.report_cycle,
        ssr.section_title,
        ssr.status,
        ssr.prepared_by,
        ssr.reviewed_by,
        ssr.updated_at,

        COUNT(DISTINCT sub.id) AS substandard_count,
        COUNT(DISTINCT e.id) AS evidence_count

    FROM standards s
    LEFT JOIN ssr_sections ssr ON ssr.standard_id = s.id
    LEFT JOIN substandards sub ON sub.standard_id = s.id
    LEFT JOIN evidence_items e ON e.substandard_id = sub.id

    GROUP BY
        s.id,
        s.standard_code,
        s.standard_title,
        s.description,
        ssr.id,
        ssr.academic_year,
        ssr.report_cycle,
        ssr.section_title,
        ssr.status,
        ssr.prepared_by,
        ssr.reviewed_by,
        ssr.updated_at

    ORDER BY s.id

    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->query($sql);
$standards = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Summary counts for all standards, not only current page
// ===============================
$summarySql = "
    SELECT 
        COUNT(DISTINCT s.id) AS total_standards,
        COUNT(DISTINCT ssr.id) AS started_sections,
        SUM(CASE WHEN ssr.status = 'Approved' THEN 1 ELSE 0 END) AS approved_sections,
        COUNT(DISTINCT e.id) AS total_evidence

    FROM standards s
    LEFT JOIN ssr_sections ssr ON ssr.standard_id = s.id
    LEFT JOIN substandards sub ON sub.standard_id = s.id
    LEFT JOIN evidence_items e ON e.substandard_id = sub.id
";

$summaryStmt = $conn->query($summarySql);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$totalStandards = (int)($summary["total_standards"] ?? 0);
$startedSections = (int)($summary["started_sections"] ?? 0);
$approvedSections = (int)($summary["approved_sections"] ?? 0);
$totalEvidence = (int)($summary["total_evidence"] ?? 0);

// ===============================
// Pagination URL
// ===============================
function buildPaginationUrl($pageNumber) {
    return "ssr_builder.php?page=" . (int)$pageNumber;
}

// ===============================
// Helpers
// ===============================
function safeText($text) {
    return htmlspecialchars((string)($text ?? ""));
}

function statusBadge($status) {
    $status = $status ?: "Not Started";

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

    return '<span class="badge badge-danger">Not Started</span>';
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>SSR Builder</title>
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

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
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

        .small-text {
            font-size: 12px;
            color: #555;
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

        .badge-danger {
            background: #f8d7da;
            color: #842029;
        }

        .badge-muted {
            background: #e9ecef;
            color: #495057;
        }

        .actions a {
            display: inline-block;
            margin-bottom: 4px;
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

        @media (max-width: 1000px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(160px, 1fr));
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
        <h1>SSR Builder</h1>
        <p>Build self-study report sections by standard using evidence, substandards, tasks, KPIs, course reports, and semester reports.</p>
    </div>

    <div class="panel">
        <a href="export_full_ssr_word.php" class="btn btn-purple">
            Export Full SSR Word
        </a>
    </div>

    <div class="panel">
        <h2>SSR Progress Summary</h2>

        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-number"><?php echo safeText($totalStandards); ?></div>
                <div class="summary-label">Total Standards</div>
            </div>

            <div class="summary-card">
                <div class="summary-number"><?php echo safeText($startedSections); ?></div>
                <div class="summary-label">SSR Sections Started</div>
            </div>

            <div class="summary-card">
                <div class="summary-number"><?php echo safeText($approvedSections); ?></div>
                <div class="summary-label">Approved SSR Sections</div>
            </div>

            <div class="summary-card">
                <div class="summary-number"><?php echo safeText($totalEvidence); ?></div>
                <div class="summary-label">Evidence Items Across Standards</div>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>Standards and SSR Sections</h2>

        <div class="pagination-info">
            Page <?php echo safeText($page); ?> of <?php echo safeText($totalPages); ?>
            |
            <?php echo safeText($recordsPerPage); ?> records per page
            |
            Total standards: <?php echo safeText($totalRecords); ?>
        </div>

        <table>
            <tr>
                <th>Standard</th>
                <th>Description</th>
                <th>Substandards</th>
                <th>Evidence Items</th>
                <th>SSR Section</th>
                <th>Status</th>
                <th>Prepared / Reviewed</th>
                <th>Updated</th>
                <th>Actions</th>
            </tr>

            <?php if (count($standards) > 0): ?>

                <?php foreach ($standards as $row): ?>
                    <tr>
                        <td>
                            <strong><?php echo safeText($row["standard_code"]); ?></strong><br>
                            <?php echo safeText($row["standard_title"]); ?>
                        </td>

                        <td>
                            <span class="small-text">
                                <?php echo nl2br(safeText($row["description"])); ?>
                            </span>
                        </td>

                        <td>
                            <?php echo safeText($row["substandard_count"]); ?>
                        </td>

                        <td>
                            <?php echo safeText($row["evidence_count"]); ?>
                        </td>

                        <td>
                            <?php if (!empty($row["ssr_id"])): ?>
                                <strong><?php echo safeText($row["section_title"]); ?></strong><br>
                                <span class="small-text">
                                    Academic Year: <?php echo safeText($row["academic_year"]); ?><br>
                                    Cycle: <?php echo safeText($row["report_cycle"]); ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-danger">No SSR Section</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php echo statusBadge($row["status"] ?? null); ?>
                        </td>

                        <td>
                            <?php if (!empty($row["ssr_id"])): ?>
                                Prepared by: <?php echo safeText($row["prepared_by"]); ?><br>
                                Reviewed by: <?php echo safeText($row["reviewed_by"]); ?>
                            <?php else: ?>
                                <span class="small-text">Not available</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php echo safeText($row["updated_at"]); ?>
                        </td>

                        <td class="actions">
                            <a href="standard_evidence_report.php?standard_id=<?php echo safeText($row["standard_id"]); ?>" class="btn btn-purple">
                                Evidence Map
                            </a>

                            <?php if (!empty($row["ssr_id"])): ?>
                                <a href="edit_ssr_section.php?id=<?php echo safeText($row["ssr_id"]); ?>" class="btn btn-edit">
                                    Edit SSR
                                </a>

                                <a href="view_ssr_section.php?id=<?php echo safeText($row["ssr_id"]); ?>" class="btn">
                                    View
                                </a>

                                <?php if (hasRole(["Admin", "Quality Coordinator"])): ?>
                                    <?php echo deleteForm('delete_ssr_section.php?id=' . urlencode((string)$row['ssr_id']), 'Are you sure you want to delete this SSR section?'); ?>
                                <?php endif; ?>

                            <?php else: ?>

                                <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                                    <a href="create_ssr_section.php?standard_id=<?php echo safeText($row["standard_id"]); ?>" class="btn">
                                        Create SSR
                                    </a>
                                <?php endif; ?>

                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="9">No standards found.</td>
                </tr>

            <?php endif; ?>
        </table>

        <div class="pagination">
            <div class="pagination-info">
                Page <?php echo safeText($page); ?> of <?php echo safeText($totalPages); ?>
                |
                <?php echo safeText($recordsPerPage); ?> records per page
                |
                Total standards: <?php echo safeText($totalRecords); ?>
            </div>

            <?php if ($page > 1): ?>
                <a href="<?php echo htmlspecialchars(buildPaginationUrl(1)); ?>">First</a>
                <a href="<?php echo htmlspecialchars(buildPaginationUrl($page - 1)); ?>">Previous</a>
            <?php else: ?>
                <span class="disabled">First</span>
                <span class="disabled">Previous</span>
            <?php endif; ?>

            <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
            ?>

            <?php if ($startPage > 1): ?>
                <a href="<?php echo htmlspecialchars(buildPaginationUrl(1)); ?>">1</a>

                <?php if ($startPage > 2): ?>
                    <span class="disabled">...</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current-page"><?php echo safeText($i); ?></span>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars(buildPaginationUrl($i)); ?>">
                        <?php echo safeText($i); ?>
                    </a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                    <span class="disabled">...</span>
                <?php endif; ?>

                <a href="<?php echo htmlspecialchars(buildPaginationUrl($totalPages)); ?>">
                    <?php echo safeText($totalPages); ?>
                </a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?php echo htmlspecialchars(buildPaginationUrl($page + 1)); ?>">Next</a>
                <a href="<?php echo htmlspecialchars(buildPaginationUrl($totalPages)); ?>">Last</a>
            <?php else: ?>
                <span class="disabled">Next</span>
                <span class="disabled">Last</span>
            <?php endif; ?>
        </div>

    </div>

</div>

</body>
</html>
