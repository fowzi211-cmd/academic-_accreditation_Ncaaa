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

if (!tableExists($conn, "program_specification_teaching_strategies")) {
    die("program_specification_teaching_strategies table does not exist.");
}

if (!tableExists($conn, "program_specification_assessment_methods")) {
    die("program_specification_assessment_methods table does not exist.");
}

if (!tableExists($conn, "program_specification_mark_distributions")) {
    die("program_specification_mark_distributions table does not exist.");
}

/*
    Safety upgrade for older tables.
*/
addColumnIfMissing($conn, "program_specification_teaching_strategies", "strategy_name", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_teaching_strategies", "strategy_description", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_teaching_strategies", "related_plo_codes", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_teaching_strategies", "curricular_or_extra", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "program_specification_teaching_strategies", "notes", "TEXT NULL");

addColumnIfMissing($conn, "program_specification_assessment_methods", "assessment_method", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_assessment_methods", "assessment_type", "VARCHAR(100) NULL");
addColumnIfMissing($conn, "program_specification_assessment_methods", "assessment_description", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_assessment_methods", "related_plo_codes", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_assessment_methods", "notes", "TEXT NULL");

addColumnIfMissing($conn, "program_specification_mark_distributions", "course_type", "VARCHAR(150) NULL");
addColumnIfMissing($conn, "program_specification_mark_distributions", "assessment_method", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_mark_distributions", "marks", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "program_specification_mark_distributions", "percentage", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "program_specification_mark_distributions", "display_order", "INT NULL");
addColumnIfMissing($conn, "program_specification_mark_distributions", "notes", "TEXT NULL");

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
    Default HPE Teaching, Assessment, and Mark Distribution
*/

$teachingRows = [
    [
        "strategy_name" => "Lectures and Interactive Presentations",
        "strategy_description" => "Structured lectures supported by interactive questioning, short discussions, and examples from public health and health promotion practice.",
        "related_plo_codes" => "K1, K2",
        "curricular_or_extra" => "Curricular",
        "notes" => "Main strategy for building theoretical knowledge and conceptual understanding."
    ],
    [
        "strategy_name" => "Problem-Based Learning",
        "strategy_description" => "Students analyze public health and health promotion problems, identify determinants, propose evidence-based solutions, and justify intervention choices.",
        "related_plo_codes" => "K2, S1, S3, S5",
        "curricular_or_extra" => "Curricular",
        "notes" => "Supports analytical thinking and application of health promotion planning principles."
    ],
    [
        "strategy_name" => "Case-Based Learning",
        "strategy_description" => "Students work with realistic cases related to community health needs, behavior change, health education, maternal and child health, environmental health, and health-promoting settings.",
        "related_plo_codes" => "K2, S1, S2, S3, V1, V2",
        "curricular_or_extra" => "Curricular",
        "notes" => "Useful for linking theory with practical decision-making."
    ],
    [
        "strategy_name" => "Group Discussion and Collaborative Learning",
        "strategy_description" => "Students work in small groups to discuss assigned topics, debate solutions, prepare reports, and present findings.",
        "related_plo_codes" => "S2, S3, V1",
        "curricular_or_extra" => "Curricular",
        "notes" => "Supports communication, teamwork, leadership, and collaboration."
    ],
    [
        "strategy_name" => "Practical Sessions and Applied Activities",
        "strategy_description" => "Students perform applied activities such as needs assessment, data interpretation, intervention design, campaign planning, and educational material development.",
        "related_plo_codes" => "S1, S2, S3, S4, S5",
        "curricular_or_extra" => "Curricular",
        "notes" => "Used in skills-based and applied public health courses."
    ],
    [
        "strategy_name" => "Field Visits and Community-Based Learning",
        "strategy_description" => "Students engage with community settings, health services, schools, workplaces, and public health programs to observe and analyze real-world practice.",
        "related_plo_codes" => "K2, S1, S2, S3, V1, V2",
        "curricular_or_extra" => "Curricular and Extracurricular",
        "notes" => "Connects academic learning with community needs and professional practice."
    ],
    [
        "strategy_name" => "Research Projects and Data-Based Assignments",
        "strategy_description" => "Students design research questions, review literature, collect or analyze data, interpret findings, and prepare research reports or presentations.",
        "related_plo_codes" => "S3, S4, V2",
        "curricular_or_extra" => "Curricular",
        "notes" => "Supports research competency and ethical use of data."
    ],
    [
        "strategy_name" => "E-Learning and Digital Tools",
        "strategy_description" => "Use of learning management systems, online resources, digital assignments, quizzes, discussion boards, databases, and presentation technologies.",
        "related_plo_codes" => "S4",
        "curricular_or_extra" => "Curricular",
        "notes" => "Supports digital competency and flexible learning."
    ],
    [
        "strategy_name" => "Seminars and Student Presentations",
        "strategy_description" => "Students prepare and deliver presentations on public health and health promotion topics using evidence-based sources.",
        "related_plo_codes" => "K1, K2, S2, S3, S4, V1",
        "curricular_or_extra" => "Curricular",
        "notes" => "Supports oral communication, evidence use, and professional presentation skills."
    ],
    [
        "strategy_name" => "Internship and Supervised Field Training",
        "strategy_description" => "Students apply program knowledge and skills in supervised professional field settings and receive feedback from academic and field supervisors.",
        "related_plo_codes" => "S1, S2, S3, S4, S5, V1, V2",
        "curricular_or_extra" => "Curricular",
        "notes" => "Used to assess readiness for health promotion practice."
    ]
];

$assessmentRows = [
    [
        "assessment_method" => "Written Examinations",
        "assessment_type" => "Direct",
        "assessment_description" => "Midterm and final examinations using MCQs, short-answer questions, structured questions, and applied scenarios.",
        "related_plo_codes" => "K1, K2",
        "notes" => "Used mainly for knowledge and understanding outcomes."
    ],
    [
        "assessment_method" => "Quizzes",
        "assessment_type" => "Direct",
        "assessment_description" => "Short quizzes used to monitor student progress and understanding during the semester.",
        "related_plo_codes" => "K1, K2",
        "notes" => "Useful for continuous formative and summative assessment."
    ],
    [
        "assessment_method" => "Assignments and Reports",
        "assessment_type" => "Direct",
        "assessment_description" => "Written assignments, literature-based tasks, case reports, and applied health promotion planning reports.",
        "related_plo_codes" => "K2, S1, S3, S4, V2",
        "notes" => "Assesses application, research, writing, and ethical use of evidence."
    ],
    [
        "assessment_method" => "Practical Assessment",
        "assessment_type" => "Direct",
        "assessment_description" => "Assessment of practical tasks, applied activities, educational material development, data interpretation, and intervention planning.",
        "related_plo_codes" => "S1, S2, S3, S4, S5",
        "notes" => "Used in applied and skills-based courses."
    ],
    [
        "assessment_method" => "Oral Presentations",
        "assessment_type" => "Direct",
        "assessment_description" => "Individual or group presentations assessed using rubrics for content, evidence use, communication, and professionalism.",
        "related_plo_codes" => "S2, S3, S4, V1",
        "notes" => "Assesses communication and professional presentation skills."
    ],
    [
        "assessment_method" => "Project Assessment",
        "assessment_type" => "Direct",
        "assessment_description" => "Assessment of graduation projects, research reports, project outputs, and defense or presentation.",
        "related_plo_codes" => "S1, S3, S4, S5, V1, V2",
        "notes" => "Used for advanced integration and mastery of program outcomes."
    ],
    [
        "assessment_method" => "Field Training / Internship Evaluation",
        "assessment_type" => "Direct",
        "assessment_description" => "Evaluation by academic and field supervisors using structured rubrics and training reports.",
        "related_plo_codes" => "S1, S2, S3, S4, S5, V1, V2",
        "notes" => "Assesses professional readiness and workplace performance."
    ],
    [
        "assessment_method" => "Student Course Evaluation Survey",
        "assessment_type" => "Indirect",
        "assessment_description" => "Student feedback on course quality, teaching, assessment, learning resources, and overall learning experience.",
        "related_plo_codes" => "K1, K2, S1, S2, S3, S4, S5, V1, V2",
        "notes" => "Used as indirect evidence for continuous improvement."
    ],
    [
        "assessment_method" => "Employer and Alumni Surveys",
        "assessment_type" => "Indirect",
        "assessment_description" => "Feedback from employers and alumni regarding graduate competencies, employability, and program relevance.",
        "related_plo_codes" => "S1, S2, S3, S4, S5, V1, V2",
        "notes" => "Used for program-level quality evaluation."
    ]
];

$markRows = [
    [
        "course_type" => "Theoretical Course",
        "assessment_method" => "Midterm Examination",
        "marks" => 20,
        "percentage" => 20,
        "display_order" => 1,
        "notes" => "Standard suggested distribution; may be adjusted by approved course specification."
    ],
    [
        "course_type" => "Theoretical Course",
        "assessment_method" => "Assignments / Quizzes / Participation",
        "marks" => 20,
        "percentage" => 20,
        "display_order" => 2,
        "notes" => "Continuous assessment components."
    ],
    [
        "course_type" => "Theoretical Course",
        "assessment_method" => "Final Examination",
        "marks" => 60,
        "percentage" => 60,
        "display_order" => 3,
        "notes" => "Final summative assessment."
    ],
    [
        "course_type" => "Theory and Practical Course",
        "assessment_method" => "Midterm Examination",
        "marks" => 20,
        "percentage" => 20,
        "display_order" => 1,
        "notes" => "Theory component."
    ],
    [
        "course_type" => "Theory and Practical Course",
        "assessment_method" => "Practical / Applied Assessment",
        "marks" => 30,
        "percentage" => 30,
        "display_order" => 2,
        "notes" => "Practical skills and applied tasks."
    ],
    [
        "course_type" => "Theory and Practical Course",
        "assessment_method" => "Final Examination",
        "marks" => 50,
        "percentage" => 50,
        "display_order" => 3,
        "notes" => "Final summative assessment."
    ],
    [
        "course_type" => "Graduation Project",
        "assessment_method" => "Research Proposal / Progress Work",
        "marks" => 30,
        "percentage" => 30,
        "display_order" => 1,
        "notes" => "Planning, supervision progress, and research development."
    ],
    [
        "course_type" => "Graduation Project",
        "assessment_method" => "Final Report",
        "marks" => 40,
        "percentage" => 40,
        "display_order" => 2,
        "notes" => "Written research/project report."
    ],
    [
        "course_type" => "Graduation Project",
        "assessment_method" => "Presentation / Defense",
        "marks" => 30,
        "percentage" => 30,
        "display_order" => 3,
        "notes" => "Oral presentation and discussion."
    ],
    [
        "course_type" => "Internship",
        "assessment_method" => "Field Supervisor Evaluation",
        "marks" => 50,
        "percentage" => 50,
        "display_order" => 1,
        "notes" => "Performance in field training site."
    ],
    [
        "course_type" => "Internship",
        "assessment_method" => "Academic Supervisor Evaluation",
        "marks" => 25,
        "percentage" => 25,
        "display_order" => 2,
        "notes" => "Academic follow-up and professional conduct."
    ],
    [
        "course_type" => "Internship",
        "assessment_method" => "Training Report / Portfolio",
        "marks" => 25,
        "percentage" => 25,
        "display_order" => 3,
        "notes" => "Documentation of learning, activities, and reflection."
    ]
];

try {
    $conn->beginTransaction();

    $deleteTeachingStmt = $conn->prepare("
        DELETE FROM program_specification_teaching_strategies
        WHERE program_specification_id = :program_specification_id
    ");
    $deleteTeachingStmt->execute([":program_specification_id" => $spec_id]);

    $deleteAssessmentStmt = $conn->prepare("
        DELETE FROM program_specification_assessment_methods
        WHERE program_specification_id = :program_specification_id
    ");
    $deleteAssessmentStmt->execute([":program_specification_id" => $spec_id]);

    $deleteMarksStmt = $conn->prepare("
        DELETE FROM program_specification_mark_distributions
        WHERE program_specification_id = :program_specification_id
    ");
    $deleteMarksStmt->execute([":program_specification_id" => $spec_id]);

    $insertTeachingStmt = $conn->prepare("
        INSERT INTO program_specification_teaching_strategies
        (
            program_specification_id,
            strategy_name,
            strategy_description,
            related_plo_codes,
            curricular_or_extra,
            notes
        )
        VALUES
        (
            :program_specification_id,
            :strategy_name,
            :strategy_description,
            :related_plo_codes,
            :curricular_or_extra,
            :notes
        )
    ");

    $teachingInserted = 0;

    foreach ($teachingRows as $row) {
        $insertTeachingStmt->execute([
            ":program_specification_id" => $spec_id,
            ":strategy_name" => $row["strategy_name"],
            ":strategy_description" => $row["strategy_description"],
            ":related_plo_codes" => $row["related_plo_codes"],
            ":curricular_or_extra" => $row["curricular_or_extra"],
            ":notes" => $row["notes"]
        ]);

        $teachingInserted++;
    }

    $insertAssessmentStmt = $conn->prepare("
        INSERT INTO program_specification_assessment_methods
        (
            program_specification_id,
            assessment_method,
            assessment_type,
            assessment_description,
            related_plo_codes,
            notes
        )
        VALUES
        (
            :program_specification_id,
            :assessment_method,
            :assessment_type,
            :assessment_description,
            :related_plo_codes,
            :notes
        )
    ");

    $assessmentInserted = 0;

    foreach ($assessmentRows as $row) {
        $insertAssessmentStmt->execute([
            ":program_specification_id" => $spec_id,
            ":assessment_method" => $row["assessment_method"],
            ":assessment_type" => $row["assessment_type"],
            ":assessment_description" => $row["assessment_description"],
            ":related_plo_codes" => $row["related_plo_codes"],
            ":notes" => $row["notes"]
        ]);

        $assessmentInserted++;
    }

    $insertMarkStmt = $conn->prepare("
        INSERT INTO program_specification_mark_distributions
        (
            program_specification_id,
            course_type,
            assessment_method,
            marks,
            percentage,
            display_order,
            notes
        )
        VALUES
        (
            :program_specification_id,
            :course_type,
            :assessment_method,
            :marks,
            :percentage,
            :display_order,
            :notes
        )
    ");

    $marksInserted = 0;

    foreach ($markRows as $row) {
        $insertMarkStmt->execute([
            ":program_specification_id" => $spec_id,
            ":course_type" => $row["course_type"],
            ":assessment_method" => $row["assessment_method"],
            ":marks" => $row["marks"],
            ":percentage" => $row["percentage"],
            ":display_order" => $row["display_order"],
            ":notes" => $row["notes"]
        ]);

        $marksInserted++;
    }

    $conn->commit();

    logActivitySafe(
        $conn,
        "Import",
        "Program Specification Teaching and Assessment",
        $spec_id,
        "Default HPE teaching/assessment imported. Teaching: " . $teachingInserted .
        " | Assessment: " . $assessmentInserted .
        " | Mark rows: " . $marksInserted
    );

    header("Location: manage_program_specification_teaching_assessment.php?id=" . $spec_id);
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    die("Import failed: " . safeText($e->getMessage()));
}
