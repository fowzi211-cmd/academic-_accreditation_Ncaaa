<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// Helper functions
// ===============================
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

function buildPaginationUrl($pageNumber) {
    $queryParams = $_GET;
    $queryParams["page"] = $pageNumber;
    return "committees.php?" . http_build_query($queryParams);
}

// ===============================
// Required table check
// ===============================
if (!tableExists($conn, "committees")) {
    die("committees table does not exist. Run the committee SQL table creation first.");
}

// ===============================
// Filters
// ===============================
$filter_search = trim($_GET["search"] ?? "");
$filter_academic_year = trim($_GET["academic_year"] ?? "");
$filter_type = trim($_GET["committee_type"] ?? "");
$filter_status = trim($_GET["status"] ?? "");

// ===============================
// Pagination
// ===============================
$recordsPerPage = 10;
$currentPage = isset($_GET["page"]) && is_numeric($_GET["page"]) ? (int)$_GET["page"] : 1;

if ($currentPage < 1) {
    $currentPage = 1;
}

$offset = ($currentPage - 1) * $recordsPerPage;

// ===============================
// Build WHERE
// ===============================
$whereSql = " WHERE 1 = 1 ";
$params = [];

if ($filter_search !== "") {
    $whereSql .= " AND (
        c.committee_name LIKE :search
        OR c.committee_type LIKE :search
        OR c.formation_reference LIKE :search
        OR c.mandate LIKE :search
        OR c.notes LIKE :search
        OR chair.full_name LIKE :search
        OR secretary.full_name LIKE :search
    )";
    $params[":search"] = "%" . $filter_search . "%";
}

if ($filter_academic_year !== "") {
    $whereSql .= " AND c.academic_year LIKE :academic_year";
    $params[":academic_year"] = "%" . $filter_academic_year . "%";
}

if ($filter_type !== "") {
    $whereSql .= " AND c.committee_type = :committee_type";
    $params[":committee_type"] = $filter_type;
}

if ($filter_status !== "") {
    $whereSql .= " AND c.status = :status";
    $params[":status"] = $filter_status;
}

// ===============================
// Base FROM
// ===============================
$fromSql = "
    FROM committees c
    LEFT JOIN program_staff chair ON c.chair_staff_id = chair.id
    LEFT JOIN program_staff secretary ON c.secretary_staff_id = secretary.id
";

// ===============================
// Count records
// ===============================
$countSql = "
    SELECT COUNT(*)
    $fromSql
    $whereSql
";

$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$totalResults = (int)$countStmt->fetchColumn();

$totalPages = (int)ceil($totalResults / $recordsPerPage);

if ($totalPages < 1) {
    $totalPages = 1;
}

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
    $offset = ($currentPage - 1) * $recordsPerPage;
}

$recordsPerPage = (int)$recordsPerPage;
$offset = (int)$offset;

// ===============================
// Load committees
// ===============================
$sql = "
    SELECT
        c.id,
        c.committee_name,
        c.committee_type,
        c.academic_year,
        c.formation_reference,
        c.formation_date,
        c.mandate,
        c.status,
        c.notes,
        c.created_at,
        c.updated_at,

        chair.full_name AS chair_name,
        secretary.full_name AS secretary_name,

        (
            SELECT COUNT(*)
            FROM committee_members cm
            WHERE cm.committee_id = c.id
        ) AS member_count,

        (
            SELECT COUNT(*)
            FROM committee_meetings mt
            WHERE mt.committee_id = c.id
        ) AS meeting_count,

        (
            SELECT COUNT(*)
            FROM committee_recommendations cr
            WHERE cr.committee_id = c.id
        ) AS recommendation_count,

        (
            SELECT COUNT(*)
            FROM committee_tasks ct
            WHERE ct.committee_id = c.id
        ) AS task_count

    $fromSql
    $whereSql

    ORDER BY
        CASE
            WHEN c.status = 'Active' THEN 1
            WHEN c.status = 'Draft' THEN 2
            WHEN c.status = 'Inactive' THEN 3
            ELSE 4
        END,
        c.academic_year DESC,
        c.committee_name ASC

    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$committees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Summary counts
// ===============================
$summaryStmt = $conn->query("
    SELECT
        COUNT(*) AS total_committees,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) AS active_committees,
        SUM(CASE WHEN status = 'Draft' THEN 1 ELSE 0 END) AS draft_committees,
        SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) AS inactive_committees
    FROM committees
");

$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$totalCommittees = (int)($summary["total_committees"] ?? 0);
$activeCommittees = (int)($summary["active_committees"] ?? 0);
$draftCommittees = (int)($summary["draft_committees"] ?? 0);
$inactiveCommittees = (int)($summary["inactive_committees"] ?? 0);

