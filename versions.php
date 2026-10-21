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
// Count total versions
// ===============================
$countStmt = $conn->query("
    SELECT COUNT(*)
    FROM system_versions
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
// Load paginated versions
// ===============================
$sql = "
    SELECT *
    FROM system_versions
    ORDER BY version_date DESC, id DESC
    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->query($sql);
$versions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Pagination URL
// ===============================
function buildPaginationUrl($pageNumber) {
    return "versions.php?page=" . (int)$pageNumber;
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>System Versions</title>
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

        .version-name {
            font-weight: bold;
            color: #222;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffe69c;
            color: #664d03;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
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
        <h1>System Versions</h1>
        <p>Track stable system versions, backup files, folder copies, and major development milestones.</p>
    </div>

    <div class="panel">
        <h2>Version Control Notes</h2>

        <div class="warning-box">
            Before creating a new version record, run <strong>backup_database.bat</strong> and copy the full
            <strong>C:\quality_system</strong> folder to a versioned folder.
        </div>

        <a href="create_version.php" class="btn">Add New Version</a>
        <a href="maintenance.php" class="btn btn-secondary">Maintenance</a>
    </div>

    <div class="panel">
        <h2>Saved Versions</h2>

        <div class="pagination-info">
            Page <?php echo htmlspecialchars($page); ?> of <?php echo htmlspecialchars($totalPages); ?>
            |
            <?php echo htmlspecialchars($recordsPerPage); ?> records per page
            |
            Total versions: <?php echo htmlspecialchars($totalRecords); ?>
        </div>

        <table>
            <tr>
                <th>ID</th>
                <th>Version</th>
                <th>Date</th>
                <th>Description</th>
                <th>Backup File</th>
                <th>Folder Copy</th>
                <th>Notes</th>
                <th>Created By</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>

            <?php if (count($versions) > 0): ?>

                <?php foreach ($versions as $version): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($version["id"]); ?></td>

                        <td>
                            <span class="version-name">
                                <?php echo htmlspecialchars($version["version_name"]); ?>
                            </span>
                        </td>

                        <td><?php echo htmlspecialchars($version["version_date"]); ?></td>

                        <td><?php echo nl2br(htmlspecialchars($version["description"] ?? "")); ?></td>

                        <td>
                            <span class="small-text">
                                <?php echo htmlspecialchars($version["backup_file_name"] ?? ""); ?>
                            </span>
                        </td>

                        <td>
                            <span class="small-text">
                                <?php echo htmlspecialchars($version["folder_copy_name"] ?? ""); ?>
                            </span>
                        </td>

                        <td><?php echo nl2br(htmlspecialchars($version["notes"] ?? "")); ?></td>

                        <td><?php echo htmlspecialchars($version["created_by"] ?? ""); ?></td>

                        <td>
                            <span class="small-text">
                                <?php echo htmlspecialchars($version["created_at"]); ?>
                            </span>
                        </td>

                        <td class="actions">
                            <a href="edit_version.php?id=<?php echo htmlspecialchars($version["id"]); ?>" class="btn btn-edit">
                                Edit
                            </a>

                            <?php echo deleteForm('delete_version.php?id=' . urlencode((string)$version['id']), 'Are you sure you want to delete this version record? This will not delete the actual backup file or folder copy.'); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="10">No system versions recorded yet.</td>
                </tr>

            <?php endif; ?>

        </table>

        <div class="pagination">
            <div class="pagination-info">
                Page <?php echo htmlspecialchars($page); ?> of <?php echo htmlspecialchars($totalPages); ?>
                |
                <?php echo htmlspecialchars($recordsPerPage); ?> records per page
                |
                Total versions: <?php echo htmlspecialchars($totalRecords); ?>
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

</div>

</body>
</html>
