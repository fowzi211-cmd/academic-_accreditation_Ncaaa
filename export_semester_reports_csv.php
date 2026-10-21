<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

$stmt = $conn->query("
    SELECT 
        id,
        academic_year,
        semester,
        report_title,
        prepared_by,
        status,
        program_strengths,
        program_weaknesses,
        improvement_actions,
        kpi_summary,
        course_report_summary,
        student_feedback_summary,
        teaching_learning_summary,
        assessment_summary,
        resources_summary,
        general_notes,
        created_at,
        updated_at
    FROM semester_reports
    ORDER BY id DESC
");

$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = "semester_reports_export_" . date("Y-m-d_H-i-s") . ".csv";

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
    "Academic Year",
    "Semester",
    "Report Title",
    "Prepared By",
    "Status",
    "Program Strengths",
    "Program Weaknesses / Areas for Improvement",
    "Improvement Actions",
    "KPI Summary",
    "Course Report Summary",
    "Student Feedback Summary",
    "Teaching and Learning Summary",
    "Assessment Summary",
    "Resources Summary",
    "General Notes",
    "Created At",
    "Updated At"
]);

// Data rows
foreach ($reports as $report) {
    fputcsv($output, [
        $report["id"],
        $report["academic_year"],
        $report["semester"],
        $report["report_title"],
        $report["prepared_by"],
        $report["status"],
        $report["program_strengths"],
        $report["program_weaknesses"],
        $report["improvement_actions"],
        $report["kpi_summary"],
        $report["course_report_summary"],
        $report["student_feedback_summary"],
        $report["teaching_learning_summary"],
        $report["assessment_summary"],
        $report["resources_summary"],
        $report["general_notes"],
        $report["created_at"],
        $report["updated_at"]
    ]);
}

fclose($output);
exit;