<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"]);

// ===============================
// Read filter values from URL
// ===============================
$filter_standard_id = $_GET["standard_id"] ?? "";
$filter_substandard_id = $_GET["substandard_id"] ?? "";
$filter_status = $_GET["status"] ?? "";
$filter_academic_year = trim($_GET["academic_year"] ?? "");
$filter_semester = $_GET["semester"] ?? "";
$filter_responsible_person = trim($_GET["responsible_person"] ?? "");
$filter_search = trim($_GET["search"] ?? "");

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
// Load standards for filter dropdown
// ===============================
$standardsStmt = $conn->query("
    SELECT id, standard_code, standard_title
    FROM standards
    ORDER BY id
");
$standards = $standardsStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Load substandards for filter dropdown
// ===============================
$substandardsStmt = $conn->query("
    SELECT 
        ss.id,
        ss.substandard_code,
        ss.substandard_text,
        s.standard_code
    FROM substandards ss
    INNER JOIN standards s ON ss.standard_id = s.id
    ORDER BY s.id, ss.substandard_code
");
$substandards = $substandardsStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Build WHERE filters once
// ===============================
$whereSql = " WHERE 1 = 1 ";
$params = [];

if ($filter_standard_id !== "") {
    $whereSql .= " AND s.id = :standard_id";
    $params[":standard_id"] = $filter_standard_id;
}

if ($filter_substandard_id !== "") {
    $whereSql .= " AND ss.id = :substandard_id";
    $params[":substandard_id"] = $filter_substandard_id;
}

if ($filter_status !== "") {
    $whereSql .= " AND e.status = :status";
    $params[":status"] = $filter_status;
}

if ($filter_academic_year !== "") {
    $whereSql .= " AND e.academic_year LIKE :academic_year";
    $params[":academic_year"] = "%" . $filter_academic_year . "%";
}

if ($filter_semester !== "") {
    $whereSql .= " AND e.semester = :semester";
    $params[":semester"] = $filter_semester;
}

if ($filter_responsible_person !== "") {
    $whereSql .= " AND e.responsible_person LIKE :responsible_person";
    $params[":responsible_person"] = "%" . $filter_responsible_person . "%";
}

if ($filter_search !== "") {
    $whereSql .= " AND (
        e.title LIKE :search
        OR e.notes LIKE :search
        OR e.evidence_type LIKE :search
        OR e.responsible_person LIKE :search
        OR ss.substandard_code LIKE :search
        OR ss.substandard_text LIKE :search
        OR s.standard_code LIKE :search
        OR s.standard_title LIKE :search
    )";
    $params[":search"] = "%" . $filter_search . "%";
}

// ===============================
// Count total filtered records
// ===============================
$countSql = "
    SELECT COUNT(DISTINCT e.id)
    FROM evidence_items e
    LEFT JOIN substandards ss ON e.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
    $whereSql
";

$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

$totalPages = (int)ceil($totalRecords / $recordsPerPage);

if ($totalPages < 1) {
    $totalPages = 1;
}

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $recordsPerPage;
}

// Safety: force integer values before inserting into LIMIT/OFFSET
$recordsPerPage = (int)$recordsPerPage;
$offset = (int)$offset;

// ===============================
// Main paginated query
// ===============================
$sql = "
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

        s.id AS standard_id,
        s.standard_code,
        s.standard_title,

        ss.id AS substandard_id,
        ss.substandard_code,
        ss.substandard_text,

        COUNT(ef.id) AS file_count

    FROM evidence_items e
    LEFT JOIN substandards ss ON e.substandard_id = ss.id
    LEFT JOIN standards s ON ss.standard_id = s.id
    LEFT JOIN evidence_files ef ON e.id = ef.evidence_id

    $whereSql

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
        s.id,
        s.standard_code,
        s.standard_title,
        ss.id,
        ss.substandard_code,
        ss.substandard_text

    ORDER BY e.id DESC

    LIMIT $recordsPerPage OFFSET $offset
";

$stmt = $conn->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$evidenceItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Preserve filters in pagination links
// ===============================
$queryParams = $_GET;
unset($queryParams["page"]);

