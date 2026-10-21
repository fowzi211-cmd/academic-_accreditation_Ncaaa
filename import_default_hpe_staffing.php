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

if (!tableExists($conn, "program_specification_staffing_needs")) {
    die("program_specification_staffing_needs table does not exist.");
}

/*
    Safety upgrade for older tables.
*/
addColumnIfMissing($conn, "program_specification_staffing_needs", "academic_rank", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_staffing_needs", "general_specialty", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_staffing_needs", "specific_specialty", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_staffing_needs", "special_requirements", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_staffing_needs", "required_male", "INT DEFAULT 0");
addColumnIfMissing($conn, "program_specification_staffing_needs", "required_female", "INT DEFAULT 0");
addColumnIfMissing($conn, "program_specification_staffing_needs", "required_total", "INT DEFAULT 0");
addColumnIfMissing($conn, "program_specification_staffing_needs", "notes", "TEXT NULL");

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

/*
    Default HPE Staffing Needs

    These are reasonable editable defaults for program specification documentation.
    Adjust numbers later based on actual department staffing, male/female sections,
    workload, and university approval.
*/

$defaultRows = [
    [
        "academic_rank" => "Professor",
        "general_specialty" => "Public Health, Health Promotion, Community Health, Epidemiology, Health Education, or related fields.",
        "specific_specialty" => "Senior academic expertise in health promotion, public health leadership, health behavior, community health, epidemiology, or program evaluation.",
        "special_requirements" => "PhD degree, strong research record, postgraduate supervision experience, leadership in curriculum and quality assurance preferred.",
        "required_male" => 1,
        "required_female" => 1,
        "required_total" => 2,
        "notes" => "Supports senior academic leadership, research, curriculum development, and program governance."
    ],
    [
        "academic_rank" => "Associate Professor",
        "general_specialty" => "Public Health, Health Promotion, Health Education, Epidemiology, Environmental Health, Health Services Management, or related fields.",
        "specific_specialty" => "Health promotion planning, public health research, behavioral science, health education, maternal and child health, epidemiology, or health-promoting settings.",
        "special_requirements" => "PhD degree, teaching experience, research publications, contribution to program committees and quality assurance.",
        "required_male" => 2,
        "required_female" => 2,
        "required_total" => 4,
        "notes" => "Supports advanced teaching, research supervision, academic advising, and quality improvement activities."
    ],
    [
        "academic_rank" => "Assistant Professor",
        "general_specialty" => "Public Health, Health Promotion, Health Education, Community Health, Epidemiology, Biostatistics, Environmental Health, or related fields.",
        "specific_specialty" => "Health promotion theories, program planning, health communication, needs assessment, research methods, epidemiology, biostatistics, community interventions, and evaluation.",
        "special_requirements" => "PhD degree preferred/required according to university policy, teaching competency, research activity, and ability to supervise student projects.",
        "required_male" => 5,
        "required_female" => 5,
        "required_total" => 10,
        "notes" => "Core teaching staff for most specialized program courses."
    ],
    [
        "academic_rank" => "Lecturer",
        "general_specialty" => "Public Health, Health Promotion, Health Education, Community Health, Biostatistics, or related fields.",
        "specific_specialty" => "Applied health promotion, health education practice, communication skills, field training support, and practical course delivery.",
        "special_requirements" => "Master's degree at minimum, teaching ability, practical public health experience preferred.",
        "required_male" => 2,
        "required_female" => 2,
        "required_total" => 4,
        "notes" => "Supports teaching, practical sessions, tutorials, academic advising, and field activities."
    ],
    [
        "academic_rank" => "Teaching Assistant",
        "general_specialty" => "Public Health, Health Promotion, Health Education, Community Health, or related fields.",
        "specific_specialty" => "Tutorial support, practical activities, student follow-up, course coordination support, and field activity assistance.",
        "special_requirements" => "Bachelor's degree or higher according to university policy; strong academic record and communication skills.",
        "required_male" => 2,
        "required_female" => 2,
        "required_total" => 4,
        "notes" => "Supports tutorials, practical learning, student support, and course administration."
    ],
    [
        "academic_rank" => "Laboratory Technician",
        "general_specialty" => "Public Health laboratory support, health education materials, IT-supported learning, or related technical support.",
        "specific_specialty" => "Laboratory preparation, learning resources, educational media, computer lab support, and field equipment support.",
        "special_requirements" => "Relevant diploma or bachelor's degree; technical competency in laboratory or educational technology support.",
        "required_male" => 1,
        "required_female" => 1,
        "required_total" => 2,
        "notes" => "Supports applied sessions, laboratory readiness, and educational equipment."
    ],
    [
        "academic_rank" => "Program Coordinator",
        "general_specialty" => "Academic administration, program coordination, quality documentation, or related administrative support.",
        "specific_specialty" => "Program scheduling, committee documentation, student records coordination, reports, and evidence follow-up.",
        "special_requirements" => "Administrative experience, strong documentation skills, knowledge of academic processes preferred.",
        "required_male" => 1,
        "required_female" => 1,
        "required_total" => 2,
        "notes" => "Supports program administration and coordination across male and female sections."
    ],
    [
        "academic_rank" => "Quality Coordinator",
        "general_specialty" => "Quality assurance, accreditation, academic quality systems, or program evaluation.",
        "specific_specialty" => "NCAAA documentation, KPI monitoring, course reports, program reports, evidence management, and continuous improvement.",
        "special_requirements" => "Experience in academic quality assurance and accreditation documentation preferred.",
        "required_male" => 1,
        "required_female" => 1,
        "required_total" => 2,
        "notes" => "Supports program accreditation, quality assurance, KPI tracking, and evidence management."
    ],
    [
        "academic_rank" => "Academic Advisor",
        "general_specialty" => "Academic advising and student support.",
        "specific_specialty" => "Student academic advising, progression monitoring, at-risk student support, orientation, and referral to support services.",
        "special_requirements" => "Faculty member or trained academic advisor according to university advising policy.",
        "required_male" => 2,
        "required_female" => 2,
        "required_total" => 4,
        "notes" => "Can be assigned from existing faculty; included here to document advising coverage needs."
    ],
    [
        "academic_rank" => "Administrative Staff",
        "general_specialty" => "Department and program administration.",
        "specific_specialty" => "Records, communication, scheduling, meeting coordination, student service support, and report documentation.",
        "special_requirements" => "Administrative competency, computer skills, records management, and confidentiality.",
        "required_male" => 1,
        "required_female" => 1,
        "required_total" => 2,
        "notes" => "Supports daily program operation and documentation."
    ]
];

