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
    return "program_staff.php?" . http_build_query($queryParams);
}

// ===============================
// Check table
// ===============================
if (!tableExists($conn, "program_staff")) {
    die("program_staff table does not exist. Run the Program Staff SQL table creation first.");
}

// ===============================
// Filters
// ===============================
$filter_search = trim($_GET["search"] ?? "");
$filter_status = trim($_GET["employment_status"] ?? "");
$filter_rank = trim($_GET["academic_rank"] ?? "");
$filter_department = trim($_GET["department"] ?? "");
$filter_role = trim($_GET["role_in_program"] ?? "");

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
        ps.full_name LIKE :search
        OR ps.staff_code LIKE :search
        OR ps.email LIKE :search
        OR ps.specialty LIKE :search
        OR ps.qualification LIKE :search
        OR ps.role_in_program LIKE :search
    )";
    $params[":search"] = "%" . $filter_search . "%";
}

if ($filter_status !== "") {
    $whereSql .= " AND ps.employment_status = :employment_status";
    $params[":employment_status"] = $filter_status;
}

if ($filter_rank !== "") {
    $whereSql .= " AND ps.academic_rank LIKE :academic_rank";
    $params[":academic_rank"] = "%" . $filter_rank . "%";
}

if ($filter_department !== "") {
    $whereSql .= " AND ps.department LIKE :department";
    $params[":department"] = "%" . $filter_department . "%";
}

if ($filter_role !== "") {
    $whereSql .= " AND ps.role_in_program LIKE :role_in_program";
    $params[":role_in_program"] = "%" . $filter_role . "%";
}

// ===============================
// Count records
// ===============================
$countSql = "
    SELECT COUNT(*)
    FROM program_staff ps
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
// Load staff records
// ===============================
$sql = "
    SELECT
        ps.*,
        u.full_name AS user_full_name,
        u.email AS user_email,
        u.role AS user_role,
        u.status AS user_status
    FROM program_staff ps
    LEFT JOIN users u ON ps.user_id = u.id
    $whereSql
    ORDER BY
        CASE 
            WHEN ps.employment_status = 'Active' THEN 1
            WHEN ps.employment_status = 'Inactive' THEN 2
            ELSE 3
        END,
        ps.full_name ASC
    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$staffRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Summary counts
// ===============================
$summaryStmt = $conn->query("
    SELECT
        COUNT(*) AS total_staff,
        SUM(CASE WHEN employment_status = 'Active' THEN 1 ELSE 0 END) AS active_staff,
        SUM(CASE WHEN employment_status = 'Inactive' THEN 1 ELSE 0 END) AS inactive_staff,
        SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) AS linked_users
    FROM program_staff
");

$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$totalStaff = (int)($summary["total_staff"] ?? 0);
$activeStaff = (int)($summary["active_staff"] ?? 0);
$inactiveStaff = (int)($summary["inactive_staff"] ?? 0);
$linkedUsers = (int)($summary["linked_users"] ?? 0);

// ===============================
// Distinct values for filters
// ===============================
$statusOptions = ["Active", "Inactive", "On Leave", "External", "Former"];

