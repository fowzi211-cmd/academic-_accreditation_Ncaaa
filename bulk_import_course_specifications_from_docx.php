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

$error = "";
$success = "";
$results = [];

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

function clearImportedChildRows($conn, $courseSpecificationId) {
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
        if (tableExists($conn, $childTable) && columnExists($conn, $childTable, "course_specification_id")) {
            $stmt = $conn->prepare("DELETE FROM $childTable WHERE course_specification_id = :id");
            $stmt->execute([":id" => $courseSpecificationId]);
        }
    }
}

function importCourseSpecDocx($conn, $programSpec, $program_specification_id, $fileRecord) {
    $filePath = storagePath($fileRecord["file_path"] ?? "");

    if (!file_exists($filePath)) {
        throw new Exception("Physical file not found.");
    }

    $tables = extractDocxTables($filePath);

    if (count($tables) < 11) {
        throw new Exception("Expected at least 11 tables, detected " . count($tables) . ".");
    }

    $identityTable = $tables[0] ?? [];
    $generalTable = $tables[1] ?? [];
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
                $courseTypeScope = trim(str_replace("☒", "", $cell));
            }
        }
    }

    if (isset($generalTable[4])) {
        foreach ($generalTable[4] as $cell) {
            if (strpos($cell, "☒") !== false) {
                $requirementType = trim(str_replace("☒", "", $cell));
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
            ":notes" => "Imported from bulk DOCX importer."
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
            ":notes" => "Updated from bulk DOCX importer.",
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
        ":id" => $fileRecord["id"]
    ]);

    clearImportedChildRows($conn, $courseSpecificationId);

    $closInserted = 0;
    $topicsInserted = 0;
    $assessmentsInserted = 0;
    $resourcesInserted = 0;
    $facilitiesInserted = 0;
    $qualityInserted = 0;
    $approvalInserted = 0;

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
            ":notes" => "Imported from bulk DOCX importer."
        ]);

        $approvalInserted++;
    }

    logActivitySafe(
        $conn,
        "Bulk Import",
        "Course Specification DOCX",
        $courseSpecificationId,
        "Bulk imported DOCX file ID " . ($fileRecord["id"] ?? "") . " for course " . $courseCode
    );

    return [
        "course_specification_id" => $courseSpecificationId,
        "course_code" => $courseCode,
        "course_title" => $courseTitle,
        "clos" => $closInserted,
        "topics" => $topicsInserted,
        "assessments" => $assessmentsInserted,
        "resources" => $resourcesInserted,
        "facilities" => $facilitiesInserted,
        "quality" => $qualityInserted,
        "approval" => $approvalInserted
    ];
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

/* Safety columns for older schema */
addColumnIfMissing($conn, "course_specification_resources", "resource_type", "VARCHAR(150) NULL");
addColumnIfMissing($conn, "course_specification_resources", "resource_category", "VARCHAR(150) NULL");
addColumnIfMissing($conn, "course_specification_resources", "resource_details", "TEXT NULL");
addColumnIfMissing($conn, "course_specification_resources", "display_order", "INT DEFAULT 0");

addColumnIfMissing($conn, "course_specification_facilities", "facility_item", "VARCHAR(150) NULL");
addColumnIfMissing($conn, "course_specification_facilities", "facility_category", "VARCHAR(150) NULL");
addColumnIfMissing($conn, "course_specification_facilities", "required_resources", "TEXT NULL");
addColumnIfMissing($conn, "course_specification_facilities", "display_order", "INT DEFAULT 0");

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

