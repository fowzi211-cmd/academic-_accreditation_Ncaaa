<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"]);

// ===============================
// Read filters
// ===============================
$filter_academic_year = trim($_GET["academic_year"] ?? "");
$filter_semester = $_GET["semester"] ?? "";
$filter_status = $_GET["status"] ?? "";
$filter_coordinator = trim($_GET["course_coordinator"] ?? "");
$filter_search = trim($_GET["search"] ?? "");

// ===============================
// Build query
// ===============================
$sql = "
    SELECT
        cr.*,

        cs.id AS linked_spec_id,
        cs.course_code AS spec_course_code,
        cs.course_title AS spec_course_title,
        cs.program_name AS spec_program_name,
        cs.department_name AS spec_department_name,
        cs.college_name AS spec_college_name,
        cs.institution_name AS spec_institution_name,
        cs.specification_version AS spec_version,
        cs.last_revision_date AS spec_last_revision_date,
        cs.status AS spec_status

    FROM course_reports cr

    LEFT JOIN course_specifications cs
        ON cs.id = cr.course_specification_id

    WHERE 1 = 1
";

$params = [];

if ($filter_academic_year !== "") {
    $sql .= " AND cr.academic_year LIKE :academic_year";
    $params[":academic_year"] = "%" . $filter_academic_year . "%";
}

if ($filter_semester !== "") {
    $sql .= " AND cr.semester = :semester";
    $params[":semester"] = $filter_semester;
}

if ($filter_status !== "") {
    $sql .= " AND cr.status = :status";
    $params[":status"] = $filter_status;
}

if ($filter_coordinator !== "") {
    $sql .= " AND cr.course_coordinator LIKE :course_coordinator";
    $params[":course_coordinator"] = "%" . $filter_coordinator . "%";
}

// IMPORTANT:
// Use separate placeholders. Reusing one :search many times can break
// when PDO native prepares are enabled.
if ($filter_search !== "") {
    $sql .= " AND (
        cr.course_code LIKE :search_course_code
        OR cr.course_title LIKE :search_course_title
        OR cr.course_coordinator LIKE :search_course_coordinator
        OR cr.clo_achievement_summary LIKE :search_clo
        OR cr.assessment_summary LIKE :search_assessment
        OR cr.teaching_summary LIKE :search_teaching
        OR cr.student_feedback_summary LIKE :search_feedback
        OR cr.course_improvement_actions LIKE :search_improvement
        OR cr.general_notes LIKE :search_notes
        OR cs.course_code LIKE :search_spec_code
        OR cs.course_title LIKE :search_spec_title
        OR cs.specification_version LIKE :search_spec_version
        OR cs.status LIKE :search_spec_status
    )";

    $searchValue = "%" . $filter_search . "%";

    $params[":search_course_code"] = $searchValue;
    $params[":search_course_title"] = $searchValue;
    $params[":search_course_coordinator"] = $searchValue;
    $params[":search_clo"] = $searchValue;
    $params[":search_assessment"] = $searchValue;
    $params[":search_teaching"] = $searchValue;
    $params[":search_feedback"] = $searchValue;
    $params[":search_improvement"] = $searchValue;
    $params[":search_notes"] = $searchValue;
    $params[":search_spec_code"] = $searchValue;
    $params[":search_spec_title"] = $searchValue;
    $params[":search_spec_version"] = $searchValue;
    $params[":search_spec_status"] = $searchValue;
}

$sql .= " ORDER BY cr.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Helper functions
// ===============================
function calcRateCsv($part, $total) {
    $part = (int)$part;
    $total = (int)$total;

    if ($total <= 0) {
        return 0;
    }

    return round(($part / $total) * 100, 1);
}

function csvValue($value) {
    if ($value === null) {
        return "";
    }

    return (string)$value;
}

function linkedSpecStatusText($row) {
    $courseSpecificationId = $row["course_specification_id"] ?? null;
    $linkedSpecId = $row["linked_spec_id"] ?? null;

    if ($linkedSpecId !== null && trim((string)$linkedSpecId) !== "" && (int)$linkedSpecId > 0) {
        return "Linked";
    }

    if ($courseSpecificationId !== null && trim((string)$courseSpecificationId) !== "" && (int)$courseSpecificationId > 0) {
        return "Broken Link - specification ID exists in course report but no matching specification was found";
    }

    return "Not Linked";
}

// ===============================
// Output CSV
// ===============================
$filename = "course_reports_export_" . date("Y-m-d_H-i-s") . ".csv";

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

    "Course Code",
    "Course Title",

    "Linked Specification Status",
    "Course Specification ID",
    "Specification Course Code",
    "Specification Course Title",
    "Specification Program",
    "Specification Department",
    "Specification College",
    "Specification Institution",
    "Specification Version",
    "Specification Last Revision Date",
    "Specification Status",

    "Academic Year",
    "Semester",
    "Course Coordinator",

    "Students Enrolled",
    "Students Passed",
    "Students Failed",
    "Pass Rate %",
    "Fail Rate %",

    "Report Status",

    "CLO Achievement Summary",
    "Assessment Summary",
    "Teaching Summary",
    "Student Feedback Summary",
    "Course Improvement Actions",
    "General Notes",

    "Created At",
    "Updated At"
]);

// Data rows
foreach ($rows as $row) {
    $enrolled = (int)($row["students_enrolled"] ?? 0);
    $passed = (int)($row["students_passed"] ?? 0);
    $failed = (int)($row["students_failed"] ?? 0);

    $passRate = calcRateCsv($passed, $enrolled);
    $failRate = calcRateCsv($failed, $enrolled);

    fputcsv($output, [
        csvValue($row["id"] ?? ""),

        csvValue($row["course_code"] ?? ""),
        csvValue($row["course_title"] ?? ""),

        linkedSpecStatusText($row),
        csvValue($row["course_specification_id"] ?? ""),
        csvValue($row["spec_course_code"] ?? ""),
        csvValue($row["spec_course_title"] ?? ""),
        csvValue($row["spec_program_name"] ?? ""),
        csvValue($row["spec_department_name"] ?? ""),
        csvValue($row["spec_college_name"] ?? ""),
        csvValue($row["spec_institution_name"] ?? ""),
        csvValue($row["spec_version"] ?? ""),
        csvValue($row["spec_last_revision_date"] ?? ""),
        csvValue($row["spec_status"] ?? ""),

        csvValue($row["academic_year"] ?? ""),
        csvValue($row["semester"] ?? ""),
        csvValue($row["course_coordinator"] ?? ""),

        $enrolled,
        $passed,
        $failed,
        $passRate,
        $failRate,

        csvValue($row["status"] ?? ""),

        csvValue($row["clo_achievement_summary"] ?? ""),
        csvValue($row["assessment_summary"] ?? ""),
        csvValue($row["teaching_summary"] ?? ""),
        csvValue($row["student_feedback_summary"] ?? ""),
        csvValue($row["course_improvement_actions"] ?? ""),
        csvValue($row["general_notes"] ?? ""),

        csvValue($row["created_at"] ?? ""),
        csvValue($row["updated_at"] ?? "")
    ]);
}

fclose($output);
exit;
?>