<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

$program_specification_id = isset($_GET["program_specification_id"]) && is_numeric($_GET["program_specification_id"])
    ? (int)$_GET["program_specification_id"]
    : 3;

$file_id = isset($_GET["file_id"]) && is_numeric($_GET["file_id"])
    ? (int)$_GET["file_id"]
    : null;

$error = "";
$success = "";
$importSummary = [];

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

function nullIfEmpty($value) {
    $value = trim((string)$value);
    return $value === "" ? null : $value;
}

function numberFromText($text) {
    $text = trim((string)$text);
    $text = str_replace(["%", ","], ["", "."], $text);

    if (preg_match('/[-+]?\d+(\.\d+)?/', $text, $m)) {
        return (float)$m[0];
    }

    return 0;
}

function intFromText($text) {
    $text = trim((string)$text);

    if (preg_match('/\d+/', $text, $m)) {
        return (int)$m[0];
    }

    return 0;
}

function cleanText($text) {
    $text = (string)$text;
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace("/[ \t]+/", " ", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

function normalizeLineText($text) {
    $text = cleanText($text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

function getDocxXml($filePath, $xmlPath) {
    if (!class_exists("ZipArchive")) {
        throw new Exception("PHP ZipArchive extension is not enabled. Enable zip extension in php.ini.");
    }

    $zip = new ZipArchive();

    if ($zip->open($filePath) !== true) {
        throw new Exception("Could not open DOCX file.");
    }

    $xml = $zip->getFromName($xmlPath);
    $zip->close();

    if ($xml === false) {
        throw new Exception("Could not read $xmlPath from DOCX file.");
    }

    return $xml;
}

function extractDocxText($filePath) {
    $xml = getDocxXml($filePath, "word/document.xml");

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($xml);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace("w", "http://schemas.openxmlformats.org/wordprocessingml/2006/main");

    $paragraphs = [];
    $nodes = $xpath->query("//w:body//w:p");

    foreach ($nodes as $p) {
        $parts = [];
        $texts = $xpath->query(".//w:t", $p);

        foreach ($texts as $t) {
            $parts[] = $t->nodeValue;
        }

        $line = normalizeLineText(implode("", $parts));

        if ($line !== "") {
            $paragraphs[] = $line;
        }
    }

    return cleanText(implode("\n", $paragraphs));
}

function extractDocxTables($filePath) {
    $xml = getDocxXml($filePath, "word/document.xml");

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($xml);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace("w", "http://schemas.openxmlformats.org/wordprocessingml/2006/main");

    $tables = [];
    $tblNodes = $xpath->query("//w:tbl");

    foreach ($tblNodes as $tbl) {
        $table = [];
        $rowNodes = $xpath->query("./w:tr", $tbl);

        foreach ($rowNodes as $tr) {
            $row = [];
            $cellNodes = $xpath->query("./w:tc", $tr);

            foreach ($cellNodes as $tc) {
                $paragraphs = [];
                $pNodes = $xpath->query(".//w:p", $tc);

                foreach ($pNodes as $p) {
                    $parts = [];
                    $texts = $xpath->query(".//w:t", $p);

                    foreach ($texts as $t) {
                        $parts[] = $t->nodeValue;
                    }

                    $line = normalizeLineText(implode("", $parts));

                    if ($line !== "") {
                        $paragraphs[] = $line;
                    }
                }

                $row[] = cleanText(implode("\n", $paragraphs));
            }

            $hasValue = false;

            foreach ($row as $cell) {
                if (trim($cell) !== "") {
                    $hasValue = true;
                    break;
                }
            }

            if ($hasValue) {
                $table[] = $row;
            }
        }

        if (count($table) > 0) {
            $tables[] = $table;
        }
    }

    return $tables;
}

function findValueAfterLabel($text, $label) {
    $lines = explode("\n", $text);
    $labelLower = strtolower($label);

    for ($i = 0; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        $lineLower = strtolower($line);

        if (strpos($lineLower, $labelLower) !== false) {
            $pos = stripos($line, $label);

            if ($pos !== false) {
                $after = trim(substr($line, $pos + strlen($label)));
                $after = trim($after, " :\t");

                if ($after !== "") {
                    return $after;
                }
            }

            for ($j = $i + 1; $j < min($i + 4, count($lines)); $j++) {
                $candidate = trim($lines[$j]);

                if ($candidate !== "") {
                    return $candidate;
                }
            }
        }
    }

    return "";
}

/* Required tables */
$requiredTables = [
    "program_specifications",
    "course_specifications",
    "course_specification_files",
    "course_specification_clos",
    "course_specification_topics",
    "course_specification_assessments",
    "course_specification_resources",
    "course_specification_facilities",
    "course_specification_quality",
    "course_specification_approval"
];

foreach ($requiredTables as $tableName) {
    if (!tableExists($conn, $tableName)) {
        die($tableName . " table does not exist. Run course_specification_schema_update.php first.");
    }
}

/* Safety columns */
addColumnIfMissing($conn, "course_specifications", "program_specification_id", "INT NULL");
addColumnIfMissing($conn, "course_specifications", "program_course_id", "INT NULL");
addColumnIfMissing($conn, "course_specifications", "course_code", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "course_specifications", "course_title", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "course_specifications", "program_name", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "course_specifications", "department_name", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "course_specifications", "college_name", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "course_specifications", "institution_name", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "course_specifications", "version_no", "VARCHAR(50) NULL");
addColumnIfMissing($conn, "course_specifications", "last_revision_date_text", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "course_specifications", "credit_hours", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "course_specifications", "theoretical_hours", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "course_specifications", "course_level_year", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "course_specifications", "course_type_scope", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "course_specifications", "requirement_type", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "course_specifications", "course_general_description", "TEXT NULL");
addColumnIfMissing($conn, "course_specifications", "pre_requisites", "TEXT NULL");
addColumnIfMissing($conn, "course_specifications", "co_requisites", "TEXT NULL");
addColumnIfMissing($conn, "course_specifications", "course_main_objectives", "TEXT NULL");
addColumnIfMissing($conn, "course_specifications", "contact_lectures", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "course_specifications", "contact_laboratory", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "course_specifications", "contact_field", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "course_specifications", "contact_tutorial", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "course_specifications", "contact_others", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "course_specifications", "contact_total", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "course_specifications", "status", "VARCHAR(100) DEFAULT 'Draft'");
addColumnIfMissing($conn, "course_specifications", "approval_status", "VARCHAR(100) DEFAULT 'Draft'");
addColumnIfMissing($conn, "course_specifications", "notes", "TEXT NULL");

addColumnIfMissing($conn, "course_specification_resources", "resource_type", "VARCHAR(150) NULL");
addColumnIfMissing($conn, "course_specification_resources", "resource_category", "VARCHAR(150) NULL");
addColumnIfMissing($conn, "course_specification_resources", "resource_details", "TEXT NULL");
addColumnIfMissing($conn, "course_specification_resources", "display_order", "INT DEFAULT 0");

addColumnIfMissing($conn, "course_specification_facilities", "facility_item", "VARCHAR(150) NULL");
addColumnIfMissing($conn, "course_specification_facilities", "facility_category", "VARCHAR(150) NULL");
addColumnIfMissing($conn, "course_specification_facilities", "required_resources", "TEXT NULL");
addColumnIfMissing($conn, "course_specification_facilities", "display_order", "INT DEFAULT 0");

/* Load program specification */
$programStmt = $conn->prepare("
    SELECT *
    FROM program_specifications
    WHERE id = :id
    LIMIT 1
");
$programStmt->execute([":id" => $program_specification_id]);
$programSpec = $programStmt->fetch(PDO::FETCH_ASSOC);

if (!$programSpec) {
    die("Program specification not found.");
}

/* Load uploaded files */
$filesStmt = $conn->prepare("
    SELECT f.*, cs.course_title AS linked_course_title
    FROM course_specification_files f
    LEFT JOIN course_specifications cs ON f.course_specification_id = cs.id
    WHERE f.program_specification_id = :program_specification_id
    ORDER BY f.upload_date DESC, f.id DESC
");
$filesStmt->execute([":program_specification_id" => $program_specification_id]);
$uploadedFiles = $filesStmt->fetchAll(PDO::FETCH_ASSOC);

/* Import action */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $file_id = isset($_POST["file_id"]) && is_numeric($_POST["file_id"]) ? (int)$_POST["file_id"] : null;

    if ($file_id === null) {
        $error = "Select a DOCX file to import.";
    } else {
        $fileStmt = $conn->prepare("
            SELECT *
            FROM course_specification_files
            WHERE id = :id
              AND program_specification_id = :program_specification_id
            LIMIT 1
        ");
        $fileStmt->execute([
            ":id" => $file_id,
            ":program_specification_id" => $program_specification_id
        ]);
        $fileRecord = $fileStmt->fetch(PDO::FETCH_ASSOC);

        if (!$fileRecord) {
            $error = "Uploaded file record not found.";
        } else {
            $extension = strtolower(pathinfo($fileRecord["original_filename"] ?? "", PATHINFO_EXTENSION));

            if ($extension !== "docx") {
                $error = "Only .docx files can be structurally imported. PDF and .doc are attachment-only.";
            } else {
                $filePath = storagePath($fileRecord["file_path"] ?? "");

                if (!file_exists($filePath)) {
                    $error = "Physical file not found: " . $filePath;
                } else {
                    try {
                        $text = extractDocxText($filePath);
                        $tables = extractDocxTables($filePath);

                        if (count($tables) < 11) {
                            throw new Exception("Expected at least 11 DOCX tables, but detected " . count($tables) . ". This file may not follow the HP1001/NCAAA template.");
                        }

                        $identityTable = $tables[0] ?? [];
                        $generalTable = $tables[1] ?? [];
                        $teachingModeTable = $tables[2] ?? [];
                        $contactHoursTable = $tables[3] ?? [];
                        $cloTable = $tables[4] ?? [];
                        $topicsTable = $tables[5] ?? [];
                        $assessmentTable = $tables[6] ?? [];
                        $resourcesTable = $tables[7] ?? [];
                        $facilitiesTable = $tables[8] ?? [];
                        $qualityTable = $tables[9] ?? [];
                        $approvalTable = $tables[10] ?? [];

                        $courseCode = strtoupper(trim((string)($fileRecord["course_code"] ?? "")));
                        $courseTitle = "";
                        $programName = "";
                        $departmentName = "";
                        $collegeName = "";
                        $institutionName = "";
                        $versionNo = "";
                        $lastRevision = "";

                        foreach ($identityTable as $row) {
                            $cell = trim((string)($row[0] ?? ""));

                            if (stripos($cell, "Course Title:") !== false) {
                                $courseTitle = trim(substr($cell, stripos($cell, "Course Title:") + strlen("Course Title:")));
                            } elseif (stripos($cell, "Course Code:") !== false) {
                                $detectedCode = trim(substr($cell, stripos($cell, "Course Code:") + strlen("Course Code:")));
                                if ($courseCode === "") {
                                    $courseCode = strtoupper($detectedCode);
                                }
                            } elseif (stripos($cell, "Program:") !== false) {
                                $programName = trim(substr($cell, stripos($cell, "Program:") + strlen("Program:")));
                            } elseif (stripos($cell, "Department:") !== false) {
                                $departmentName = trim(substr($cell, stripos($cell, "Department:") + strlen("Department:")));
                            } elseif (stripos($cell, "College:") !== false) {
                                $collegeName = trim(substr($cell, stripos($cell, "College:") + strlen("College:")));
                            } elseif (stripos($cell, "Institution:") !== false) {
                                $institutionName = trim(substr($cell, stripos($cell, "Institution:") + strlen("Institution:")));
                            } elseif (stripos($cell, "Version:") !== false) {
                                $versionNo = trim(substr($cell, stripos($cell, "Version:") + strlen("Version:")));
                            } elseif (stripos($cell, "Last Revision Date:") !== false) {
                                $lastRevision = trim(substr($cell, stripos($cell, "Last Revision Date:") + strlen("Last Revision Date:")));
                            }
                        }

                        $creditHours = 0;
                        $theoreticalHours = 0;
                        $levelYear = "";
                        $courseTypeScope = "";
                        $requirementType = "";
                        $generalDescription = "";
                        $preReq = "";
                        $coReq = "";
                        $objectives = "";

                        if (isset($generalTable[0][0])) {
                            $creditHours = numberFromText($generalTable[0][0]);
                        }

                        if (isset($generalTable[1][0])) {
                            $theoreticalHours = numberFromText($generalTable[1][0]);
                        }

                        if (isset($generalTable[3])) {
                            foreach ($generalTable[3] as $cell) {
                                if (strpos($cell, "☒") !== false) {
                                    $courseTypeScope = str_replace("☒", "", $cell);
                                    $courseTypeScope = trim($courseTypeScope);
                                }
                            }
                        }

                        if (isset($generalTable[4])) {
                            foreach ($generalTable[4] as $cell) {
                                if (strpos($cell, "☒") !== false) {
                                    $requirementType = str_replace("☒", "", $cell);
                                    $requirementType = trim($requirementType);
                                }
                            }
                        }

                        if (isset($generalTable[5][0])) {
                            $line = $generalTable[5][0];
                            if (preg_match('/\((.*?)\)/', $line, $m)) {
                                $levelYear = trim($m[1]);
                            } else {
                                $levelYear = trim($line);
                            }
                        }

                        $generalDescription = $generalTable[7][0] ?? "";
                        $preReq = $generalTable[9][0] ?? "";
                        $coReq = $generalTable[11][0] ?? "";
                        $objectives = $generalTable[13][0] ?? "";

                        $contactLectures = 0;
                        $contactLab = 0;
                        $contactField = 0;
                        $contactTutorial = 0;
                        $contactOthers = 0;
                        $contactTotal = 0;

                        foreach ($contactHoursTable as $row) {
                            $row = array_pad($row, 3, "");
                            $activity = strtolower(trim($row[1]));
                            $hours = numberFromText($row[2]);

                            if (strpos($activity, "lectures") !== false) {
                                $contactLectures = $hours;
                            } elseif (strpos($activity, "laboratory") !== false || strpos($activity, "studio") !== false) {
                                $contactLab = $hours;
                            } elseif (strpos($activity, "field") !== false) {
                                $contactField = $hours;
                            } elseif (strpos($activity, "tutorial") !== false) {
                                $contactTutorial = $hours;
                            } elseif (strpos($activity, "others") !== false) {
                                $contactOthers = $hours;
                            } elseif (strtolower(trim($row[0])) === "total") {
                                $contactTotal = numberFromText($row[1]);
                            }
                        }

                        if ($contactTotal <= 0) {
                            $contactTotal = $contactLectures + $contactLab + $contactField + $contactTutorial + $contactOthers;
                        }

                        $programCourseId = null;

                        if (tableExists($conn, "program_specification_courses") && $courseCode !== "") {
                            $pcStmt = $conn->prepare("
                                SELECT *
                                FROM program_specification_courses
                                WHERE program_specification_id = :program_specification_id
                                  AND UPPER(course_code) = :course_code
                                LIMIT 1
                            ");
                            $pcStmt->execute([
                                ":program_specification_id" => $program_specification_id,
                                ":course_code" => strtoupper($courseCode)
                            ]);
                            $pc = $pcStmt->fetch(PDO::FETCH_ASSOC);

                            if ($pc) {
                                $programCourseId = $pc["id"] ?? null;

                                if ($courseTitle === "") {
                                    $courseTitle = $pc["course_title"] ?? "";
                                }

                                if ($creditHours <= 0) {
                                    $creditHours = (float)($pc["credit_hours"] ?? 0);
                                }
                            }
                        }

                        $courseSpecificationId = null;

                        if (!empty($fileRecord["course_specification_id"])) {
                            $courseSpecificationId = (int)$fileRecord["course_specification_id"];
                        } elseif ($courseCode !== "") {
                            $findStmt = $conn->prepare("
                                SELECT id
                                FROM course_specifications
                                WHERE program_specification_id = :program_specification_id
                                  AND UPPER(course_code) = :course_code
                                LIMIT 1
                            ");
                            $findStmt->execute([
                                ":program_specification_id" => $program_specification_id,
                                ":course_code" => strtoupper($courseCode)
                            ]);
                            $foundId = $findStmt->fetchColumn();

                            if ($foundId) {
                                $courseSpecificationId = (int)$foundId;
                            }
                        }

                        if ($courseSpecificationId === null) {
                            $insertMain = $conn->prepare("
                                INSERT INTO course_specifications
                                (
                                    program_specification_id,
                                    program_course_id,
                                    course_code,
                                    course_title,
                                    program_name,
                                    department_name,
                                    college_name,
                                    institution_name,
                                    version_no,
                                    last_revision_date_text,
                                    credit_hours,
                                    theoretical_hours,
                                    course_level_year,
                                    course_type_scope,
                                    requirement_type,
                                    course_general_description,
                                    pre_requisites,
                                    co_requisites,
                                    course_main_objectives,
                                    contact_lectures,
                                    contact_laboratory,
                                    contact_field,
                                    contact_tutorial,
                                    contact_others,
                                    contact_total,
                                    status,
                                    approval_status,
                                    notes
                                )
                                VALUES
                                (
                                    :program_specification_id,
                                    :program_course_id,
                                    :course_code,
                                    :course_title,
                                    :program_name,
                                    :department_name,
                                    :college_name,
                                    :institution_name,
                                    :version_no,
                                    :last_revision_date_text,
                                    :credit_hours,
                                    :theoretical_hours,
                                    :course_level_year,
                                    :course_type_scope,
                                    :requirement_type,
                                    :course_general_description,
                                    :pre_requisites,
                                    :co_requisites,
                                    :course_main_objectives,
                                    :contact_lectures,
                                    :contact_laboratory,
                                    :contact_field,
                                    :contact_tutorial,
                                    :contact_others,
                                    :contact_total,
                                    :status,
                                    :approval_status,
                                    :notes
                                )
                            ");

                            $insertMain->execute([
                                ":program_specification_id" => $program_specification_id,
                                ":program_course_id" => $programCourseId,
                                ":course_code" => nullIfEmpty($courseCode),
                                ":course_title" => nullIfEmpty($courseTitle),
                                ":program_name" => nullIfEmpty($programName),
                                ":department_name" => nullIfEmpty($departmentName),
                                ":college_name" => nullIfEmpty($collegeName),
                                ":institution_name" => nullIfEmpty($institutionName),
                                ":version_no" => nullIfEmpty($versionNo),
                                ":last_revision_date_text" => nullIfEmpty($lastRevision),
                                ":credit_hours" => $creditHours,
                                ":theoretical_hours" => $theoreticalHours,
                                ":course_level_year" => nullIfEmpty($levelYear),
                                ":course_type_scope" => nullIfEmpty($courseTypeScope),
                                ":requirement_type" => nullIfEmpty($requirementType),
                                ":course_general_description" => nullIfEmpty($generalDescription),
                                ":pre_requisites" => nullIfEmpty($preReq),
                                ":co_requisites" => nullIfEmpty($coReq),
                                ":course_main_objectives" => nullIfEmpty($objectives),
                                ":contact_lectures" => $contactLectures,
                                ":contact_laboratory" => $contactLab,
                                ":contact_field" => $contactField,
                                ":contact_tutorial" => $contactTutorial,
                                ":contact_others" => $contactOthers,
                                ":contact_total" => $contactTotal,
                                ":status" => "Imported from DOCX",
                                ":approval_status" => "Needs Review",
                                ":notes" => "Imported from uploaded DOCX file ID " . $file_id
                            ]);

                            $courseSpecificationId = (int)$conn->lastInsertId();
                        } else {
                            $updateMain = $conn->prepare("
                                UPDATE course_specifications
                                SET
                                    program_specification_id = :program_specification_id,
                                    program_course_id = :program_course_id,
                                    course_code = :course_code,
                                    course_title = :course_title,
                                    program_name = :program_name,
                                    department_name = :department_name,
                                    college_name = :college_name,
                                    institution_name = :institution_name,
                                    version_no = :version_no,
                                    last_revision_date_text = :last_revision_date_text,
                                    credit_hours = :credit_hours,
                                    theoretical_hours = :theoretical_hours,
                                    course_level_year = :course_level_year,
                                    course_type_scope = :course_type_scope,
                                    requirement_type = :requirement_type,
                                    course_general_description = :course_general_description,
                                    pre_requisites = :pre_requisites,
                                    co_requisites = :co_requisites,
                                    course_main_objectives = :course_main_objectives,
                                    contact_lectures = :contact_lectures,
                                    contact_laboratory = :contact_laboratory,
                                    contact_field = :contact_field,
                                    contact_tutorial = :contact_tutorial,
                                    contact_others = :contact_others,
                                    contact_total = :contact_total,
                                    status = :status,
                                    approval_status = :approval_status,
                                    notes = :notes
                                WHERE id = :id
                            ");

                            $updateMain->execute([
                                ":program_specification_id" => $program_specification_id,
                                ":program_course_id" => $programCourseId,
                                ":course_code" => nullIfEmpty($courseCode),
                                ":course_title" => nullIfEmpty($courseTitle),
                                ":program_name" => nullIfEmpty($programName),
                                ":department_name" => nullIfEmpty($departmentName),
                                ":college_name" => nullIfEmpty($collegeName),
                                ":institution_name" => nullIfEmpty($institutionName),
                                ":version_no" => nullIfEmpty($versionNo),
                                ":last_revision_date_text" => nullIfEmpty($lastRevision),
                                ":credit_hours" => $creditHours,
                                ":theoretical_hours" => $theoreticalHours,
                                ":course_level_year" => nullIfEmpty($levelYear),
                                ":course_type_scope" => nullIfEmpty($courseTypeScope),
                                ":requirement_type" => nullIfEmpty($requirementType),
                                ":course_general_description" => nullIfEmpty($generalDescription),
                                ":pre_requisites" => nullIfEmpty($preReq),
                                ":co_requisites" => nullIfEmpty($coReq),
                                ":course_main_objectives" => nullIfEmpty($objectives),
                                ":contact_lectures" => $contactLectures,
                                ":contact_laboratory" => $contactLab,
                                ":contact_field" => $contactField,
                                ":contact_tutorial" => $contactTutorial,
                                ":contact_others" => $contactOthers,
                                ":contact_total" => $contactTotal,
                                ":status" => "Imported from DOCX",
                                ":approval_status" => "Needs Review",
                                ":notes" => "Updated from uploaded DOCX file ID " . $file_id,
                                ":id" => $courseSpecificationId
                            ]);
                        }

                        $updateFile = $conn->prepare("
                            UPDATE course_specification_files
                            SET course_specification_id = :course_specification_id,
                                course_code = :course_code
                            WHERE id = :id
                        ");
                        $updateFile->execute([
                            ":course_specification_id" => $courseSpecificationId,
                            ":course_code" => nullIfEmpty($courseCode),
                            ":id" => $file_id
                        ]);

                        $childTables = [
                            "course_specification_clos",
                            "course_specification_topics",
                            "course_specification_assessments",
                            "course_specification_resources",
                            "course_specification_facilities",
                            "course_specification_quality",
                            "course_specification_approval"
                        ];

                        foreach ($childTables as $childTable) {
                            $del = $conn->prepare("DELETE FROM $childTable WHERE course_specification_id = :id");
                            $del->execute([":id" => $courseSpecificationId]);
                        }

                        $closInserted = 0;
                        $topicsInserted = 0;
                        $assessmentsInserted = 0;
                        $resourcesInserted = 0;
                        $facilitiesInserted = 0;
                        $qualityInserted = 0;
                        $approvalInserted = 0;

                        /* CLOs - Table 5 */
                        $currentDomain = "";
                        $displayOrder = 1;

                        foreach ($cloTable as $row) {
                            $row = array_pad($row, 5, "");

                            $code = trim($row[0]);
                            $cloText = trim($row[1]);
                            $ploCode = trim($row[2]);
                            $strategies = trim($row[3]);
                            $methods = trim($row[4]);

                            if ($code === "" || strtolower($code) === "code") {
                                continue;
                            }

                            if (preg_match('/^[123]\.0$/', $code)) {
                                $currentDomain = $cloText;
                                continue;
                            }

                            if (preg_match('/^[123]\.\d+$/', $code) && $cloText !== "") {
                                $stmt = $conn->prepare("
                                    INSERT INTO course_specification_clos
                                    (
                                        course_specification_id,
                                        clo_code,
                                        clo_domain,
                                        clo_text,
                                        aligned_plo_code,
                                        teaching_strategies,
                                        assessment_methods,
                                        display_order
                                    )
                                    VALUES
                                    (
                                        :course_specification_id,
                                        :clo_code,
                                        :clo_domain,
                                        :clo_text,
                                        :aligned_plo_code,
                                        :teaching_strategies,
                                        :assessment_methods,
                                        :display_order
                                    )
                                ");

                                $stmt->execute([
                                    ":course_specification_id" => $courseSpecificationId,
                                    ":clo_code" => $code,
                                    ":clo_domain" => nullIfEmpty($currentDomain),
                                    ":clo_text" => nullIfEmpty($cloText),
                                    ":aligned_plo_code" => nullIfEmpty($ploCode),
                                    ":teaching_strategies" => nullIfEmpty($strategies),
                                    ":assessment_methods" => nullIfEmpty($methods),
                                    ":display_order" => $displayOrder
                                ]);

                                $closInserted++;
                                $displayOrder++;
                            }
                        }

                        /* Topics - Table 6 */
                        $displayOrder = 1;

                        foreach ($topicsTable as $row) {
                            $row = array_pad($row, 3, "");

                            $first = trim($row[0]);
                            $topic = trim($row[1]);
                            $hours = numberFromText($row[2]);

                            if (strtolower($first) === "no" || stripos($topic, "list of topics") !== false) {
                                continue;
                            }

                            if (stripos($first, "total") !== false || stripos($topic, "total") !== false) {
                                continue;
                            }

                            if ($topic !== "") {
                                $topicNo = intFromText($first);

                                if ($topicNo === 0) {
                                    $topicNo = $displayOrder;
                                }

                                $stmt = $conn->prepare("
                                    INSERT INTO course_specification_topics
                                    (
                                        course_specification_id,
                                        topic_no,
                                        topic_title,
                                        contact_hours,
                                        display_order
                                    )
                                    VALUES
                                    (
                                        :course_specification_id,
                                        :topic_no,
                                        :topic_title,
                                        :contact_hours,
                                        :display_order
                                    )
                                ");

                                $stmt->execute([
                                    ":course_specification_id" => $courseSpecificationId,
                                    ":topic_no" => $topicNo,
                                    ":topic_title" => $topic,
                                    ":contact_hours" => $hours,
                                    ":display_order" => $displayOrder
                                ]);

                                $topicsInserted++;
                                $displayOrder++;
                            }
                        }

                        /* Assessment - Table 7 */
                        $displayOrder = 1;

                        foreach ($assessmentTable as $row) {
                            $row = array_pad($row, 4, "");

                            $first = trim($row[0]);
                            $activity = trim($row[1]);
                            $timing = trim($row[2]);
                            $percentage = numberFromText($row[3]);

                            if (strtolower($first) === "no" || stripos($activity, "assessment activities") !== false) {
                                continue;
                            }

                            if (stripos($first, "total") !== false || stripos($activity, "total") !== false) {
                                continue;
                            }

                            if ($activity !== "") {
                                $stmt = $conn->prepare("
                                    INSERT INTO course_specification_assessments
                                    (
                                        course_specification_id,
                                        assessment_no,
                                        assessment_activity,
                                        assessment_timing,
                                        percentage,
                                        display_order
                                    )
                                    VALUES
                                    (
                                        :course_specification_id,
                                        :assessment_no,
                                        :assessment_activity,
                                        :assessment_timing,
                                        :percentage,
                                        :display_order
                                    )
                                ");

                                $stmt->execute([
                                    ":course_specification_id" => $courseSpecificationId,
                                    ":assessment_no" => intFromText($first),
                                    ":assessment_activity" => $activity,
                                    ":assessment_timing" => nullIfEmpty($timing),
                                    ":percentage" => $percentage,
                                    ":display_order" => $displayOrder
                                ]);

                                $assessmentsInserted++;
                                $displayOrder++;
                            }
                        }

                        /* Resources - Table 8 */
                        $displayOrder = 1;

                        foreach ($resourcesTable as $row) {
                            $row = array_pad($row, 2, "");

                            $category = trim($row[0]);
                            $details = trim($row[1]);

                            if ($category !== "" && $details !== "") {
                                $stmt = $conn->prepare("
                                    INSERT INTO course_specification_resources
                                    (
                                        course_specification_id,
                                        resource_type,
                                        resource_category,
                                        resource_details,
                                        display_order
                                    )
                                    VALUES
                                    (
                                        :course_specification_id,
                                        :resource_type,
                                        :resource_category,
                                        :resource_details,
                                        :display_order
                                    )
                                ");

                                $stmt->execute([
                                    ":course_specification_id" => $courseSpecificationId,
                                    ":resource_type" => $category,
                                    ":resource_category" => $category,
                                    ":resource_details" => $details,
                                    ":display_order" => $displayOrder
                                ]);

                                $resourcesInserted++;
                                $displayOrder++;
                            }
                        }

                        /* Facilities - Table 9 */
                        $displayOrder = 1;

                        foreach ($facilitiesTable as $row) {
                            $row = array_pad($row, 2, "");

                            $category = trim($row[0]);
                            $details = trim($row[1]);

                            if (strtolower($category) === "items" || strtolower($details) === "resources") {
                                continue;
                            }

                            if ($category !== "" && $details !== "") {
                                $stmt = $conn->prepare("
                                    INSERT INTO course_specification_facilities
                                    (
                                        course_specification_id,
                                        facility_item,
                                        facility_category,
                                        required_resources,
                                        display_order
                                    )
                                    VALUES
                                    (
                                        :course_specification_id,
                                        :facility_item,
                                        :facility_category,
                                        :required_resources,
                                        :display_order
                                    )
                                ");

                                $stmt->execute([
                                    ":course_specification_id" => $courseSpecificationId,
                                    ":facility_item" => $category,
                                    ":facility_category" => $category,
                                    ":required_resources" => $details,
                                    ":display_order" => $displayOrder
                                ]);

                                $facilitiesInserted++;
                                $displayOrder++;
                            }
                        }

                        /* Quality - Table 10 */
                        $displayOrder = 1;

                        foreach ($qualityTable as $row) {
                            $row = array_pad($row, 3, "");

                            $area = trim($row[0]);
                            $assessor = trim($row[1]);
                            $method = trim($row[2]);

                            if (stripos($area, "Assessment Areas") !== false) {
                                continue;
                            }

                            if ($area !== "") {
                                $stmt = $conn->prepare("
                                    INSERT INTO course_specification_quality
                                    (
                                        course_specification_id,
                                        assessment_area,
                                        assessor,
                                        assessment_method,
                                        display_order
                                    )
                                    VALUES
                                    (
                                        :course_specification_id,
                                        :assessment_area,
                                        :assessor,
                                        :assessment_method,
                                        :display_order
                                    )
                                ");

                                $stmt->execute([
                                    ":course_specification_id" => $courseSpecificationId,
                                    ":assessment_area" => $area,
                                    ":assessor" => nullIfEmpty($assessor),
                                    ":assessment_method" => nullIfEmpty($method),
                                    ":display_order" => $displayOrder
                                ]);

                                $qualityInserted++;
                                $displayOrder++;
                            }
                        }

                        /* Approval - Table 11 */
                        $council = "";
                        $referenceNo = "";
                        $approvalDateText = "";

                        foreach ($approvalTable as $row) {
                            $row = array_pad($row, 2, "");

                            $label = strtolower(trim($row[0]));
                            $value = trim($row[1]);

                            if (strpos($label, "council") !== false || strpos($label, "committee") !== false) {
                                $council = $value;
                            } elseif (strpos($label, "reference") !== false) {
                                $referenceNo = $value;
                            } elseif (strpos($label, "date") !== false) {
                                $approvalDateText = $value;
                            }
                        }

                        if ($council !== "" || $referenceNo !== "" || $approvalDateText !== "") {
                            $stmt = $conn->prepare("
                                INSERT INTO course_specification_approval
                                (
                                    course_specification_id,
                                    council_committee,
                                    reference_no,
                                    approval_date_text,
                                    notes
                                )
                                VALUES
                                (
                                    :course_specification_id,
                                    :council_committee,
                                    :reference_no,
                                    :approval_date_text,
                                    :notes
                                )
                            ");

                            $stmt->execute([
                                ":course_specification_id" => $courseSpecificationId,
                                ":council_committee" => nullIfEmpty($council),
                                ":reference_no" => nullIfEmpty($referenceNo),
                                ":approval_date_text" => nullIfEmpty($approvalDateText),
                                ":notes" => "Imported from DOCX."
                            ]);

                            $approvalInserted++;
                        }

                        logActivitySafe(
                            $conn,
                            "Import",
                            "Course Specification DOCX",
                            $courseSpecificationId,
                            "Imported DOCX file ID " . $file_id . " for course " . $courseCode
                        );

                        $success = "Course specification imported successfully.";

                        $importSummary = [
                            "Course Specification ID" => $courseSpecificationId,
                            "Course Code" => $courseCode,
                            "Course Title" => $courseTitle,
                            "CLOs Imported" => $closInserted,
                            "Topics Imported" => $topicsInserted,
                            "Assessment Activities Imported" => $assessmentsInserted,
                            "Resources Imported" => $resourcesInserted,
                            "Facilities Imported" => $facilitiesInserted,
                            "Quality Rows Imported" => $qualityInserted,
                            "Approval Rows Imported" => $approvalInserted
                        ];

                    } catch (Exception $e) {
                        $error = "Import failed: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

/* Reload files after import */
$filesStmt = $conn->prepare("
    SELECT f.*, cs.course_title AS linked_course_title
    FROM course_specification_files f
    LEFT JOIN course_specifications cs ON f.course_specification_id = cs.id
    WHERE f.program_specification_id = :program_specification_id
    ORDER BY f.upload_date DESC, f.id DESC
");
$filesStmt->execute([":program_specification_id" => $program_specification_id]);
$uploadedFiles = $filesStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Import Course Specification from DOCX</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-bottom: 18px;
        }

        .actions a {
            display: inline-block;
            width: auto;
            text-align: center;
            margin-bottom: 0;
        }

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .alert-success {
            background: #d1e7dd;
            border: 1px solid #badbcc;
            color: #0f5132;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(160px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .summary-card {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 12px;
        }

        .summary-label {
            font-size: 12px;
            color: #555;
        }

        .summary-value {
            font-size: 20px;
            font-weight: bold;
            color: #007bff;
        }

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .wide-table {
            min-width: 1000px;
            width: 100%;
            border-collapse: collapse;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        @media (max-width: 900px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Import Course Specification from DOCX</h1>
        <p>Extract structured course specification data from uploaded Word files.</p>
    </div>

    <div class="actions">
        <a href="upload_course_specification_file.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Upload Course Spec File
        </a>

        <a href="course_specifications.php" class="btn">
            Course Specifications
        </a>

        <a href="program_specification_dashboard.php?id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Program Spec Dashboard
        </a>
    </div>

    <div class="panel">
        <h2>Program Specification</h2>

        <table>
            <tr>
                <th>Program Specification ID</th>
                <td><?php echo safeText($program_specification_id); ?></td>
                <th>Program</th>
                <td><?php echo safeText($programSpec["program_name"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Program Code</th>
                <td><?php echo safeText($programSpec["program_code"] ?? ""); ?></td>
                <th>College</th>
                <td><?php echo safeText($programSpec["college_name"] ?? ""); ?></td>
            </tr>
        </table>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error"><?php echo safeText($error); ?></div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="alert-success"><?php echo safeText($success); ?></div>
    <?php endif; ?>

    <?php if (!empty($importSummary)): ?>
        <div class="panel">
            <h2>Import Summary</h2>

            <div class="summary-grid">
                <?php foreach ($importSummary as $label => $value): ?>
                    <div class="summary-card">
                        <div class="summary-label"><?php echo safeText($label); ?></div>
                        <div class="summary-value"><?php echo safeText($value); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Select DOCX File to Import</h2>

        <form method="POST" action="import_course_specification_from_docx.php?program_specification_id=<?php echo safeText($program_specification_id); ?>">
            <label>Uploaded DOCX Course Specification File</label>

            <select name="file_id" required>
                <option value="">-- Select DOCX File --</option>

                <?php foreach ($uploadedFiles as $file): ?>
                    <?php
                    $ext = strtolower(pathinfo($file["original_filename"] ?? "", PATHINFO_EXTENSION));
                    if ($ext !== "docx") {
                        continue;
                    }
                    ?>
                    <option value="<?php echo safeText($file["id"]); ?>" <?php if ((string)$file_id === (string)$file["id"]) echo "selected"; ?>>
                        ID <?php echo safeText($file["id"]); ?> |
                        <?php echo safeText($file["course_code"] ?? ""); ?> |
                        <?php echo safeText($file["original_filename"] ?? ""); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <p class="small-text">Only .docx files are supported for structured extraction.</p>

            <button type="submit" class="btn btn-purple">
                Import Selected DOCX
            </button>
        </form>
    </div>

    <div class="panel">
        <h2>Uploaded Files</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>ID</th>
                    <th>Course Code</th>
                    <th>Original Filename</th>
                    <th>Linked Course Title</th>
                    <th>File Type</th>
                    <th>Upload Date</th>
                    <th>Action</th>
                </tr>

                <?php if (count($uploadedFiles) > 0): ?>
                    <?php foreach ($uploadedFiles as $file): ?>
                        <tr>
                            <td><?php echo safeText($file["id"]); ?></td>
                            <td><?php echo safeText($file["course_code"] ?? ""); ?></td>
                            <td><?php echo safeText($file["original_filename"] ?? ""); ?></td>
                            <td><?php echo safeText($file["linked_course_title"] ?? ""); ?></td>
                            <td><?php echo safeText($file["file_type"] ?? ""); ?></td>
                            <td><?php echo safeText($file["upload_date"] ?? ""); ?></td>
                            <td>
                                <?php if (strtolower(pathinfo($file["original_filename"] ?? "", PATHINFO_EXTENSION)) === "docx"): ?>
                                    <a href="import_course_specification_from_docx.php?program_specification_id=<?php echo safeText($program_specification_id); ?>&file_id=<?php echo safeText($file["id"]); ?>" class="btn">
                                        Select
                                    </a>
                                <?php else: ?>
                                    <span class="small-text">Attachment only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7">No uploaded course specification files found.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