$docxFilesStmt = $conn->prepare("
    SELECT f.*, cs.course_title AS linked_course_title
    FROM course_specification_files f
    LEFT JOIN course_specifications cs ON f.course_specification_id = cs.id
    WHERE f.program_specification_id = :program_specification_id
      AND LOWER(f.file_type) = 'docx'
    ORDER BY f.course_code ASC, f.id ASC
");
$docxFilesStmt->execute([":program_specification_id" => $program_specification_id]);
$docxFiles = $docxFilesStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $selectedFileIds = $_POST["file_ids"] ?? [];

    if (!is_array($selectedFileIds) || count($selectedFileIds) === 0) {
        $error = "Select at least one DOCX file to import.";
    } else {
        foreach ($docxFiles as $fileRecord) {
            if (!in_array((string)$fileRecord["id"], array_map("strval", $selectedFileIds), true)) {
                continue;
            }

            $rowResult = [
                "file_id" => $fileRecord["id"],
                "filename" => $fileRecord["original_filename"],
                "course_code" => $fileRecord["course_code"],
                "status" => "Failed",
                "message" => "",
                "clos" => 0,
                "topics" => 0,
                "assessments" => 0
            ];

            try {
                $summary = importCourseSpecDocx($conn, $programSpec, $program_specification_id, $fileRecord);

                $rowResult["status"] = "Imported";
                $rowResult["course_code"] = $summary["course_code"];
                $rowResult["message"] = "Imported successfully.";
                $rowResult["clos"] = $summary["clos"];
                $rowResult["topics"] = $summary["topics"];
                $rowResult["assessments"] = $summary["assessments"];
            } catch (Exception $e) {
                $rowResult["status"] = "Failed";
                $rowResult["message"] = $e->getMessage();
            }

            $results[] = $rowResult;
        }

        $success = "Bulk DOCX import completed. Review the result table.";
    }
}

