<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole("Admin");

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
// Count total substandards
// ===============================
$countStmt = $conn->query("
    SELECT COUNT(*)
    FROM substandards
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
// Load paginated substandards
// ===============================
$sql = "
    SELECT 
        ss.id,
        ss.substandard_code,
        ss.substandard_text,
        ss.created_at,

        s.standard_code,
        s.standard_title,

        COUNT(e.id) AS evidence_count

    FROM substandards ss
    INNER JOIN standards s ON ss.standard_id = s.id
    LEFT JOIN evidence_items e ON e.substandard_id = ss.id

    GROUP BY 
        ss.id,
        ss.substandard_code,
        ss.substandard_text,
        ss.created_at,
        s.standard_code,
        s.standard_title,
        s.id

    ORDER BY s.id, ss.substandard_code

    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->query($sql);
$substandards = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Pagination URL
// ===============================
function buildPaginationUrl($pageNumber) {
    return "substandards.php?page=" . (int)$pageNumber;
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Substandards Management</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .top-bar {
            margin-bottom: 20px;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            background-color: #e9ecef;
            border-radius: 4px;
            font-weight: bold;
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
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Substandards Management</h1>
        <p>Manage accreditation substandards, requirement text, and linked evidence counts.</p>
    </div>

    <div class="pagination-info">
        Page <?php echo htmlspecialchars($page); ?> of <?php echo htmlspecialchars($totalPages); ?>
        |
        <?php echo htmlspecialchars($recordsPerPage); ?> records per page
        |
        Total substandards: <?php echo htmlspecialchars($totalRecords); ?>
    </div>

<div class="top-bar">
    <a href="create_substandard.php" class="btn">Add Substandard</a>
    <a href="standards.php" class="btn btn-purple">Standards</a>
</div>

    <table>
        <tr>
            <th>ID</th>
            <th>Standard</th>
            <th>Substandard Code</th>
            <th>Requirement Text</th>
            <th>Evidence Count</th>
            <th>Created At</th>
            <th>Actions</th>
        </tr>

        <?php if (count($substandards) > 0): ?>

            <?php foreach ($substandards as $sub): ?>
                <tr>
                    <td><?php echo htmlspecialchars($sub["id"]); ?></td>

                    <td>
                        <strong><?php echo htmlspecialchars($sub["standard_code"]); ?></strong><br>
                        <span class="small-text">
                            <?php echo htmlspecialchars($sub["standard_title"]); ?>
                        </span>
                    </td>

                    <td>
                        <span class="badge">
                            <?php echo htmlspecialchars($sub["substandard_code"]); ?>
                        </span>
                    </td>

                    <td>
                        <?php echo nl2br(htmlspecialchars($sub["substandard_text"])); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars($sub["evidence_count"]); ?>
                    </td>

                    <td>
                        <span class="small-text">
                            <?php echo htmlspecialchars($sub["created_at"]); ?>
                        </span>
                    </td>

                    <td class="actions">
                        <a href="edit_substandard.php?id=<?php echo htmlspecialchars($sub["id"]); ?>" class="btn btn-edit">
                            Edit
                        </a>

                        <?php echo deleteForm('delete_substandard.php?id=' . urlencode((string)$sub['id']), 'Are you sure you want to delete this substandard?'); ?>
                    </td>
                </tr>
            <?php endforeach; ?>

        <?php else: ?>

            <tr>
                <td colspan="7">No substandards found.</td>
            </tr>

        <?php endif; ?>

    </table>

    <div class="pagination">
        <div class="pagination-info">
            Page <?php echo htmlspecialchars($page); ?> of <?php echo htmlspecialchars($totalPages); ?>
            |
            <?php echo htmlspecialchars($recordsPerPage); ?> records per page
            |
            Total substandards: <?php echo htmlspecialchars($totalRecords); ?>
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
                <span class="current-page"><?php echo htmlspecialchars($i); ?></span>
            <?php else: ?>
                <a href="<?php echo htmlspecialchars(buildPaginationUrl($i)); ?>">
                    <?php echo htmlspecialchars($i); ?>
                </a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?>
                <span class="disabled">...</span>
            <?php endif; ?>

            <a href="<?php echo htmlspecialchars(buildPaginationUrl($totalPages)); ?>">
                <?php echo htmlspecialchars($totalPages); ?>
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

</body>
</html>