// ===============================
// Filter options
// ===============================
$typeOptions = [];
$typeStmt = $conn->query("
    SELECT DISTINCT committee_type
    FROM committees
    WHERE committee_type IS NOT NULL AND committee_type <> ''
    ORDER BY committee_type ASC
");
$typeOptions = $typeStmt->fetchAll(PDO::FETCH_COLUMN);

$yearOptions = [];
$yearStmt = $conn->query("
    SELECT DISTINCT academic_year
    FROM committees
    WHERE academic_year IS NOT NULL AND academic_year <> ''
    ORDER BY academic_year DESC
");
$yearOptions = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

$statusOptions = ["Active", "Draft", "Inactive", "Archived"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Committees</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .summary-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
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

        .filter-box {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 22px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 12px;
        }

        .filter-actions {
            margin-top: 15px;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-active {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-draft {
            background: #fff3cd;
            color: #664d03;
        }

        .badge-inactive {
            background: #f8d7da;
            color: #842029;
        }

        .badge-muted {
            background: #e9ecef;
            color: #495057;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .missing {
            color: #999;
            font-style: italic;
        }

        .actions a {
            display: block;
            width: 120px;
            text-align: center;
            margin-bottom: 5px;
        }

        .metric-line {
            line-height: 1.7;
        }

        .pagination {
            margin-top: 20px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #ccc;
            background: #fff;
            text-decoration: none;
            color: #333;
            border-radius: 4px;
            font-size: 14px;
        }

        .pagination .active-page {
            background: #007bff;
            color: white;
            border-color: #007bff;
            font-weight: bold;
        }

        .pagination .disabled-page {
            color: #aaa;
            background: #f5f5f5;
        }

        .page-summary {
            margin-top: 10px;
            margin-bottom: 10px;
            color: #555;
            font-size: 14px;
        }

        @media (max-width: 1100px) {
            .summary-grid,
            .filter-grid {
                grid-template-columns: repeat(2, minmax(160px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .summary-grid,
            .filter-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Committees</h1>
        <p>Manage program committees, formation details, members, meetings, recommendations, and assigned tasks.</p>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalCommittees); ?></div>
            <div class="summary-label">Total Committees</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($activeCommittees); ?></div>
            <div class="summary-label">Active Committees</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($draftCommittees); ?></div>
            <div class="summary-label">Draft Committees</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($inactiveCommittees); ?></div>
            <div class="summary-label">Inactive Committees</div>
        </div>
    </div>

    <div class="filter-box">
        <h2>Filter Committees</h2>

        <form method="GET" action="committees.php">
            <div class="filter-grid">
                <div>
                    <label>Search</label>
                    <input type="text"
                           name="search"
                           value="<?php echo safeText($filter_search); ?>"
                           placeholder="Committee name, chair, mandate">
                </div>

                <div>
                    <label>Academic Year</label>
                    <select name="academic_year">
                        <option value="">All Years</option>
                        <?php foreach ($yearOptions as $year): ?>
                            <option value="<?php echo safeText($year); ?>" <?php if ($filter_academic_year === $year) echo "selected"; ?>>
                                <?php echo safeText($year); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Committee Type</label>
                    <select name="committee_type">
                        <option value="">All Types</option>
                        <?php foreach ($typeOptions as $type): ?>
                            <option value="<?php echo safeText($type); ?>" <?php if ($filter_type === $type) echo "selected"; ?>>
                                <?php echo safeText($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?php echo safeText($status); ?>" <?php if ($filter_status === $status) echo "selected"; ?>>
                                <?php echo safeText($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn">Apply Filters</button>

                <a href="committees.php" class="btn btn-secondary">
                    Clear Filters
                </a>

                <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                    <a href="create_committee.php" class="btn">
                        Add Committee
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="page-summary">
        Page <?php echo safeText($currentPage); ?> of <?php echo safeText($totalPages); ?>
        |
        <?php echo safeText($recordsPerPage); ?> records per page
        |
        Total filtered records: <?php echo safeText($totalResults); ?>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>Committee</th>
            <th>Chair / Secretary</th>
            <th>Formation</th>
            <th>Governance Activity</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>

        <?php if (count($committees) > 0): ?>
            <?php foreach ($committees as $committee): ?>
                <?php
                    $statusClass = "badge-muted";

                    if (($committee["status"] ?? "") === "Active") {
                        $statusClass = "badge-active";
                    } elseif (($committee["status"] ?? "") === "Draft") {
                        $statusClass = "badge-draft";
                    } elseif (in_array(($committee["status"] ?? ""), ["Inactive", "Archived"], true)) {
                        $statusClass = "badge-inactive";
                    }
                ?>
                <tr>
                    <td><?php echo safeText($committee["id"]); ?></td>

                    <td>
                        <strong><?php echo safeText($committee["committee_name"]); ?></strong>

                        <?php if (!empty($committee["committee_type"])): ?>
                            <br>
                            <span class="small-text">
                                Type: <?php echo safeText($committee["committee_type"]); ?>
                            </span>
                        <?php endif; ?>

                        <?php if (!empty($committee["academic_year"])): ?>
                            <br>
                            <span class="small-text">
                                Academic Year: <?php echo safeText($committee["academic_year"]); ?>
                            </span>
                        <?php endif; ?>

                        <?php if (!empty($committee["mandate"])): ?>
                            <br>
                            <span class="small-text">
                                <?php echo safeText(mb_substr($committee["mandate"], 0, 160)); ?>
                                <?php if (mb_strlen($committee["mandate"]) > 160) echo "..."; ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <strong>Chair:</strong>
                        <?php if (!empty($committee["chair_name"])): ?>
                            <?php echo safeText($committee["chair_name"]); ?>
                        <?php else: ?>
                            <span class="missing">Not assigned</span>
                        <?php endif; ?>

                        <br>

                        <strong>Secretary:</strong>
                        <?php if (!empty($committee["secretary_name"])): ?>
                            <?php echo safeText($committee["secretary_name"]); ?>
                        <?php else: ?>
                            <span class="missing">Not assigned</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($committee["formation_reference"])): ?>
                            <strong>Ref:</strong>
                            <?php echo safeText($committee["formation_reference"]); ?>
                            <br>
                        <?php endif; ?>

                        <?php if (!empty($committee["formation_date"])): ?>
                            <strong>Date:</strong>
                            <?php echo safeText($committee["formation_date"]); ?>
                        <?php else: ?>
                            <span class="missing">No formation date</span>
                        <?php endif; ?>
                    </td>

                    <td class="metric-line">
                        Members: <strong><?php echo safeText($committee["member_count"]); ?></strong><br>
                        Meetings: <strong><?php echo safeText($committee["meeting_count"]); ?></strong><br>
                        Recommendations: <strong><?php echo safeText($committee["recommendation_count"]); ?></strong><br>
                        Tasks: <strong><?php echo safeText($committee["task_count"]); ?></strong>
                    </td>

                    <td>
                        <span class="badge <?php echo safeText($statusClass); ?>">
                            <?php echo safeText($committee["status"]); ?>
                        </span>
                    </td>

                    <td class="actions">
                        <a href="committee_profile.php?id=<?php echo safeText($committee["id"]); ?>" class="btn">
                            Profile
                        </a>

                        <a href="manage_committee_members.php?id=<?php echo safeText($committee["id"]); ?>" class="btn btn-purple">
                            Members
                        </a>

                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                            <a href="edit_committee.php?id=<?php echo safeText($committee["id"]); ?>" class="btn btn-edit">
                                Edit
                            </a>
                        <?php endif; ?>

                        <?php if (hasRole(["Admin", "Quality Coordinator"])): ?>
                            <?php echo deleteForm('delete_committee.php?id=' . urlencode((string)$committee['id']), 'Are you sure you want to delete this committee?'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="7">No committees found.</td>
            </tr>
        <?php endif; ?>
    </table>

    <div class="pagination">
        <?php if ($currentPage > 1): ?>
            <a href="<?php echo safeText(buildPaginationUrl(1)); ?>">First</a>
            <a href="<?php echo safeText(buildPaginationUrl($currentPage - 1)); ?>">&laquo; Previous</a>
        <?php else: ?>
            <span class="disabled-page">First</span>
            <span class="disabled-page">&laquo; Previous</span>
        <?php endif; ?>

        <?php
        $startPage = max(1, $currentPage - 2);
        $endPage = min($totalPages, $currentPage + 2);
        ?>

        <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
            <?php if ($pageNumber === $currentPage): ?>
                <span class="active-page"><?php echo safeText($pageNumber); ?></span>
            <?php else: ?>
                <a href="<?php echo safeText(buildPaginationUrl($pageNumber)); ?>">
                    <?php echo safeText($pageNumber); ?>
                </a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($currentPage < $totalPages): ?>
            <a href="<?php echo safeText(buildPaginationUrl($currentPage + 1)); ?>">Next &raquo;</a>
            <a href="<?php echo safeText(buildPaginationUrl($totalPages)); ?>">Last</a>
        <?php else: ?>
            <span class="disabled-page">Next &raquo;</span>
            <span class="disabled-page">Last</span>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