$baseQueryString = http_build_query($queryParams);
$basePageUrl = "index.php" . ($baseQueryString !== "" ? "?" . $baseQueryString . "&" : "?");
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Quality System - Evidence Repository</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .filter-box {
            background: white;
            padding: 15px;
            border: 1px solid #ccc;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .filter-actions {
            margin-top: 15px;
        }

        .result-count {
            margin-bottom: 12px;
            font-weight: bold;
            color: #333;
        }

        .status {
            font-weight: bold;
        }

        .missing {
            color: #999;
            font-style: italic;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .actions a {
            display: block;
            width: 90px;
            text-align: center;
            margin-bottom: 5px;
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
            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 700px) {
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
        <h1>Evidence Repository</h1>
        <p>Manage accreditation evidence, mapped standards, substandards, files, and review status.</p>
    </div>

    <div class="filter-box">
        <h2>Filter Evidence</h2>

        <form method="GET" action="index.php">

            <div class="filter-grid">

                <div>
                    <label>Search</label>
                    <input type="text" name="search"
                           value="<?php echo htmlspecialchars($filter_search); ?>"
                           placeholder="Search title, notes, standard, substandard">
                </div>

                <div>
                    <label>Standard</label>
                    <select name="standard_id">
                        <option value="">All Standards</option>

                        <?php foreach ($standards as $standard): ?>
                            <option value="<?php echo htmlspecialchars($standard["id"]); ?>"
                                <?php if ((string)$filter_standard_id === (string)$standard["id"]) echo "selected"; ?>>
                                <?php echo htmlspecialchars($standard["standard_code"] . " - " . $standard["standard_title"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Substandard</label>
                    <select name="substandard_id">
                        <option value="">All Substandards</option>

                        <?php foreach ($substandards as $sub): ?>
                            <option value="<?php echo htmlspecialchars($sub["id"]); ?>"
                                <?php if ((string)$filter_substandard_id === (string)$sub["id"]) echo "selected"; ?>>
                                <?php
                                    echo htmlspecialchars(
                                        $sub["standard_code"] . " - " .
                                        $sub["substandard_code"] . " | " .
                                        $sub["substandard_text"]
                                    );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>

                        <?php
                        $statuses = ["Missing", "Draft", "Uploaded", "Reviewed", "Approved"];
                        foreach ($statuses as $status):
                        ?>
                            <option value="<?php echo htmlspecialchars($status); ?>"
                                <?php if ($filter_status === $status) echo "selected"; ?>>
                                <?php echo htmlspecialchars($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Academic Year</label>
                    <input type="text" name="academic_year"
                           value="<?php echo htmlspecialchars($filter_academic_year); ?>"
                           placeholder="Example: 2025-2026">
                </div>

                <div>
                    <label>Semester</label>
                    <select name="semester">
                        <option value="">All Semesters</option>

                        <?php
                        $semesters = ["First Semester", "Second Semester", "Third Semester", "Annual"];
                        foreach ($semesters as $semester):
                        ?>
                            <option value="<?php echo htmlspecialchars($semester); ?>"
                                <?php if ($filter_semester === $semester) echo "selected"; ?>>
                                <?php echo htmlspecialchars($semester); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Responsible Person</label>
                    <input type="text" name="responsible_person"
                           value="<?php echo htmlspecialchars($filter_responsible_person); ?>"
                           placeholder="Example: Quality Coordinator">
                </div>

            </div>

            <div class="filter-actions">
                <button type="submit" class="btn">Apply Filters</button>
                <a href="index.php" class="btn btn-secondary">Clear Filters</a>

                <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                    <a href="create.php" class="btn">Add Evidence</a>
                <?php endif; ?>

                <a href="export_evidence_csv.php?<?php echo htmlspecialchars(http_build_query($_GET)); ?>" 
                   class="btn btn-purple">
                    Export Evidence CSV
                </a>
            </div>

        </form>
    </div>

    <div class="result-count">
        Results found: <?php echo htmlspecialchars($totalRecords); ?>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>Evidence Title</th>
            <th>Standard</th>
            <th>Substandard</th>
            <th>Requirement</th>
            <th>Type</th>
            <th>Responsible</th>
            <th>Status</th>
            <th>Academic Year</th>
            <th>Semester</th>
            <th>Review</th>
            <th>Notes</th>
            <th>Files</th>
            <th>Actions</th>
        </tr>

        <?php if (count($evidenceItems) > 0): ?>

            <?php foreach ($evidenceItems as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item["id"]); ?></td>

                    <td><?php echo htmlspecialchars($item["title"] ?? ""); ?></td>

                    <td>
                        <?php if (!empty($item["standard_code"])): ?>
                            <strong><?php echo htmlspecialchars($item["standard_code"]); ?></strong><br>
                            <span class="small-text">
                                <?php echo htmlspecialchars($item["standard_title"] ?? ""); ?>
                            </span>
                        <?php else: ?>
                            <span class="missing">Not linked</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($item["substandard_code"])): ?>
                            <?php echo htmlspecialchars($item["substandard_code"]); ?>
                        <?php else: ?>
                            <span class="missing">Not linked</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($item["substandard_text"])): ?>
                            <?php echo htmlspecialchars($item["substandard_text"]); ?>
                        <?php else: ?>
                            <span class="missing">No mapped requirement</span>
                        <?php endif; ?>
                    </td>

                    <td><?php echo htmlspecialchars($item["evidence_type"] ?? ""); ?></td>

                    <td><?php echo htmlspecialchars($item["responsible_person"] ?? ""); ?></td>

                    <td class="status"><?php echo htmlspecialchars($item["status"] ?? ""); ?></td>

                    <td><?php echo htmlspecialchars($item["academic_year"] ?? ""); ?></td>

                    <td><?php echo htmlspecialchars($item["semester"] ?? ""); ?></td>

                    <td><?php echo htmlspecialchars($item["review_status"] ?? ""); ?></td>

                    <td><?php echo nl2br(htmlspecialchars($item["notes"] ?? "")); ?></td>

                    <td>
                        <?php if ((int)$item["file_count"] > 0): ?>
                            <?php echo htmlspecialchars($item["file_count"]); ?> file(s)
                        <?php else: ?>
                            <span class="missing">No files</span>
                        <?php endif; ?>

                        <br>

                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty"])): ?>
                            <a href="upload_file.php?id=<?php echo htmlspecialchars($item["id"]); ?>" class="btn btn-purple">
                                Upload/View
                            </a>
                        <?php endif; ?>
                    </td>

                    <td class="actions">

                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"])): ?>
                            <a href="edit.php?id=<?php echo htmlspecialchars($item["id"]); ?>" class="btn btn-edit">
                                Edit
                            </a>
                        <?php endif; ?>

                        <?php if (hasRole("Admin")): ?>
                            <?php echo deleteForm('delete.php?id=' . urlencode((string)$item['id']), 'Are you sure you want to delete this evidence item?'); ?>
                        <?php endif; ?>

                    </td>
                </tr>
            <?php endforeach; ?>

        <?php else: ?>

            <tr>
                <td colspan="14">No evidence records found for the selected filters.</td>
            </tr>

        <?php endif; ?>

    </table>

    <div class="pagination">
        <div class="pagination-info">
            Page <?php echo htmlspecialchars($page); ?> of <?php echo htmlspecialchars($totalPages); ?>
            |
            <?php echo htmlspecialchars($recordsPerPage); ?> records per page
            |
            Total records: <?php echo htmlspecialchars($totalRecords); ?>
        </div>

        <?php if ($page > 1): ?>
            <a href="<?php echo htmlspecialchars($basePageUrl); ?>page=1">First</a>
            <a href="<?php echo htmlspecialchars($basePageUrl); ?>page=<?php echo htmlspecialchars($page - 1); ?>">Previous</a>
        <?php else: ?>
            <span class="disabled">First</span>
            <span class="disabled">Previous</span>
        <?php endif; ?>

        <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
        ?>

        <?php if ($startPage > 1): ?>
            <a href="<?php echo htmlspecialchars($basePageUrl); ?>page=1">1</a>

            <?php if ($startPage > 2): ?>
                <span class="disabled">...</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="current-page"><?php echo htmlspecialchars($i); ?></span>
            <?php else: ?>
                <a href="<?php echo htmlspecialchars($basePageUrl); ?>page=<?php echo htmlspecialchars($i); ?>">
                    <?php echo htmlspecialchars($i); ?>
                </a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?>
                <span class="disabled">...</span>
            <?php endif; ?>

            <a href="<?php echo htmlspecialchars($basePageUrl); ?>page=<?php echo htmlspecialchars($totalPages); ?>">
                <?php echo htmlspecialchars($totalPages); ?>
            </a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
            <a href="<?php echo htmlspecialchars($basePageUrl); ?>page=<?php echo htmlspecialchars($page + 1); ?>">Next</a>
            <a href="<?php echo htmlspecialchars($basePageUrl); ?>page=<?php echo htmlspecialchars($totalPages); ?>">Last</a>
        <?php else: ?>
            <span class="disabled">Next</span>
            <span class="disabled">Last</span>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
