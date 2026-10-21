<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid program specification ID.");
}

$spec_id = (int)$_GET["id"];

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

function columnExists($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND column_name = :column_name
        ");
        $stmt->execute([
            ":table_name" => $tableName,
            ":column_name" => $columnName
        ]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function addColumnIfMissing($conn, $tableName, $columnName, $definition) {
    if (!columnExists($conn, $tableName, $columnName)) {
        requireSchemaColumn($conn, $tableName, $columnName);
    }
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "program_specification_evaluation_matrix")) {
    die("program_specification_evaluation_matrix table does not exist.");
}

/*
    Safety upgrade for older tables.
*/
addColumnIfMissing($conn, "program_specification_evaluation_matrix", "evaluation_area", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_evaluation_matrix", "evaluation_sources", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_evaluation_matrix", "evaluation_methods", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_evaluation_matrix", "evaluation_time", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_evaluation_matrix", "notes", "TEXT NULL");

$specStmt = $conn->prepare("
    SELECT *
    FROM program_specifications
    WHERE id = :id
    LIMIT 1
");
$specStmt->execute([":id" => $spec_id]);
$spec = $specStmt->fetch(PDO::FETCH_ASSOC);

if (!$spec) {
    die("Program specification not found.");
}

$defaultRows = [
    [
        "evaluation_area" => "Program Management",
        "evaluation_sources" => "Program leader, department council, quality committee, program committees, teaching staff",
        "evaluation_methods" => "Annual program report, committee reports, meeting minutes, action plan follow-up, KPI review",
        "evaluation_time" => "Annually",
        "notes" => "Used to evaluate program governance, planning, implementation, and follow-up of improvement actions."
    ],
    [
        "evaluation_area" => "Course Effectiveness",
        "evaluation_sources" => "Students, course instructors, course coordinators, course reports",
        "evaluation_methods" => "Course evaluation surveys, course reports, assessment analysis, CLO achievement review, improvement plans",
        "evaluation_time" => "End of each semester",
        "notes" => "Used to evaluate course delivery, assessment quality, student satisfaction, and achievement of course learning outcomes."
    ],
    [
        "evaluation_area" => "Student Learning Outcomes",
        "evaluation_sources" => "Course reports, direct assessment results, exit exam results, graduation project assessment, internship evaluation",
        "evaluation_methods" => "Direct and indirect PLO assessment, PLO-course mapping review, benchmark comparison, trend analysis",
        "evaluation_time" => "Annually",
        "notes" => "Used to evaluate achievement of program learning outcomes and identify improvement priorities."
    ],
    [
        "evaluation_area" => "Graduate Attributes",
        "evaluation_sources" => "Graduates, alumni surveys, employers, internship supervisors, advisory committee",
        "evaluation_methods" => "Graduate survey, employer survey, internship supervisor feedback, alumni tracking, advisory committee feedback",
        "evaluation_time" => "Annually",
        "notes" => "Used to evaluate graduates' readiness, employability, professional skills, and alignment with labor market expectations."
    ],
    [
        "evaluation_area" => "Student Support Services",
        "evaluation_sources" => "Students, academic advisors, student affairs unit, program leadership",
        "evaluation_methods" => "Student satisfaction survey, academic advising records, counseling reports, support service utilization reports",
        "evaluation_time" => "Each semester / Annually",
        "notes" => "Used to evaluate academic advising, orientation, counseling, and support for students with special needs."
    ],
    [
        "evaluation_area" => "Learning Resources",
        "evaluation_sources" => "Students, teaching staff, library reports, e-learning reports, course coordinators",
        "evaluation_methods" => "Learning resources survey, library usage reports, LMS analytics, course report feedback",
        "evaluation_time" => "Annually",
        "notes" => "Used to evaluate adequacy and accessibility of textbooks, references, databases, LMS resources, and digital learning materials."
    ],
    [
        "evaluation_area" => "Facilities and Equipment",
        "evaluation_sources" => "Students, teaching staff, laboratory supervisors, facilities unit, program leadership",
        "evaluation_methods" => "Facilities satisfaction survey, inspection reports, maintenance reports, classroom and laboratory readiness review",
        "evaluation_time" => "Annually / Before each semester",
        "notes" => "Used to evaluate adequacy, safety, accessibility, and readiness of classrooms, laboratories, IT resources, and equipment."
    ],
    [
        "evaluation_area" => "Teaching Staff Performance",
        "evaluation_sources" => "Students, department leadership, peer reviewers, faculty members, quality committee",
        "evaluation_methods" => "Student evaluation of teaching, peer review, annual faculty performance review, professional development records",
        "evaluation_time" => "Each semester / Annually",
        "notes" => "Used to evaluate teaching quality, assessment practices, academic advising, research activity, and professional development."
    ],
    [
        "evaluation_area" => "Employer Feedback",
        "evaluation_sources" => "Employers, internship sites, field supervisors, advisory committee",
        "evaluation_methods" => "Employer survey, field supervisor feedback, advisory committee discussion, labor market feedback",
        "evaluation_time" => "Annually",
        "notes" => "Used to evaluate graduate competency, workplace readiness, and relevance of the curriculum to professional practice."
    ],
    [
        "evaluation_area" => "Alumni Feedback",
        "evaluation_sources" => "Program graduates, alumni database, alumni surveys",
        "evaluation_methods" => "Alumni survey, employment tracking, postgraduate enrollment tracking, qualitative feedback",
        "evaluation_time" => "Annually",
        "notes" => "Used to evaluate graduate employment, postgraduate progression, and perceived usefulness of the program."
    ],
    [
        "evaluation_area" => "Program KPIs",
        "evaluation_sources" => "Program KPI dashboard, course reports, surveys, official student records, faculty records, alumni/employer data",
        "evaluation_methods" => "KPI calculation, target comparison, benchmarking, trend analysis, improvement action tracking",
        "evaluation_time" => "Annually",
        "notes" => "Used to monitor performance against NCAAA and program-specific KPI targets."
    ],
    [
        "evaluation_area" => "Quality Assurance System",
        "evaluation_sources" => "Quality committee, program quality coordinator, internal reviewers, accreditation evidence repository",
        "evaluation_methods" => "Internal audit, evidence review, SSR preparation, action plan follow-up, committee reporting",
        "evaluation_time" => "Annually / According to QA cycle",
        "notes" => "Used to evaluate effectiveness of the quality assurance system and continuous improvement process."
    ]
];

try {
    $conn->beginTransaction();

    $deleteStmt = $conn->prepare("
        DELETE FROM program_specification_evaluation_matrix
        WHERE program_specification_id = :program_specification_id
    ");
    $deleteStmt->execute([
        ":program_specification_id" => $spec_id
    ]);

    $insertStmt = $conn->prepare("
        INSERT INTO program_specification_evaluation_matrix
        (
            program_specification_id,
            evaluation_area,
            evaluation_sources,
            evaluation_methods,
            evaluation_time,
            notes
        )
        VALUES
        (
            :program_specification_id,
            :evaluation_area,
            :evaluation_sources,
            :evaluation_methods,
            :evaluation_time,
            :notes
        )
    ");

    $inserted = 0;

    foreach ($defaultRows as $row) {
        $insertStmt->execute([
            ":program_specification_id" => $spec_id,
            ":evaluation_area" => $row["evaluation_area"],
            ":evaluation_sources" => $row["evaluation_sources"],
            ":evaluation_methods" => $row["evaluation_methods"],
            ":evaluation_time" => $row["evaluation_time"],
            ":notes" => $row["notes"]
        ]);

        $inserted++;
    }

    $conn->commit();

    logActivitySafe(
        $conn,
        "Import",
        "Program Specification Evaluation Matrix",
        $spec_id,
        "Default HPE evaluation matrix imported. Rows inserted: " . $inserted
    );

    header("Location: manage_program_specification_evaluation.php?id=" . $spec_id);
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    die("Import failed: " . safeText($e->getMessage()));
}
