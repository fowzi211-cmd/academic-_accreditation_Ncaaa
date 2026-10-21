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

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "program_specification_kpis")) {
    die("program_specification_kpis table does not exist.");
}

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

$defaultKPIs = [
    [
        "kpi_no" => 1,
        "kpi_code" => "KPI-P-01",
        "kpi_name" => "Students' Evaluation of Quality of learning experience in the program",
        "target_value" => "≥ 4.5 / 5",
        "measurement_methods" => "Direct structured survey with open-ended questions for improvement",
        "measurement_time" => "End of each semester",
        "kpi_type" => "Main KPI"
    ],
    [
        "kpi_no" => 2,
        "kpi_code" => "KPI-P-02",
        "kpi_name" => "Students' evaluation of the quality of the courses",
        "target_value" => "≥ 4.5 / 5",
        "measurement_methods" => "Direct structured survey with open-ended questions for improvement",
        "measurement_time" => "End of each semester",
        "kpi_type" => "Main KPI"
    ],
    [
        "kpi_no" => 3,
        "kpi_code" => "KPI-P-03",
        "kpi_name" => "Completion rate",
        "target_value" => "≥ 90%",
        "measurement_methods" => "Analysis of official declared results",
        "measurement_time" => "Annually",
        "kpi_type" => "Main KPI"
    ],
    [
        "kpi_no" => 4,
        "kpi_code" => "KPI-P-04",
        "kpi_name" => "First-year students' retention rate",
        "target_value" => "≥ 90%",
        "measurement_methods" => "Analysis of enrollment and withdrawal data",
        "measurement_time" => "Annually",
        "kpi_type" => "Main KPI"
    ],
    [
        "kpi_no" => 5,
        "kpi_code" => "KPI-P-05",
        "kpi_name" => "Students' performance in the professional and/or national examinations",
        "target_value" => "≥ 80% passing",
        "measurement_methods" => "Official exam results",
        "measurement_time" => "After each exam cycle",
        "kpi_type" => "Main KPI"
    ],
    [
        "kpi_no" => 6,
        "kpi_code" => "KPI-P-06",
        "kpi_name" => "Graduates' employability and enrolment in postgraduate programs",
        "target_value" => "≥ 50% employed / ≥ 20% postgraduate enrollment",
        "measurement_methods" => "Direct alumni survey",
        "measurement_time" => "Annually",
        "kpi_type" => "Main KPI"
    ],
    [
        "kpi_no" => 7,
        "kpi_code" => "KPI-P-07",
        "kpi_name" => "Employers' evaluation of the program graduates' proficiency",
        "target_value" => "≥ 4 / 5",
        "measurement_methods" => "Survey of employers and internship supervisors",
        "measurement_time" => "Annually",
        "kpi_type" => "Main KPI"
    ],
    [
        "kpi_no" => 8,
        "kpi_code" => "KPI-P-08",
        "kpi_name" => "Ratio of students to teaching staff",
        "target_value" => "15:1",
        "measurement_methods" => "Official workload and staffing data",
        "measurement_time" => "Annually",
        "kpi_type" => "Main KPI"
    ],
    [
        "kpi_no" => 9,
        "kpi_code" => "KPI-P-09",
        "kpi_name" => "Percentage of publications of faculty members",
        "target_value" => "≥ 70%",
        "measurement_methods" => "Departmental data on publications",
        "measurement_time" => "Annually",
        "kpi_type" => "Main KPI"
    ],
    [
        "kpi_no" => 10,
        "kpi_code" => "KPI-P-10",
        "kpi_name" => "Rate of published research per faculty member",
        "target_value" => "≥ 1 publication per faculty per year",
        "measurement_methods" => "Departmental data on publications",
        "measurement_time" => "Annually",
        "kpi_type" => "Main KPI"
    ],
    [
        "kpi_no" => 11,
        "kpi_code" => "KPI-P-11",
        "kpi_name" => "Citations rate in refereed journals per faculty member",
        "target_value" => "≥ 1 citation per faculty per year",
        "measurement_methods" => "Departmental data on citations. Google Scholar and/or ResearchGate profile will be considered as citation references.",
        "measurement_time" => "Annually",
        "kpi_type" => "Main KPI"
    ],

    [
        "kpi_no" => 1,
        "kpi_code" => "S-KPI-P-01",
        "kpi_name" => "Percentage of program faculty workload position-wise and productivity based on teaching hours",
        "target_value" => "100%",
        "measurement_methods" => "Direct: Actual teaching workload ÷ prescribed workload × 100",
        "measurement_time" => "End of each semester",
        "kpi_type" => "Sub-KPI"
    ],
    [
        "kpi_no" => 2,
        "kpi_code" => "S-KPI-P-02",
        "kpi_name" => "Percentage of program course reports and course files completed on time",
        "target_value" => "100%",
        "measurement_methods" => "Direct: Number of course reports received ÷ number of courses offered × 100",
        "measurement_time" => "End of each semester",
        "kpi_type" => "Sub-KPI"
    ],
    [
        "kpi_no" => 3,
        "kpi_code" => "S-KPI-P-03",
        "kpi_name" => "Percentage of program faculty assigned as student advisors",
        "target_value" => "100%",
        "measurement_methods" => "Direct: Number of faculty advisors ÷ total faculty × 100",
        "measurement_time" => "Annually",
        "kpi_type" => "Sub-KPI"
    ],
    [
        "kpi_no" => 4,
        "kpi_code" => "S-KPI-P-04",
        "kpi_name" => "Percentage of program faculty involved as student research supervisors",
        "target_value" => "100%",
        "measurement_methods" => "Direct: Number of faculty supervising research ÷ total faculty × 100",
        "measurement_time" => "Annually",
        "kpi_type" => "Sub-KPI"
    ],
    [
        "kpi_no" => 5,
        "kpi_code" => "S-KPI-P-05",
        "kpi_name" => "Percentage of students participating in community service during study",
        "target_value" => "≥ 20%",
        "measurement_methods" => "Direct: Number of students participating ÷ total 2nd–4th year students × 100",
        "measurement_time" => "Annually",
        "kpi_type" => "Sub-KPI"
    ]
];

