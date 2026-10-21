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
// Count total users
// ===============================
$countStmt = $conn->query("
    SELECT COUNT(*)
    FROM users
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
// Load paginated users
// ===============================
$stmt = $conn->prepare("
    SELECT 
        id,
        full_name,
        email,
        role,
        status,
        created_at,
        updated_at
    FROM users
    ORDER BY id DESC
    LIMIT $recordsPerPage OFFSET $offset
");

$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Pagination URL
// ===============================
function buildPaginationUrl($pageNumber) {
    return "users.php?page=" . (int)$pageNumber;
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Users Management</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .top-bar {
            margin-bottom: 20px;
        }

        .role {
            font-weight: bold;
        }

        .active {
            color: #28a745;
            font-weight: bold;
        }

        .inactive {
            color: #dc3545;
            font-weight: bold;
        }

        .small-text {
            font-size: 12px;
            color: #555;
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
        <h1>Users Management</h1>
        <p>Manage system users, roles, account status, and access control.</p>
    </div>

    <div class="top-bar">
        <a href="create_user.php" class="btn">Add New User</a>
    </div>

    <div class="pagination-info">
        Page <?php echo htmlspecialchars($page); ?> of <?php echo htmlspecialchars($totalPages); ?>
        |
        <?php echo htmlspecialchars($recordsPerPage); ?> records per page
        |
        Total users: <?php echo htmlspecialchars($totalRecords); ?>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Created At</th>
            <th>Updated At</th>
            <th>Actions</th>
        </tr>

        <?php if (count($users) > 0): ?>

            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo htmlspecialchars($user["id"]); ?></td>

                    <td><?php echo htmlspecialchars($user["full_name"]); ?></td>

                    <td><?php echo htmlspecialchars($user["email"]); ?></td>

                    <td class="role"><?php echo htmlspecialchars($user["role"]); ?></td>

                    <td>
                        <?php if ($user["status"] === "Active"): ?>
                            <span class="active">Active</span>
                        <?php else: ?>
                            <span class="inactive"><?php echo htmlspecialchars($user["status"]); ?></span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <span class="small-text">
                            <?php echo htmlspecialchars($user["created_at"]); ?>
                        </span>
                    </td>

                    <td>
                        <span class="small-text">
                            <?php echo htmlspecialchars($user["updated_at"]); ?>
                        </span>
                    </td>

                    <td class="actions">
                        <a href="edit_user.php?id=<?php echo htmlspecialchars($user["id"]); ?>" class="btn btn-edit">
                            Edit
                        </a>

                        <?php if ((int)$user["id"] !== (int)$_SESSION["user_id"]): ?>
                            <?php echo deleteForm('delete_user.php?id=' . urlencode((string)$user['id']), 'Are you sure you want to delete this user?'); ?>
                        <?php else: ?>
                            <span class="small-text">Current user</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

        <?php else: ?>

            <tr>
                <td colspan="8">No users found.</td>
            </tr>

        <?php endif; ?>

    </table>

    <div class="pagination">
        <div class="pagination-info">
            Page <?php echo htmlspecialchars($page); ?> of <?php echo htmlspecialchars($totalPages); ?>
            |
            <?php echo htmlspecialchars($recordsPerPage); ?> records per page
            |
            Total users: <?php echo htmlspecialchars($totalRecords); ?>
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
