<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// Read filters from program_staff.php
// ===============================
$filter_search = trim($_GET["search"] ?? "");
$filter_status = trim($_GET["employment_status"] ?? "");
$filter_rank = trim($_GET["academic_rank"] ?? "");
$filter_department = trim($_GET["department"] ?? "");
$filter_role = trim($_GET["role_in_program"] ?? "");

// ===============================
// Build SQL
// ===============================
$sql = "
    SELECT
        ps.id,
        ps.staff_code,
        ps.full_name,
        ps.academic_rank,
        ps.qualification,
        ps.specialty,
        ps.department,
        ps.college,
        ps.institution,
        ps.email,
        ps.phone,
        ps.employment_status,
        ps.role_in_program,
        ps.notes,
        ps.created_at,
        ps.updated_at,

        u.full_name AS linked_user_full_name,
        u.email AS linked_user_email,
        u.role AS linked_user_role,
        u.status AS linked_user_status

    FROM program_staff ps
    LEFT JOIN users u ON ps.user_id = u.id
    WHERE 1 = 1
";

$params = [];

if ($filter_search !== "") {
    $sql .= " AND (
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
    $sql .= " AND ps.employment_status = :employment_status";
    $params[":employment_status"] = $filter_status;
}

if ($filter_rank !== "") {
    $sql .= " AND ps.academic_rank LIKE :academic_rank";
    $params[":academic_rank"] = "%" . $filter_rank . "%";
}

if ($filter_department !== "") {
    $sql .= " AND ps.department LIKE :department";
    $params[":department"] = "%" . $filter_department . "%";
}

if ($filter_role !== "") {
    $sql .= " AND ps.role_in_program LIKE :role_in_program";
    $params[":role_in_program"] = "%" . $filter_role . "%";
}

$sql .= "
    ORDER BY
        CASE 
            WHEN ps.employment_status = 'Active' THEN 1
            WHEN ps.employment_status = 'Inactive' THEN 2
            ELSE 3
        END,
        ps.full_name ASC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// CSV output
// ===============================
$filename = "program_staff_export_" . date("Y-m-d_H-i-s") . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

$output = fopen("php://output", "w");

// Header row
fputcsv($output, [
    "ID",
    "Staff Code",
    "Full Name",
    "Academic Rank",
    "Qualification",
    "Specialty",
    "Department",
    "College",
    "Institution",
    "Email",
    "Phone",
    "Employment Status",
    "Role in Program",
    "Notes",
    "Linked User Name",
    "Linked User Email",
    "Linked User Role",
    "Linked User Status",
    "Created At",
    "Updated At"
]);

// Data rows
foreach ($rows as $row) {
    fputcsv($output, [
        $row["id"],
        $row["staff_code"],
        $row["full_name"],
        $row["academic_rank"],
        $row["qualification"],
        $row["specialty"],
        $row["department"],
        $row["college"],
        $row["institution"],
        $row["email"],
        $row["phone"],
        $row["employment_status"],
        $row["role_in_program"],
        $row["notes"],
        $row["linked_user_full_name"],
        $row["linked_user_email"],
        $row["linked_user_role"],
        $row["linked_user_status"],
        $row["created_at"],
        $row["updated_at"]
    ]);
}

fclose($output);
exit;