try {
    $conn->beginTransaction();

    $checkStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM program_specification_kpis
        WHERE program_specification_id = :program_specification_id
          AND kpi_code = :kpi_code
    ");

    $insertStmt = $conn->prepare("
        INSERT INTO program_specification_kpis
        (
            program_specification_id,
            kpi_no,
            kpi_code,
            kpi_name,
            target_value,
            measurement_methods,
            measurement_time,
            kpi_type,
            notes
        )
        VALUES
        (
            :program_specification_id,
            :kpi_no,
            :kpi_code,
            :kpi_name,
            :target_value,
            :measurement_methods,
            :measurement_time,
            :kpi_type,
            :notes
        )
    ");

    $inserted = 0;
    $skipped = 0;

    foreach ($defaultKPIs as $kpi) {
        $checkStmt->execute([
            ":program_specification_id" => $spec_id,
            ":kpi_code" => $kpi["kpi_code"]
        ]);

        $exists = (int)$checkStmt->fetchColumn();

        if ($exists > 0) {
            $skipped++;
            continue;
        }

        $insertStmt->execute([
            ":program_specification_id" => $spec_id,
            ":kpi_no" => $kpi["kpi_no"],
            ":kpi_code" => $kpi["kpi_code"],
            ":kpi_name" => $kpi["kpi_name"],
            ":target_value" => $kpi["target_value"],
            ":measurement_methods" => $kpi["measurement_methods"],
            ":measurement_time" => $kpi["measurement_time"],
            ":kpi_type" => $kpi["kpi_type"],
            ":notes" => "Default HPE program specification KPI imported."
        ]);

        $inserted++;
    }

    $conn->commit();

    logActivitySafe(
        $conn,
        "Import",
        "Program Specification KPIs",
        $spec_id,
        "Default HPE KPIs imported. Inserted: " . $inserted . " | Skipped: " . $skipped
    );

    header("Location: manage_program_specification_kpis.php?id=" . $spec_id);
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    die("Import failed: " . safeText($e->getMessage()));
}