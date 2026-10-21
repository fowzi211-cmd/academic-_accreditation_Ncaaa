<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// Validate study plan ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid study plan ID.");
}

$study_plan_id = (int)$_GET["id"];

// ===============================
// Helper functions
// ===============================
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

// ===============================
// Required table checks
// ===============================
if (!tableExists($conn, "study_plans")) {
    die("study_plans table does not exist.");
}

if (!tableExists($conn, "study_plan_course_matrix")) {
    die("study_plan_course_matrix table does not exist.");
}

// ===============================
// Load study plan
// ===============================
$planStmt = $conn->prepare("
    SELECT *
    FROM study_plans
    WHERE id = :id
    LIMIT 1
");
$planStmt->execute([":id" => $study_plan_id]);
$plan = $planStmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    die("Study plan not found.");
}

// ===============================
// Optional filters from manage page
// ===============================
$filter_search = trim($_GET["search"] ?? "");
$filter_semester_no = trim($_GET["semester_no"] ?? "");
$filter_requirement_type = trim($_GET["course_requirement_type"] ?? "");
$filter_teaching_mode = trim($_GET["teaching_mode"] ?? "");
$filter_responsible_department = trim($_GET["responsible_department"] ?? "");

// ===============================
// Build WHERE
// ===============================
$whereSql = " WHERE study_plan_id = :study_plan_id ";
$params = [
    ":study_plan_id" => $study_plan_id
];

if ($filter_search !== "") {
    $whereSql .= " AND (
        course_code_ar LIKE :search
        OR course_name_ar LIKE :search
        OR course_code_en LIKE :search
        OR course_name_en LIKE :search
        OR course_description LIKE :search
        OR responsible_department LIKE :search
        OR prerequisite_1_name LIKE :search
        OR prerequisite_2_name LIKE :search
        OR prerequisite_1_code LIKE :search
        OR prerequisite_2_code LIKE :search
    )";
    $params[":search"] = "%" . $filter_search . "%";
}

if ($filter_semester_no !== "") {
    $whereSql .= " AND semester_no = :semester_no";
    $params[":semester_no"] = $filter_semester_no;
}

if ($filter_requirement_type !== "") {
    $whereSql .= " AND course_requirement_type = :course_requirement_type";
    $params[":course_requirement_type"] = $filter_requirement_type;
}

if ($filter_teaching_mode !== "") {
    $whereSql .= " AND teaching_mode = :teaching_mode";
    $params[":teaching_mode"] = $filter_teaching_mode;
}

if ($filter_responsible_department !== "") {
    $whereSql .= " AND responsible_department = :responsible_department";
    $params[":responsible_department"] = $filter_responsible_department;
}

// ===============================
// Load course matrix
// ===============================
$sql = "
    SELECT *
    FROM study_plan_course_matrix
    $whereSql
    ORDER BY
        semester_no ASC,
        course_sequence ASC,
        id ASC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// CSV output
// ===============================
$planTitle = $plan["plan_title"] ?? "study_plan";
$safePlanName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $planTitle);

$filename = "study_plan_courses_" . $safePlanName . "_" . date("Y-m-d_H-i-s") . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// UTF-8 BOM for Arabic support in Excel
echo "\xEF\xBB\xBF";

$output = fopen("php://output", "w");

// ===============================
// Header row matching upload format
// ===============================
fputcsv($output, [
    "course_sequence",
    "semester_no",
    "course_code_ar",
    "course_name_ar",
    "course_code_en",
    "course_name_en",
    "credit_hours",
    "course_requirement_type",
    "teaching_mode",
    "course_activity",

    "credit_theory",
    "credit_practical",
    "credit_studio",
    "credit_training",
    "credit_graduation_project",
    "credit_seminar",
    "credit_clinical_training",
    "credit_educational_applications",
    "credit_clinical_simulation",
    "credit_other",

    "contact_theory",
    "contact_practical",
    "contact_studio",
    "contact_training",
    "contact_graduation_project",
    "contact_seminar",
    "contact_clinical_training",
    "contact_educational_applications",
    "contact_clinical_simulation",
    "contact_other",

    "prerequisite_1_name",
    "prerequisite_2_name",
    "prerequisite_1_code",
    "prerequisite_2_code",
    "prerequisite_other",

    "responsible_department",
    "course_description",
    "track_name",
    "exit_point",
    "notes"
]);

// ===============================
// Data rows
// ===============================
foreach ($rows as $row) {
    fputcsv($output, [
        $row["course_sequence"] ?? "",
        $row["semester_no"] ?? "",
        $row["course_code_ar"] ?? "",
        $row["course_name_ar"] ?? "",
        $row["course_code_en"] ?? "",
        $row["course_name_en"] ?? "",
        $row["credit_hours"] ?? "",
        $row["course_requirement_type"] ?? "",
        $row["teaching_mode"] ?? "",
        $row["course_activity"] ?? "",

        $row["credit_theory"] ?? "",
        $row["credit_practical"] ?? "",
        $row["credit_studio"] ?? "",
        $row["credit_training"] ?? "",
        $row["credit_graduation_project"] ?? "",
        $row["credit_seminar"] ?? "",
        $row["credit_clinical_training"] ?? "",
        $row["credit_educational_applications"] ?? "",
        $row["credit_clinical_simulation"] ?? "",
        $row["credit_other"] ?? "",

        $row["contact_theory"] ?? "",
        $row["contact_practical"] ?? "",
        $row["contact_studio"] ?? "",
        $row["contact_training"] ?? "",
        $row["contact_graduation_project"] ?? "",
        $row["contact_seminar"] ?? "",
        $row["contact_clinical_training"] ?? "",
        $row["contact_educational_applications"] ?? "",
        $row["contact_clinical_simulation"] ?? "",
        $row["contact_other"] ?? "",

        $row["prerequisite_1_name"] ?? "",
        $row["prerequisite_2_name"] ?? "",
        $row["prerequisite_1_code"] ?? "",
        $row["prerequisite_2_code"] ?? "",
        $row["prerequisite_other"] ?? "",

        $row["responsible_department"] ?? "",
        $row["course_description"] ?? "",
        $row["track_name"] ?? "",
        $row["exit_point"] ?? "",
        $row["notes"] ?? ""
    ]);
}

fclose($output);
exit;