/* Reload status counts */
$statusStmt = $conn->prepare("
    SELECT 
        cs.id,
        cs.course_code,
        cs.course_title,
        cs.status,
        COUNT(DISTINCT clo.id) AS clo_count,
        COUNT(DISTINCT t.id) AS topic_count,
        COUNT(DISTINCT a.id) AS assessment_count
    FROM course_specifications cs
    LEFT JOIN course_specification_clos clo ON clo.course_specification_id = cs.id
    LEFT JOIN course_specification_topics t ON t.course_specification_id = cs.id
    LEFT JOIN course_specification_assessments a ON a.course_specification_id = cs.id
    WHERE cs.program_specification_id = :program_specification_id
    GROUP BY cs.id, cs.course_code, cs.course_title, cs.status
    ORDER BY cs.course_code ASC, cs.id ASC
");
$statusStmt->execute([":program_specification_id" => $program_specification_id]);
$courseStatuses = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

$totalDocxFiles = count($docxFiles);
$readyToImport = 0;

foreach ($docxFiles as $file) {
    if (!empty($file["file_path"])) {
        $readyToImport++;
    }
}

$importedCourses = 0;

foreach ($courseStatuses as $row) {
    if ((int)$row["clo_count"] > 0 && (int)$row["topic_count"] > 0 && (int)$row["assessment_count"] > 0) {
        $importedCourses++;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Bulk Import Course Specifications from DOCX</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(150px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .summary-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
        }

        .summary-number {
            font-size: 26px;
            font-weight: bold;
            color: #007bff;
        }

        .summary-label {
            color: #555;
            font-size: 13px;
            margin-top: 6px;
        }

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

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .wide-table {
            min-width: 1100px;
            width: 100%;
            border-collapse: collapse;
        }

        .badge-ok {
            background: #d1e7dd;
            color: #0f5132;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-warning {
            background: #fff3cd;
            color: #664d03;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-danger {
            background: #f8d7da;
            color: #842029;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
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
        <h1>Bulk Import Course Specifications from DOCX</h1>
        <p>Import structured data from uploaded DOCX course specification files.</p>
    </div>

    <div class="actions">
	<a href="course_specification_import_status.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
    Next Step: Check Import Status
</a>

	<a href="course_specification_workflow.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
    Back to Workflow
</a>
        <a href="bulk_upload_course_specifications.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Bulk Upload
        </a>

        <a href="upload_course_specification_file.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Single Upload
        </a>

        <a href="course_specifications.php" class="btn btn-secondary">
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

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalDocxFiles); ?></div>
            <div class="summary-label">DOCX Files</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($readyToImport); ?></div>
            <div class="summary-label">Ready to Import</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($courseStatuses)); ?></div>
            <div class="summary-label">Course Spec Records</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($importedCourses); ?></div>
            <div class="summary-label">Imported Courses</div>
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error"><?php echo safeText($error); ?></div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="alert-success"><?php echo safeText($success); ?></div>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
        <div class="panel">
            <h2>Import Results</h2>

            <table>
                <tr>
                    <th>File ID</th>
                    <th>Course Code</th>
                    <th>Filename</th>
                    <th>Status</th>
                    <th>CLOs</th>
                    <th>Topics</th>
                    <th>Assessments</th>
                    <th>Message</th>
                </tr>

                <?php foreach ($results as $result): ?>
                    <tr>
                        <td><?php echo safeText($result["file_id"]); ?></td>
                        <td><?php echo safeText($result["course_code"]); ?></td>
                        <td><?php echo safeText($result["filename"]); ?></td>
                        <td>
                            <?php if ($result["status"] === "Imported"): ?>
                                <span class="badge-ok">Imported</span>
                            <?php else: ?>
                                <span class="badge-danger">Failed</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo safeText($result["clos"]); ?></td>
                        <td><?php echo safeText($result["topics"]); ?></td>
                        <td><?php echo safeText($result["assessments"]); ?></td>
                        <td><?php echo safeText($result["message"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Select DOCX Files to Import</h2>

        <form method="POST" action="bulk_import_course_specifications_from_docx.php?program_specification_id=<?php echo safeText($program_specification_id); ?>">

            <div class="wide-table-wrapper">
                <table class="wide-table">
                    <tr>
                        <th>Select</th>
                        <th>File ID</th>
                        <th>Course Code</th>
                        <th>Linked Course Title</th>
                        <th>Original Filename</th>
                        <th>Upload Date</th>
                    </tr>

                    <?php if (count($docxFiles) > 0): ?>
                        <?php foreach ($docxFiles as $file): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="file_ids[]" value="<?php echo safeText($file["id"]); ?>" checked>
                                </td>
                                <td><?php echo safeText($file["id"]); ?></td>
                                <td><?php echo safeText($file["course_code"] ?? ""); ?></td>
                                <td><?php echo safeText($file["linked_course_title"] ?? ""); ?></td>
                                <td><?php echo safeText($file["original_filename"] ?? ""); ?></td>
                                <td><?php echo safeText($file["upload_date"] ?? ""); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">No DOCX course specification files uploaded yet.</td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>

            <br>

            <?php if (count($docxFiles) > 0): ?>
                <button type="submit" class="btn btn-purple" onclick="return confirm('Import selected DOCX files? Existing child rows for these course specifications will be replaced.');">
                    Import Selected DOCX Files
                </button>
            <?php endif; ?>

        </form>
    </div>

    <div class="panel">
        <h2>Course Specification Import Status</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Course Spec ID</th>
                    <th>Course Code</th>
                    <th>Course Title</th>
                    <th>Status</th>
                    <th>CLOs</th>
                    <th>Topics</th>
                    <th>Assessments</th>
                    <th>Profile</th>
                </tr>

                <?php if (count($courseStatuses) > 0): ?>
                    <?php foreach ($courseStatuses as $row): ?>
                        <?php
                        $isImported = ((int)$row["clo_count"] > 0 && (int)$row["topic_count"] > 0 && (int)$row["assessment_count"] > 0);
                        ?>
                        <tr>
                            <td><?php echo safeText($row["id"]); ?></td>
                            <td><?php echo safeText($row["course_code"]); ?></td>
                            <td><?php echo safeText($row["course_title"]); ?></td>
                            <td>
                                <?php if ($isImported): ?>
                                    <span class="badge-ok">Imported</span>
                                <?php else: ?>
                                    <span class="badge-warning">Needs Import</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo safeText($row["clo_count"]); ?></td>
                            <td><?php echo safeText($row["topic_count"]); ?></td>
                            <td><?php echo safeText($row["assessment_count"]); ?></td>
                            <td>
                                <a href="course_specification_profile.php?id=<?php echo safeText($row["id"]); ?>" class="btn">
                                    Profile
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8">No course specification records found.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