$rankStmt = $conn->query("
    SELECT DISTINCT academic_rank
    FROM program_staff
    WHERE academic_rank IS NOT NULL AND academic_rank <> ''
    ORDER BY academic_rank ASC
");
$rankOptions = $rankStmt->fetchAll(PDO::FETCH_COLUMN);

$departmentStmt = $conn->query("
    SELECT DISTINCT department
    FROM program_staff
    WHERE department IS NOT NULL AND department <> ''
    ORDER BY department ASC
");
$departmentOptions = $departmentStmt->fetchAll(PDO::FETCH_COLUMN);

$roleStmt = $conn->query("
    SELECT DISTINCT role_in_program
    FROM program_staff
    WHERE role_in_program IS NOT NULL AND role_in_program <> ''
    ORDER BY role_in_program ASC
");
$roleOptions = $roleStmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Program Staff</title>
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
            grid-template-columns: repeat(3, minmax(200px, 1fr));
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

        .badge-inactive {
            background: #f8d7da;
            color: #842029;
        }

        .badge-other {
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
            width: 110px;
            text-align: center;
            margin-bottom: 5px;
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

        @media (max-width: 1000px) {
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
        <h1>Program Staff</h1>
        <p>Manage Health Promotion program staff profiles and optional links to quality system users.</p>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalStaff); ?></div>
            <div class="summary-label">Total Staff</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($activeStaff); ?></div>
            <div class="summary-label">Active Staff</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($inactiveStaff); ?></div>
            <div class="summary-label">Inactive Staff</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($linkedUsers); ?></div>
            <div class="summary-label">Linked System Users</div>
        </div>
    </div>

    <div class="filter-box">
        <h2>Filter Staff</h2>

        <form method="GET" action="program_staff.php">
            <div class="filter-grid">
                <div>
                    <label>Search</label>
                    <input type="text"
                           name="search"
                           value="<?php echo safeText($filter_search); ?>"
                           placeholder="Name, code, email, specialty, role">
                </div>

                <div>
                    <label>Employment Status</label>
                    <select name="employment_status">
                        <option value="">All Statuses</option>
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?php echo safeText($status); ?>"
                                <?php if ($filter_status === $status) echo "selected"; ?>>
                                <?php echo safeText($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Academic Rank</label>
                    <select name="academic_rank">
                        <option value="">All Ranks</option>
                        <?php foreach ($rankOptions as $rank): ?>
                            <option value="<?php echo safeText($rank); ?>"
                                <?php if ($filter_rank === $rank) echo "selected"; ?>>
                                <?php echo safeText($rank); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Department</label>
                    <select name="department">
                        <option value="">All Departments</option>
                        <?php foreach ($departmentOptions as $department): ?>
                            <option value="<?php echo safeText($department); ?>"
                                <?php if ($filter_department === $department) echo "selected"; ?>>
                                <?php echo safeText($department); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Role in Program</label>
                    <select name="role_in_program">
                        <option value="">All Roles</option>
                        <?php foreach ($roleOptions as $role): ?>
                            <option value="<?php echo safeText($role); ?>"
                                <?php if ($filter_role === $role) echo "selected"; ?>>
                                <?php echo safeText($role); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
    <button type="submit" class="btn">Apply Filters</button>

    <a href="program_staff.php" class="btn btn-secondary">
        Clear Filters
    </a>

    <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
        <a href="create_program_staff.php" class="btn">
            Add Staff
        </a>

        <a href="upload_program_staff_excel.php" class="btn btn-purple">
            Upload Staff CSV
        </a>
    <?php endif; ?>

    <a href="export_program_staff_csv.php?<?php echo safeText(http_build_query($_GET)); ?>" class="btn btn-purple">
        Export Staff CSV
    </a>
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
            <th>Staff</th>
            <th>Academic / Professional Details</th>
            <th>Program Role</th>
            <th>Contact</th>
            <th>Linked User</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>

        <?php if (count($staffRows) > 0): ?>
            <?php foreach ($staffRows as $staff): ?>
                <?php
                    $statusClass = "badge-other";

                    if (($staff["employment_status"] ?? "") === "Active") {
                        $statusClass = "badge-active";
                    } elseif (($staff["employment_status"] ?? "") === "Inactive" || ($staff["employment_status"] ?? "") === "Former") {
                        $statusClass = "badge-inactive";
                    }
                ?>
                <tr>
                    <td><?php echo safeText($staff["id"]); ?></td>

                    <td>
                        <strong><?php echo safeText($staff["full_name"]); ?></strong>

                        <?php if (!empty($staff["staff_code"])): ?>
                            <br>
                            <span class="small-text">
                                Staff Code: <?php echo safeText($staff["staff_code"]); ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($staff["academic_rank"])): ?>
                            <strong><?php echo safeText($staff["academic_rank"]); ?></strong><br>
                        <?php endif; ?>

                        <?php if (!empty($staff["qualification"])): ?>
                            <?php echo safeText($staff["qualification"]); ?><br>
                        <?php endif; ?>

                        <?php if (!empty($staff["specialty"])): ?>
                            <span class="small-text">
                                Specialty: <?php echo safeText($staff["specialty"]); ?>
                            </span>
                        <?php endif; ?>

                        <?php if (!empty($staff["department"])): ?>
                            <br>
                            <span class="small-text">
                                Department: <?php echo safeText($staff["department"]); ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($staff["role_in_program"])): ?>
                            <?php echo safeText($staff["role_in_program"]); ?>
                        <?php else: ?>
                            <span class="missing">Not specified</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($staff["email"])): ?>
                            <?php echo safeText($staff["email"]); ?><br>
                        <?php endif; ?>

                        <?php if (!empty($staff["phone"])): ?>
                            <span class="small-text">
                                <?php echo safeText($staff["phone"]); ?>
                            </span>
                        <?php endif; ?>

                        <?php if (empty($staff["email"]) && empty($staff["phone"])): ?>
                            <span class="missing">No contact</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($staff["user_id"])): ?>
                            <strong><?php echo safeText($staff["user_full_name"] ?? "Linked user"); ?></strong><br>
                            <span class="small-text">
                                <?php echo safeText($staff["user_email"] ?? ""); ?>
                                <?php if (!empty($staff["user_role"])): ?>
                                    | <?php echo safeText($staff["user_role"]); ?>
                                <?php endif; ?>
                                <?php if (!empty($staff["user_status"])): ?>
                                    | <?php echo safeText($staff["user_status"]); ?>
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="missing">Not linked</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <span class="badge <?php echo safeText($statusClass); ?>">
                            <?php echo safeText($staff["employment_status"] ?? ""); ?>
                        </span>
                    </td>

                    <td class="actions">
                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
				<a href="program_staff_profile.php?id=<?php echo safeText($staff["id"]); ?>" class="btn">
   				 Profile
				</a>
                            <a href="edit_program_staff.php?id=<?php echo safeText($staff["id"]); ?>" class="btn btn-edit">
                                Edit
                            </a>
                        <?php endif; ?>

                        <?php if (hasRole(["Admin", "Quality Coordinator"])): ?>
                            <?php echo deleteForm('delete_program_staff.php?id=' . urlencode((string)$staff['id']), 'Are you sure you want to delete this staff profile?'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="8">No staff records found.</td>
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