try {
    $conn->beginTransaction();

    $deleteStmt = $conn->prepare("
        DELETE FROM program_specification_staffing_needs
        WHERE program_specification_id = :program_specification_id
    ");
    $deleteStmt->execute([
        ":program_specification_id" => $spec_id
    ]);

    $insertStmt = $conn->prepare("
        INSERT INTO program_specification_staffing_needs
        (
            program_specification_id,
            academic_rank,
            general_specialty,
            specific_specialty,
            special_requirements,
            required_male,
            required_female,
            required_total,
            notes
        )
        VALUES
        (
            :program_specification_id,
            :academic_rank,
            :general_specialty,
            :specific_specialty,
            :special_requirements,
            :required_male,
            :required_female,
            :required_total,
            :notes
        )
    ");

    $inserted = 0;

    foreach ($defaultRows as $row) {
        $insertStmt->execute([
            ":program_specification_id" => $spec_id,
            ":academic_rank" => $row["academic_rank"],
            ":general_specialty" => $row["general_specialty"],
            ":specific_specialty" => $row["specific_specialty"],
            ":special_requirements" => $row["special_requirements"],
            ":required_male" => $row["required_male"],
            ":required_female" => $row["required_female"],
            ":required_total" => $row["required_total"],
            ":notes" => $row["notes"]
        ]);

        $inserted++;
    }

    $conn->commit();

    logActivitySafe(
        $conn,
        "Import",
        "Program Specification Staffing Needs",
        $spec_id,
        "Default HPE staffing needs imported. Rows inserted: " . $inserted
    );

    header("Location: manage_program_specification_staffing.php?id=" . $spec_id);
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    die("Import failed: " . safeText($e->getMessage()));
}
