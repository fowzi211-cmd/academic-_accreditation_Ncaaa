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

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

function normalizeCode($value) {
    return strtoupper(trim((string)($value ?? "")));
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "program_specification_plos")) {
    die("program_specification_plos table does not exist.");
}

if (!tableExists($conn, "program_specification_courses")) {
    die("program_specification_courses table does not exist.");
}

if (!tableExists($conn, "program_specification_plo_course_mapping")) {
    die("program_specification_plo_course_mapping table does not exist.");
}

$requiredMappingColumns = [
    "program_specification_id",
    "course_code",
    "plo_code",
    "performance_level"
];

foreach ($requiredMappingColumns as $columnName) {
    if (!columnExists($conn, "program_specification_plo_course_mapping", $columnName)) {
        die("Missing column in program_specification_plo_course_mapping: " . safeText($columnName));
    }
}

$hasCourseIdColumn = columnExists($conn, "program_specification_plo_course_mapping", "course_id");
$hasNotesColumn = columnExists($conn, "program_specification_plo_course_mapping", "notes");

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

$plosStmt = $conn->prepare("
    SELECT *
    FROM program_specification_plos
    WHERE program_specification_id = :id
");
$plosStmt->execute([":id" => $spec_id]);
$ploRows = $plosStmt->fetchAll(PDO::FETCH_ASSOC);

$existingPLOs = [];
foreach ($ploRows as $plo) {
    $code = normalizeCode($plo["plo_code"] ?? "");
    if ($code !== "") {
        $existingPLOs[$code] = true;
    }
}

$coursesStmt = $conn->prepare("
    SELECT *
    FROM program_specification_courses
    WHERE program_specification_id = :id
");
$coursesStmt->execute([":id" => $spec_id]);
$courseRows = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

$existingCourses = [];
foreach ($courseRows as $course) {
    $code = normalizeCode($course["course_code"] ?? "");
    if ($code !== "") {
        $existingCourses[$code] = [
            "id" => (int)($course["id"] ?? 0),
            "course_title" => $course["course_title"] ?? ""
        ];
    }
}

/*
    Default HPE PLO-course mapping based on the real HPE Program Specification matrix:
    I = Introduced
    P = Practiced
    M = Mastered
*/
$defaultMapping = [
    "BIO1001" => [
        "K1" => "I", "K2" => "I", "S1" => "I", "S4" => "I", "V1" => "I", "V2" => "I"
    ],
    "CHM1110" => [
        "K1" => "I", "K2" => "I", "S1" => "I", "S4" => "I", "V1" => "I", "V2" => "I"
    ],
    "HP1001" => [
        "K1" => "I", "K2" => "I", "S1" => "I", "S2" => "I", "S4" => "I", "V1" => "I"
    ],
    "MED1404" => [
        "K1" => "I", "K2" => "I", "S1" => "I", "S2" => "I", "S4" => "I", "V1" => "I"
    ],
    "HP1002" => [
        "K1" => "I", "K2" => "I", "S1" => "I", "S2" => "I", "S4" => "I", "S5" => "I", "V1" => "I", "V2" => "I"
    ],
    "HSM1212" => [
        "K1" => "I", "K2" => "I", "S2" => "I", "S3" => "I", "V1" => "I", "V2" => "I"
    ],
    "HP2003" => [
        "K1" => "I", "K2" => "I", "S1" => "I", "S4" => "I", "V1" => "I", "V2" => "I"
    ],
    "HP2004" => [
        "K1" => "I", "K2" => "I", "S1" => "I", "S2" => "I", "V2" => "I"
    ],
    "HP2005" => [
        "K1" => "I", "K2" => "I", "S1" => "I", "S4" => "I", "S5" => "I", "V2" => "I"
    ],
    "BIOM2006" => [
        "K1" => "I", "K2" => "I", "S3" => "I", "V1" => "I"
    ],
    "EPI2004" => [
        "K1" => "I", "K2" => "I", "S3" => "I", "S4" => "I", "S5" => "P", "V1" => "I", "V2" => "I"
    ],
    "HP2007" => [
        "K1" => "I", "K2" => "I", "S1" => "I", "S2" => "P", "S5" => "P", "V1" => "P"
    ],
    "EH2001" => [
        "K1" => "I", "S1" => "I", "S3" => "P", "S4" => "P", "V1" => "P", "V2" => "I"
    ],
    "HP2008" => [
        "K1" => "I", "S4" => "P", "V1" => "I", "V2" => "I"
    ],
    "HP2009" => [
        "K1" => "P", "K2" => "I", "S1" => "I", "S3" => "I", "V1" => "P", "V2" => "P"
    ],
    "EPI3019" => [
        "K1" => "P", "S1" => "P", "S4" => "P", "S5" => "P", "V1" => "P", "V2" => "P"
    ],
    "HP3010" => [
        "K1" => "P", "K2" => "P", "S1" => "P", "S2" => "P", "S3" => "P", "S4" => "P", "V1" => "P", "V2" => "P"
    ],
    "HP3011" => [
        "K1" => "P", "K2" => "P", "S1" => "P", "S3" => "P", "S4" => "P", "S5" => "P", "V1" => "P", "V2" => "P"
    ],
    "HP3012" => [
        "K1" => "P", "K2" => "P", "S1" => "P", "S4" => "P", "V1" => "P", "V2" => "P"
    ],
    "HP3013" => [
        "K1" => "P", "K2" => "P", "S1" => "P", "S4" => "P", "S5" => "P", "V1" => "P"
    ],
    "MTH3017" => [
        "K1" => "M", "K2" => "P", "S3" => "M", "S4" => "P", "V1" => "P", "V2" => "P"
    ],
    "HP3015" => [
        "K1" => "P", "K2" => "P", "S1" => "P", "S3" => "P", "V1" => "P", "V2" => "P"
    ],
    "EH3006" => [
        "K1" => "P", "K2" => "P", "S1" => "P", "S2" => "P", "V1" => "P", "V2" => "P"
    ],
    "HSM3433" => [
        "K1" => "P", "K2" => "P", "S3" => "P", "S4" => "P", "V1" => "P"
    ],
    "HP3017" => [
        "K1" => "P", "S1" => "P", "S3" => "P", "S5" => "M", "V1" => "M"
    ],
    "HP3018" => [
        "K1" => "P", "S1" => "P", "S3" => "M", "S5" => "M", "V1" => "P"
    ],
    "HSM2649" => [
        "K1" => "P", "K2" => "P", "S3" => "P", "S4" => "P", "V1" => "P"
    ],
    "HP4019" => [
        "K1" => "M", "K2" => "M", "S1" => "M", "S4" => "M", "V1" => "M"
    ],
    "HP4020" => [
        "K1" => "M", "K2" => "M", "S1" => "M", "S3" => "M", "S4" => "M", "V1" => "M", "V2" => "M"
    ],
    "HP4021" => [
        "K1" => "M", "K2" => "M", "S1" => "M", "S2" => "M", "S3" => "M", "S4" => "M", "V1" => "M", "V2" => "M"
    ],
    "HP4022" => [
        "K1" => "M", "K2" => "M", "S1" => "M", "S3" => "M", "S4" => "M", "V1" => "M", "V2" => "M"
    ],
    "HP4023" => [
        "K1" => "M", "K2" => "M", "S1" => "M", "S2" => "M", "S3" => "M", "S4" => "M", "V1" => "M", "V2" => "M"
    ],
    "HP4024" => [
        "K1" => "M", "S1" => "M", "S2" => "M", "S3" => "M", "S5" => "M", "V1" => "M", "V2" => "M"
    ],
    "HP4025" => [
        "K1" => "M", "K2" => "M", "S1" => "M", "S2" => "M", "S3" => "M", "S4" => "M", "S5" => "M", "V1" => "M", "V2" => "M"
    ],
    "HP4026" => [
        "K2" => "M", "S1" => "M", "S3" => "M", "S5" => "M", "V1" => "M"
    ],
    "HP4027" => [
        "K1" => "M", "K2" => "M", "S1" => "M", "S3" => "M", "S4" => "M", "V1" => "M", "V2" => "M"
    ],
    "HP4028" => [
        "K1" => "M", "K2" => "M", "S1" => "M", "S2" => "M", "S4" => "M", "S5" => "M", "V1" => "M", "V2" => "M"
    ]
];

try {
    $conn->beginTransaction();

    $deleteStmt = $conn->prepare("
        DELETE FROM program_specification_plo_course_mapping
        WHERE program_specification_id = :id
    ");
    $deleteStmt->execute([":id" => $spec_id]);

    $columns = [
        "program_specification_id",
        "course_code",
        "plo_code",
        "performance_level"
    ];

    $placeholders = [
        ":program_specification_id",
        ":course_code",
        ":plo_code",
        ":performance_level"
    ];

    if ($hasCourseIdColumn) {
        $columns[] = "course_id";
        $placeholders[] = ":course_id";
    }

    if ($hasNotesColumn) {
        $columns[] = "notes";
        $placeholders[] = ":notes";
    }

    $insertSql = "
        INSERT INTO program_specification_plo_course_mapping
        (" . implode(", ", $columns) . ")
        VALUES
        (" . implode(", ", $placeholders) . ")
    ";

    $insertStmt = $conn->prepare($insertSql);

    $inserted = 0;
    $skippedMissingCourse = 0;
    $skippedMissingPlo = 0;

    foreach ($defaultMapping as $courseCode => $ploMap) {
        $normalizedCourseCode = normalizeCode($courseCode);

        if (!isset($existingCourses[$normalizedCourseCode])) {
            $skippedMissingCourse += count($ploMap);
            continue;
        }

        foreach ($ploMap as $ploCode => $level) {
            $normalizedPloCode = normalizeCode($ploCode);

            if (!isset($existingPLOs[$normalizedPloCode])) {
                $skippedMissingPlo++;
                continue;
            }

            $params = [
                ":program_specification_id" => $spec_id,
                ":course_code" => $normalizedCourseCode,
                ":plo_code" => $normalizedPloCode,
                ":performance_level" => $level
            ];

            if ($hasCourseIdColumn) {
                $params[":course_id"] = $existingCourses[$normalizedCourseCode]["id"];
            }

            if ($hasNotesColumn) {
                $params[":notes"] = "Default HPE PLO-course mapping imported.";
            }

            $insertStmt->execute($params);
            $inserted++;
        }
    }

    $conn->commit();

    logActivitySafe(
        $conn,
        "Import",
        "Program Specification PLO-Course Mapping",
        $spec_id,
        "Default HPE PLO-course mapping imported. Inserted: " . $inserted .
        " | Skipped missing course: " . $skippedMissingCourse .
        " | Skipped missing PLO: " . $skippedMissingPlo
    );

    header("Location: program_specification_plo_mapping.php?id=" . $spec_id . "&saved=1");
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    die("Import failed: " . safeText($e->getMessage()));
}