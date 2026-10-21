<?php
require_once "auth_check.php";
require_once "role_check.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// CSV filename
// ===============================
$filename = "study_plan_course_template_" . date("Y-m-d_H-i-s") . ".csv";

// ===============================
// Output headers
// ===============================
header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// UTF-8 BOM for Arabic support in Excel
echo "\xEF\xBB\xBF";

$output = fopen("php://output", "w");

// ===============================
// Header row - must match upload_study_plan_courses_csv.php
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
// Sample row 1
// ===============================
fputcsv($output, [
    "1",
    "1",
    "HP1001",
    "المصطلحات الطبية في الصحة العامة",
    "HP1001",
    "Medical Terminology in Public Health",
    "2",
    "College Required Course",
    "Face-to-face",
    "Theory",

    "2",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",

    "2",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",

    "",
    "",
    "",
    "",
    "",

    "Department of Health Promotion and Education",
    "Medical Terminology in Public Health equips students to understand and use common medical and public health terminology.",
    "",
    "",
    "Sample row. Replace with actual study plan data."
]);

// ===============================
// Sample row 2
// ===============================
fputcsv($output, [
    "2",
    "1",
    "Epi1001",
    "أحياء الصحة العامة",
    "Epi1001",
    "Public Health Biology",
    "3",
    "College Required Course",
    "Face-to-face",
    "Theory",

    "3",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",

    "3",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",

    "",
    "",
    "",
    "",
    "",

    "Department of Epidemiology and Biostatistics",
    "This course introduces biological concepts relevant to public health practice and disease prevention.",
    "",
    "",
    "Sample row. Replace with actual study plan data."
]);

// ===============================
// Sample row 3 with prerequisite
// ===============================
fputcsv($output, [
    "3",
    "3",
    "HP2003",
    "مقدمة في التعزيز الصحي",
    "HP2003",
    "Introduction to Health Promotion",
    "3",
    "Program Required Course",
    "Face-to-face",
    "Theory",

    "3",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",

    "3",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",
    "0",

    "Principles of Public Health Profession",
    "",
    "HP1002",
    "",
    "",

    "Department of Health Promotion and Education",
    "Introduction to Health Promotion provides students with an overview of health promotion concepts, determinants of health, and basic intervention design.",
    "",
    "",
    "Sample row with prerequisite."
]);

fclose($output);
exit;