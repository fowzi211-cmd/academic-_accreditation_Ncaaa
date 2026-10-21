<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty"]);

$report_id = 0;

if (isset($_GET["id"]) && is_numeric($_GET["id"])) {
    $report_id = (int)$_GET["id"];
} elseif (isset($_GET["report_id"]) && is_numeric($_GET["report_id"])) {
    $report_id = (int)$_GET["report_id"];
}

if ($report_id <= 0) {
    die("Invalid course report ID.");
}

$error = "";
$success = "";

function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function nlText($text) {
    return nl2br(htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8"));
}

function nullIfEmpty($value) {
    $value = trim((string)$value);
    return $value === "" ? null : $value;
}

function intValue($value) {
    if ($value === "" || $value === null) {
        return 0;
    }

    return is_numeric($value) ? (int)$value : 0;
}

function floatValueOrNull($value) {
    $value = trim((string)$value);

    if ($value === "") {
        return null;
    }

    return is_numeric($value) ? (float)$value : null;
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

function ensureCourseReportDetailTables($conn) {
    requireSchemaTable($conn, "course_report_grade_distribution");

    addColumnIfMissing($conn, "course_report_grade_distribution", "course_report_id", "INT NOT NULL");
    addColumnIfMissing($conn, "course_report_grade_distribution", "grade_label", "VARCHAR(50) NULL");
    addColumnIfMissing($conn, "course_report_grade_distribution", "student_count", "INT DEFAULT 0");
    addColumnIfMissing($conn, "course_report_grade_distribution", "percentage", "DECIMAL(6,2) NULL");
    addColumnIfMissing($conn, "course_report_grade_distribution", "notes", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_grade_distribution", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

    requireSchemaTable($conn, "course_report_clo_results");

    addColumnIfMissing($conn, "course_report_clo_results", "course_report_id", "INT NOT NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "clo_code", "VARCHAR(50) NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "clo_text", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "target_score", "DECIMAL(6,2) NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "actual_score", "DECIMAL(6,2) NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "achievement_percentage", "DECIMAL(6,2) NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "status", "VARCHAR(100) NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "comments", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_clo_results", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

    requireSchemaTable($conn, "course_report_exam_alignment");

    addColumnIfMissing($conn, "course_report_exam_alignment", "course_report_id", "INT NOT NULL");
    addColumnIfMissing($conn, "course_report_exam_alignment", "assessment_component", "VARCHAR(255) NULL");
    addColumnIfMissing($conn, "course_report_exam_alignment", "related_clo", "VARCHAR(255) NULL");
    addColumnIfMissing($conn, "course_report_exam_alignment", "max_score", "DECIMAL(8,2) NULL");
    addColumnIfMissing($conn, "course_report_exam_alignment", "average_score", "DECIMAL(8,2) NULL");
    addColumnIfMissing($conn, "course_report_exam_alignment", "alignment_notes", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_exam_alignment", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

    requireSchemaTable($conn, "course_report_topics_not_covered");

    addColumnIfMissing($conn, "course_report_topics_not_covered", "course_report_id", "INT NOT NULL");
    addColumnIfMissing($conn, "course_report_topics_not_covered", "topic_title", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_topics_not_covered", "reason_not_covered", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_topics_not_covered", "corrective_action", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_topics_not_covered", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

    requireSchemaTable($conn, "course_report_previous_improvement_actions");

    addColumnIfMissing($conn, "course_report_previous_improvement_actions", "course_report_id", "INT NOT NULL");
    addColumnIfMissing($conn, "course_report_previous_improvement_actions", "previous_recommendation", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_previous_improvement_actions", "action_taken", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_previous_improvement_actions", "status", "VARCHAR(100) NULL");
    addColumnIfMissing($conn, "course_report_previous_improvement_actions", "evidence", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_previous_improvement_actions", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

    requireSchemaTable($conn, "course_report_improvement_plan");

    addColumnIfMissing($conn, "course_report_improvement_plan", "course_report_id", "INT NOT NULL");
    addColumnIfMissing($conn, "course_report_improvement_plan", "improvement_area", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_improvement_plan", "proposed_action", "TEXT NULL");
    addColumnIfMissing($conn, "course_report_improvement_plan", "responsible_person", "VARCHAR(255) NULL");
    addColumnIfMissing($conn, "course_report_improvement_plan", "target_date", "DATE NULL");
    addColumnIfMissing($conn, "course_report_improvement_plan", "priority", "VARCHAR(50) NULL");
    addColumnIfMissing($conn, "course_report_improvement_plan", "follow_up_status", "VARCHAR(100) NULL");
    addColumnIfMissing($conn, "course_report_improvement_plan", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

function loadRowsByReport($conn, $tableName, $reportId) {
    if (!tableExists($conn, $tableName)) {
        return [];
    }

    $fk = columnExists($conn, $tableName, "course_report_id") ? "course_report_id" : "report_id";

    $stmt = $conn->prepare("
        SELECT *
        FROM `$tableName`
        WHERE `$fk` = :report_id
        ORDER BY id ASC
    ");
    $stmt->execute([":report_id" => $reportId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function countRowsByReport($conn, $tableName, $reportId) {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    $fk = columnExists($conn, $tableName, "course_report_id") ? "course_report_id" : "report_id";

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM `$tableName`
        WHERE `$fk` = :report_id
    ");
    $stmt->execute([":report_id" => $reportId]);

    return (int)$stmt->fetchColumn();
}

function countSpecRows($conn, $tableName, $courseSpecificationId) {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    if (!columnExists($conn, $tableName, "course_specification_id")) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM `$tableName`
        WHERE course_specification_id = :id
    ");
    $stmt->execute([":id" => $courseSpecificationId]);

    return (int)$stmt->fetchColumn();
}

function rowExistsByField($conn, $tableName, $reportId, $fieldName, $fieldValue) {
    if (!tableExists($conn, $tableName)) {
        return false;
    }

    if (!columnExists($conn, $tableName, $fieldName)) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM `$tableName`
        WHERE course_report_id = :report_id
          AND `$fieldName` = :field_value
    ");
    $stmt->execute([
        ":report_id" => $reportId,
        ":field_value" => $fieldValue
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

if (!tableExists($conn, "course_reports")) {
    die("course_reports table does not exist.");
}

ensureCourseReportDetailTables($conn);

/*
    Load course report with linked specification.
*/
$stmt = $conn->prepare("
    SELECT 
        cr.*,
        cs.id AS spec_id,
        cs.course_code AS spec_course_code,
        cs.course_title AS spec_course_title,
        cs.version_no AS spec_version,
        cs.status AS spec_status,
        cs.approval_status AS spec_approval_status,
        cs.program_specification_id AS program_specification_id
    FROM course_reports cr
    LEFT JOIN course_specifications cs
        ON cs.id = cr.course_specification_id
    WHERE cr.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $report_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die("Course report not found.");
}

$course_specification_id = isset($report["course_specification_id"]) && is_numeric($report["course_specification_id"])
    ? (int)$report["course_specification_id"]
    : 0;

$program_specification_id = isset($report["program_specification_id"]) && is_numeric($report["program_specification_id"])
    ? (int)$report["program_specification_id"]
    : 3;

/*
    Load linked specification CLOs / topics / assessments.
*/
$specClos = [];
$specTopics = [];
$specAssessments = [];

if ($course_specification_id > 0) {
    if (tableExists($conn, "course_specification_clos")) {
        $stmt = $conn->prepare("
            SELECT *
            FROM course_specification_clos
            WHERE course_specification_id = :id
            ORDER BY display_order ASC, id ASC
        ");
        $stmt->execute([":id" => $course_specification_id]);
        $specClos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (tableExists($conn, "course_specification_topics")) {
        $stmt = $conn->prepare("
            SELECT *
            FROM course_specification_topics
            WHERE course_specification_id = :id
            ORDER BY display_order ASC, topic_no ASC, id ASC
        ");
        $stmt->execute([":id" => $course_specification_id]);
        $specTopics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (tableExists($conn, "course_specification_assessments")) {
        $stmt = $conn->prepare("
            SELECT *
            FROM course_specification_assessments
            WHERE course_specification_id = :id
            ORDER BY display_order ASC, assessment_no ASC, id ASC
        ");
        $stmt->execute([":id" => $course_specification_id]);
        $specAssessments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/*
    Handle POST actions.
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    try {
        if ($action === "add_grade") {
            $gradeLabel = trim($_POST["grade_label"] ?? "");
            $studentCount = intValue($_POST["student_count"] ?? 0);
            $percentage = floatValueOrNull($_POST["percentage"] ?? "");
            $notes = nullIfEmpty($_POST["notes"] ?? "");

            if ($gradeLabel === "") {
                throw new Exception("Grade label is required.");
            }

            if ($percentage === null) {
                $enrolled = (int)($report["students_enrolled"] ?? 0);
                $percentage = $enrolled > 0 ? round(($studentCount / $enrolled) * 100, 2) : 0;
            }

            $stmt = $conn->prepare("
                INSERT INTO course_report_grade_distribution
                (course_report_id, grade_label, student_count, percentage, notes)
                VALUES
                (:course_report_id, :grade_label, :student_count, :percentage, :notes)
            ");
            $stmt->execute([
                ":course_report_id" => $report_id,
                ":grade_label" => $gradeLabel,
                ":student_count" => $studentCount,
                ":percentage" => $percentage,
                ":notes" => $notes
            ]);

            $success = "Grade distribution row added.";
        }

        if ($action === "add_clo_result") {
            $cloCode = trim($_POST["clo_code"] ?? "");
            $cloText = nullIfEmpty($_POST["clo_text"] ?? "");
            $targetScore = floatValueOrNull($_POST["target_score"] ?? "");
            $actualScore = floatValueOrNull($_POST["actual_score"] ?? "");
            $achievement = floatValueOrNull($_POST["achievement_percentage"] ?? "");
            $status = nullIfEmpty($_POST["status"] ?? "");
            $comments = nullIfEmpty($_POST["comments"] ?? "");

            if ($cloCode === "") {
                throw new Exception("CLO code is required.");
            }

            if ($achievement === null && $targetScore !== null && $targetScore > 0 && $actualScore !== null) {
                $achievement = round(($actualScore / $targetScore) * 100, 2);
            }

            if ($status === null && $achievement !== null) {
                $status = $achievement >= 70 ? "Achieved" : "Not Achieved";
            }

            $stmt = $conn->prepare("
                INSERT INTO course_report_clo_results
                (course_report_id, clo_code, clo_text, target_score, actual_score, achievement_percentage, status, comments)
                VALUES
                (:course_report_id, :clo_code, :clo_text, :target_score, :actual_score, :achievement_percentage, :status, :comments)
            ");
            $stmt->execute([
                ":course_report_id" => $report_id,
                ":clo_code" => $cloCode,
                ":clo_text" => $cloText,
                ":target_score" => $targetScore,
                ":actual_score" => $actualScore,
                ":achievement_percentage" => $achievement,
                ":status" => $status,
                ":comments" => $comments
            ]);

            $success = "CLO achievement result added.";
        }

        if ($action === "generate_clo_rows") {
            if (count($specClos) === 0) {
                throw new Exception("No CLOs found in the linked course specification.");
            }

            $created = 0;

            $stmt = $conn->prepare("
                INSERT INTO course_report_clo_results
                (course_report_id, clo_code, clo_text, target_score, actual_score, achievement_percentage, status, comments)
                VALUES
                (:course_report_id, :clo_code, :clo_text, NULL, NULL, NULL, 'Pending', NULL)
            ");

            foreach ($specClos as $clo) {
                $cloCode = trim((string)($clo["clo_code"] ?? ""));
                $cloText = trim((string)($clo["clo_text"] ?? ""));

                if ($cloCode === "") {
                    continue;
                }

                if (rowExistsByField($conn, "course_report_clo_results", $report_id, "clo_code", $cloCode)) {
                    continue;
                }

                $stmt->execute([
                    ":course_report_id" => $report_id,
                    ":clo_code" => $cloCode,
                    ":clo_text" => $cloText
                ]);

                $created++;
            }

            $success = $created . " CLO result row(s) generated from linked course specification.";
        }

        if ($action === "add_exam_alignment") {
            $assessmentComponent = trim($_POST["assessment_component"] ?? "");
            $relatedClo = nullIfEmpty($_POST["related_clo"] ?? "");
            $maxScore = floatValueOrNull($_POST["max_score"] ?? "");
            $averageScore = floatValueOrNull($_POST["average_score"] ?? "");
            $alignmentNotes = nullIfEmpty($_POST["alignment_notes"] ?? "");

            if ($assessmentComponent === "") {
                throw new Exception("Assessment component is required.");
            }

            $stmt = $conn->prepare("
                INSERT INTO course_report_exam_alignment
                (course_report_id, assessment_component, related_clo, max_score, average_score, alignment_notes)
                VALUES
                (:course_report_id, :assessment_component, :related_clo, :max_score, :average_score, :alignment_notes)
            ");
            $stmt->execute([
                ":course_report_id" => $report_id,
                ":assessment_component" => $assessmentComponent,
                ":related_clo" => $relatedClo,
                ":max_score" => $maxScore,
                ":average_score" => $averageScore,
                ":alignment_notes" => $alignmentNotes
            ]);

            $success = "Exam / assessment alignment row added.";
        }

        if ($action === "generate_assessment_alignment_rows") {
            if (count($specAssessments) === 0) {
                throw new Exception("No assessment activities found in the linked course specification.");
            }

            $created = 0;

            $stmt = $conn->prepare("
                INSERT INTO course_report_exam_alignment
                (course_report_id, assessment_component, related_clo, max_score, average_score, alignment_notes)
                VALUES
                (:course_report_id, :assessment_component, NULL, NULL, NULL, 'Generated from course specification assessment plan.')
            ");

            foreach ($specAssessments as $assessment) {
                $activity = trim((string)($assessment["assessment_activity"] ?? ""));

                if ($activity === "") {
                    continue;
                }

                if (rowExistsByField($conn, "course_report_exam_alignment", $report_id, "assessment_component", $activity)) {
                    continue;
                }

                $stmt->execute([
                    ":course_report_id" => $report_id,
                    ":assessment_component" => $activity
                ]);

                $created++;
            }

            $success = $created . " assessment alignment row(s) generated.";
        }

        if ($action === "add_topic_not_covered") {
            $topicTitle = trim($_POST["topic_title"] ?? "");
            $reason = nullIfEmpty($_POST["reason_not_covered"] ?? "");
            $correctiveAction = nullIfEmpty($_POST["corrective_action"] ?? "");

            if ($topicTitle === "") {
                throw new Exception("Topic title is required.");
            }

            $stmt = $conn->prepare("
                INSERT INTO course_report_topics_not_covered
                (course_report_id, topic_title, reason_not_covered, corrective_action)
                VALUES
                (:course_report_id, :topic_title, :reason_not_covered, :corrective_action)
            ");
            $stmt->execute([
                ":course_report_id" => $report_id,
                ":topic_title" => $topicTitle,
                ":reason_not_covered" => $reason,
                ":corrective_action" => $correctiveAction
            ]);

            $success = "Topic not covered row added.";
        }

        if ($action === "mark_all_topics_covered") {
            if (!rowExistsByField($conn, "course_report_topics_not_covered", $report_id, "topic_title", "All planned topics were covered")) {
                $stmt = $conn->prepare("
                    INSERT INTO course_report_topics_not_covered
                    (course_report_id, topic_title, reason_not_covered, corrective_action)
                    VALUES
                    (:course_report_id, 'All planned topics were covered', 'Not applicable', 'No corrective action required')
                ");
                $stmt->execute([":course_report_id" => $report_id]);
            }

            $success = "Topics not covered section marked as completed.";
        }

        if ($action === "add_previous_action") {
            $previousRecommendation = trim($_POST["previous_recommendation"] ?? "");
            $actionTaken = nullIfEmpty($_POST["action_taken"] ?? "");
            $status = nullIfEmpty($_POST["status"] ?? "");
            $evidence = nullIfEmpty($_POST["evidence"] ?? "");

            if ($previousRecommendation === "") {
                throw new Exception("Previous recommendation is required.");
            }

            $stmt = $conn->prepare("
                INSERT INTO course_report_previous_improvement_actions
                (course_report_id, previous_recommendation, action_taken, status, evidence)
                VALUES
                (:course_report_id, :previous_recommendation, :action_taken, :status, :evidence)
            ");
            $stmt->execute([
                ":course_report_id" => $report_id,
                ":previous_recommendation" => $previousRecommendation,
                ":action_taken" => $actionTaken,
                ":status" => $status,
                ":evidence" => $evidence
            ]);

            $success = "Previous improvement action row added.";
        }

        if ($action === "add_improvement_plan") {
            $improvementArea = trim($_POST["improvement_area"] ?? "");
            $proposedAction = nullIfEmpty($_POST["proposed_action"] ?? "");
            $responsiblePerson = nullIfEmpty($_POST["responsible_person"] ?? "");
            $targetDate = nullIfEmpty($_POST["target_date"] ?? "");
            $priority = nullIfEmpty($_POST["priority"] ?? "");
            $followUpStatus = nullIfEmpty($_POST["follow_up_status"] ?? "");

            if ($improvementArea === "") {
                throw new Exception("Improvement area is required.");
            }

            $stmt = $conn->prepare("
                INSERT INTO course_report_improvement_plan
                (course_report_id, improvement_area, proposed_action, responsible_person, target_date, priority, follow_up_status)
                VALUES
                (:course_report_id, :improvement_area, :proposed_action, :responsible_person, :target_date, :priority, :follow_up_status)
            ");
            $stmt->execute([
                ":course_report_id" => $report_id,
                ":improvement_area" => $improvementArea,
                ":proposed_action" => $proposedAction,
                ":responsible_person" => $responsiblePerson,
                ":target_date" => $targetDate,
                ":priority" => $priority,
                ":follow_up_status" => $followUpStatus
            ]);

            $success = "Improvement plan row added.";
        }

        if ($action === "delete_row") {
            $table = $_POST["table"] ?? "";
            $rowId = isset($_POST["row_id"]) && is_numeric($_POST["row_id"]) ? (int)$_POST["row_id"] : 0;

            $allowedTables = [
                "course_report_grade_distribution",
                "course_report_clo_results",
                "course_report_exam_alignment",
                "course_report_topics_not_covered",
                "course_report_previous_improvement_actions",
                "course_report_improvement_plan"
            ];

            if (!in_array($table, $allowedTables, true)) {
                throw new Exception("Invalid table.");
            }

            if ($rowId <= 0) {
                throw new Exception("Invalid row ID.");
            }

            $stmt = $conn->prepare("
                DELETE FROM `$table`
                WHERE id = :id
                  AND course_report_id = :course_report_id
            ");
            $stmt->execute([
                ":id" => $rowId,
                ":course_report_id" => $report_id
            ]);

            $success = "Selected row deleted.";
        }

        if ($success !== "") {
            logActivitySafe(
                $conn,
                "Update",
                "Course Report Details",
                $report_id,
                $success . " Report ID: " . $report_id
            );
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/*
    Load detail rows after possible changes.
*/
$gradeRows = loadRowsByReport($conn, "course_report_grade_distribution", $report_id);
$cloRows = loadRowsByReport($conn, "course_report_clo_results", $report_id);
$examRows = loadRowsByReport($conn, "course_report_exam_alignment", $report_id);
$topicsNotCoveredRows = loadRowsByReport($conn, "course_report_topics_not_covered", $report_id);
$previousActionRows = loadRowsByReport($conn, "course_report_previous_improvement_actions", $report_id);
$improvementPlanRows = loadRowsByReport($conn, "course_report_improvement_plan", $report_id);

$gradeCount = count($gradeRows);
$cloCount = count($cloRows);
$examCount = count($examRows);
$topicsNotCoveredCount = count($topicsNotCoveredRows);
$previousActionCount = count($previousActionRows);
$improvementPlanCount = count($improvementPlanRows);

$detailCompletionChecks = [
    $gradeCount > 0,
    $cloCount > 0,
    $examCount > 0,
    $topicsNotCoveredCount > 0,
    $previousActionCount > 0,
    $improvementPlanCount > 0
];

$completedDetailSections = 0;

foreach ($detailCompletionChecks as $check) {
    if ($check) {
        $completedDetailSections++;
    }
}

$detailCompletionPercent = round(($completedDetailSections / 6) * 100, 1);

function sectionBadge($count) {
    return $count > 0 ? "badge-ok" : "badge-danger";
}

function sectionText($count) {
    return $count > 0 ? "Completed" : "Missing";
}

$priorityOptions = ["High", "Medium", "Low"];
$followUpOptions = ["Planned", "In Progress", "Completed", "Deferred"];
$previousActionStatusOptions = ["Completed", "Partially Completed", "Not Completed", "Ongoing"];
$cloStatusOptions = ["Achieved", "Partially Achieved", "Not Achieved", "Pending"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Course Report Details</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(130px, 1fr));
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
            font-size: 25px;
            font-weight: bold;
            color: #007bff;
        }

        .summary-label {
            color: #555;
            font-size: 13px;
            margin-top: 6px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-bottom: 18px;
        }

        .actions a,
        .actions button {
            display: inline-block;
            width: auto;
            text-align: center;
            margin-bottom: 0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 14px;
        }

        textarea {
            min-height: 80px;
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

        .badge-ok {
            background: #d1e7dd;
            color: #0f5132;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }

        .badge-warning {
            background: #fff3cd;
            color: #664d03;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }

        .badge-danger {
            background: #f8d7da;
            color: #842029;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .wide-table {
            min-width: 1200px;
            width: 100%;
            border-collapse: collapse;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .progress-wrap {
            background: #e9ecef;
            border-radius: 999px;
            height: 24px;
            overflow: hidden;
            border: 1px solid #ccc;
            margin-bottom: 12px;
        }

        .progress-bar {
            height: 24px;
            background: #0d6efd;
            color: white;
            text-align: center;
            line-height: 24px;
            font-weight: bold;
            font-size: 12px;
        }

        @media (max-width: 1000px) {
            .summary-grid,
            .form-grid {
                grid-template-columns: repeat(2, minmax(130px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .summary-grid,
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Manage Course Report Details</h1>
        <p>Central page for grade distribution, CLO achievement, assessment alignment, topics not covered, previous actions, and improvement plan.</p>
    </div>

    <div class="actions">
        <a href="view_course_report.php?id=<?php echo safeText($report_id); ?>" class="btn btn-purple">
            View Report
        </a>

        <a href="course_report_completion_check.php?id=<?php echo safeText($report_id); ?>" class="btn">
            Completion Check
        </a>

        <a href="edit_course_report.php?id=<?php echo safeText($report_id); ?>" class="btn btn-edit">
            Edit Main Report
        </a>

        <a href="export_course_report_word.php?id=<?php echo safeText($report_id); ?>" class="btn btn-purple">
            Export Word
        </a>

        <a href="course_report_dashboard.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Dashboard
        </a>

        <a href="course_reports.php" class="btn btn-secondary">
            Course Reports
        </a>
	<a href="import_course_report_student_results.php?id=<?php echo safeText($report_id); ?>" class="btn btn-purple">
    Import Student Results
</a>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error"><?php echo safeText($error); ?></div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="alert-success"><?php echo safeText($success); ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Course Report</h2>

        <table>
            <tr>
                <th>Report ID</th>
                <td><?php echo safeText($report_id); ?></td>
                <th>Course</th>
                <td>
                    <strong><?php echo safeText($report["course_code"] ?? ""); ?></strong>
                    -
                    <?php echo safeText($report["course_title"] ?? ""); ?>
                </td>
            </tr>
            <tr>
                <th>Academic Year</th>
                <td><?php echo safeText($report["academic_year"] ?? ""); ?></td>
                <th>Semester</th>
                <td><?php echo safeText($report["semester"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Linked Specification</th>
                <td colspan="3">
                    <?php if ($course_specification_id > 0): ?>
                        <span class="badge-ok">Linked</span>
                        ID <?php echo safeText($course_specification_id); ?> |
                        <?php echo safeText($report["spec_course_code"] ?? ""); ?>
                        -
                        <?php echo safeText($report["spec_course_title"] ?? ""); ?>
                    <?php else: ?>
                        <span class="badge-danger">Not Linked</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Detail Completion Progress</h2>

        <div class="progress-wrap">
            <div class="progress-bar" style="width: <?php echo safeText($detailCompletionPercent); ?>%;">
                <?php echo safeText($detailCompletionPercent); ?>%
            </div>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($gradeCount); ?></div>
            <div class="summary-label">Grade Rows</div>
            <span class="<?php echo safeText(sectionBadge($gradeCount)); ?>"><?php echo safeText(sectionText($gradeCount)); ?></span>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($cloCount); ?></div>
            <div class="summary-label">CLO Result Rows</div>
            <span class="<?php echo safeText(sectionBadge($cloCount)); ?>"><?php echo safeText(sectionText($cloCount)); ?></span>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($examCount); ?></div>
            <div class="summary-label">Exam Alignment Rows</div>
            <span class="<?php echo safeText(sectionBadge($examCount)); ?>"><?php echo safeText(sectionText($examCount)); ?></span>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($topicsNotCoveredCount); ?></div>
            <div class="summary-label">Topics Not Covered</div>
            <span class="<?php echo safeText(sectionBadge($topicsNotCoveredCount)); ?>"><?php echo safeText(sectionText($topicsNotCoveredCount)); ?></span>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($previousActionCount); ?></div>
            <div class="summary-label">Previous Actions</div>
            <span class="<?php echo safeText(sectionBadge($previousActionCount)); ?>"><?php echo safeText(sectionText($previousActionCount)); ?></span>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($improvementPlanCount); ?></div>
            <div class="summary-label">Improvement Plan</div>
            <span class="<?php echo safeText(sectionBadge($improvementPlanCount)); ?>"><?php echo safeText(sectionText($improvementPlanCount)); ?></span>
        </div>
    </div>

    <div class="panel">
        <h2>Quick Generate from Linked Specification</h2>

        <div class="actions">
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="generate_clo_rows">
                <button type="submit" class="btn btn-purple">
                    Generate CLO Result Rows from Specification
                </button>
            </form>

            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="generate_assessment_alignment_rows">
                <button type="submit" class="btn">
                    Generate Assessment Alignment Rows
                </button>
            </form>

            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="mark_all_topics_covered">
                <button type="submit" class="btn">
                    Mark All Topics Covered
                </button>
            </form>
		<a href="import_course_report_student_results.php?id=<?php echo safeText($report_id); ?>" class="btn btn-purple">
    Import Student Results
</a>
        </div>

        <p class="small-text">
            Linked specification source: <?php echo safeText(count($specClos)); ?> CLOs,
            <?php echo safeText(count($specTopics)); ?> topics,
            <?php echo safeText(count($specAssessments)); ?> assessments.
        </p>
    </div>

    <div class="panel">
        <h2>1. Grade Distribution</h2>

        <form method="POST">
            <input type="hidden" name="action" value="add_grade">

            <div class="form-grid">
                <div>
                    <label>Grade Label</label>
                    <input type="text" name="grade_label" placeholder="A, B+, C, F">
                </div>

                <div>
                    <label>Student Count</label>
                    <input type="number" name="student_count" min="0" value="0">
                </div>

                <div>
                    <label>Percentage</label>
                    <input type="number" name="percentage" step="0.01" placeholder="Auto if empty">
                </div>
            </div>

            <label>Notes</label>
            <textarea name="notes"></textarea>

            <button type="submit" class="btn btn-purple">Add Grade Row</button>
        </form>

        <br>

        <table>
            <tr>
                <th>Grade</th>
                <th>Students</th>
                <th>Percentage</th>
                <th>Notes</th>
                <th>Action</th>
            </tr>

            <?php if (count($gradeRows) > 0): ?>
                <?php foreach ($gradeRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["grade_label"] ?? ""); ?></td>
                        <td><?php echo safeText($row["student_count"] ?? ""); ?></td>
                        <td><?php echo safeText($row["percentage"] ?? ""); ?>%</td>
                        <td><?php echo nlText($row["notes"] ?? ""); ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Delete this row?');">
                                <input type="hidden" name="action" value="delete_row">
                                <input type="hidden" name="table" value="course_report_grade_distribution">
                                <input type="hidden" name="row_id" value="<?php echo safeText($row["id"]); ?>">
                                <button type="submit" class="btn btn-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5">No grade distribution rows added.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>2. CLO Actual Achievement Results</h2>

        <form method="POST">
            <input type="hidden" name="action" value="add_clo_result">

            <div class="form-grid">
                <div>
                    <label>CLO Code</label>
                    <input type="text" name="clo_code" placeholder="K1, S1, CLO1">
                </div>

                <div>
                    <label>Target Score</label>
                    <input type="number" name="target_score" step="0.01">
                </div>

                <div>
                    <label>Actual Score</label>
                    <input type="number" name="actual_score" step="0.01">
                </div>

                <div>
                    <label>Achievement %</label>
                    <input type="number" name="achievement_percentage" step="0.01" placeholder="Auto if target/actual entered">
                </div>

                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="">Auto / Select</option>
                        <?php foreach ($cloStatusOptions as $opt): ?>
                            <option value="<?php echo safeText($opt); ?>"><?php echo safeText($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label>CLO Text</label>
            <textarea name="clo_text"></textarea>

            <label>Comments</label>
            <textarea name="comments"></textarea>

            <button type="submit" class="btn btn-purple">Add CLO Result</button>
        </form>

        <br>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>CLO Code</th>
                    <th>CLO Text</th>
                    <th>Target</th>
                    <th>Actual</th>
                    <th>Achievement %</th>
                    <th>Status</th>
                    <th>Comments</th>
                    <th>Action</th>
                </tr>

                <?php if (count($cloRows) > 0): ?>
                    <?php foreach ($cloRows as $row): ?>
                        <tr>
                            <td><?php echo safeText($row["clo_code"] ?? ""); ?></td>
                            <td><?php echo nlText($row["clo_text"] ?? ""); ?></td>
                            <td><?php echo safeText($row["target_score"] ?? ""); ?></td>
                            <td><?php echo safeText($row["actual_score"] ?? ""); ?></td>
                            <td><?php echo safeText($row["achievement_percentage"] ?? ""); ?>%</td>
                            <td><?php echo safeText($row["status"] ?? ""); ?></td>
                            <td><?php echo nlText($row["comments"] ?? ""); ?></td>
                            <td>
                                <form method="POST" onsubmit="return confirm('Delete this row?');">
                                    <input type="hidden" name="action" value="delete_row">
                                    <input type="hidden" name="table" value="course_report_clo_results">
                                    <input type="hidden" name="row_id" value="<?php echo safeText($row["id"]); ?>">
                                    <button type="submit" class="btn btn-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8">No CLO result rows added.</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="panel">
        <h2>3. Final Exam / Assessment Alignment</h2>

        <form method="POST">
            <input type="hidden" name="action" value="add_exam_alignment">

            <div class="form-grid">
                <div>
                    <label>Assessment Component</label>
                    <input type="text" name="assessment_component" placeholder="Midterm, Final Exam, Assignment">
                </div>

                <div>
                    <label>Related CLO</label>
                    <input type="text" name="related_clo" placeholder="K1, S2, CLO1">
                </div>

                <div>
                    <label>Max Score</label>
                    <input type="number" name="max_score" step="0.01">
                </div>

                <div>
                    <label>Average Score</label>
                    <input type="number" name="average_score" step="0.01">
                </div>
            </div>

            <label>Alignment Notes</label>
            <textarea name="alignment_notes"></textarea>

            <button type="submit" class="btn btn-purple">Add Alignment Row</button>
        </form>

        <br>

        <table>
            <tr>
                <th>Assessment Component</th>
                <th>Related CLO</th>
                <th>Max Score</th>
                <th>Average Score</th>
                <th>Notes</th>
                <th>Action</th>
            </tr>

            <?php if (count($examRows) > 0): ?>
                <?php foreach ($examRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["assessment_component"] ?? ""); ?></td>
                        <td><?php echo safeText($row["related_clo"] ?? ""); ?></td>
                        <td><?php echo safeText($row["max_score"] ?? ""); ?></td>
                        <td><?php echo safeText($row["average_score"] ?? ""); ?></td>
                        <td><?php echo nlText($row["alignment_notes"] ?? ""); ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Delete this row?');">
                                <input type="hidden" name="action" value="delete_row">
                                <input type="hidden" name="table" value="course_report_exam_alignment">
                                <input type="hidden" name="row_id" value="<?php echo safeText($row["id"]); ?>">
                                <button type="submit" class="btn btn-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6">No assessment alignment rows added.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>4. Topics Not Covered</h2>

        <form method="POST">
            <input type="hidden" name="action" value="add_topic_not_covered">

            <label>Topic Title</label>
            <textarea name="topic_title"></textarea>

            <label>Reason Not Covered</label>
            <textarea name="reason_not_covered"></textarea>

            <label>Corrective Action</label>
            <textarea name="corrective_action"></textarea>

            <button type="submit" class="btn btn-purple">Add Topic Row</button>
        </form>

        <br>

        <table>
            <tr>
                <th>Topic</th>
                <th>Reason</th>
                <th>Corrective Action</th>
                <th>Action</th>
            </tr>

            <?php if (count($topicsNotCoveredRows) > 0): ?>
                <?php foreach ($topicsNotCoveredRows as $row): ?>
                    <tr>
                        <td><?php echo nlText($row["topic_title"] ?? ""); ?></td>
                        <td><?php echo nlText($row["reason_not_covered"] ?? ""); ?></td>
                        <td><?php echo nlText($row["corrective_action"] ?? ""); ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Delete this row?');">
                                <input type="hidden" name="action" value="delete_row">
                                <input type="hidden" name="table" value="course_report_topics_not_covered">
                                <input type="hidden" name="row_id" value="<?php echo safeText($row["id"]); ?>">
                                <button type="submit" class="btn btn-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="4">No topics-not-covered rows added.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>5. Previous Improvement Actions</h2>

        <form method="POST">
            <input type="hidden" name="action" value="add_previous_action">

            <label>Previous Recommendation</label>
            <textarea name="previous_recommendation"></textarea>

            <label>Action Taken</label>
            <textarea name="action_taken"></textarea>

            <div class="form-grid">
                <div>
                    <label>Status</label>
                    <select name="status">
                        <?php foreach ($previousActionStatusOptions as $opt): ?>
                            <option value="<?php echo safeText($opt); ?>"><?php echo safeText($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Evidence</label>
                    <input type="text" name="evidence" placeholder="Minutes, LMS record, exam report">
                </div>
            </div>

            <button type="submit" class="btn btn-purple">Add Previous Action</button>
        </form>

        <br>

        <table>
            <tr>
                <th>Previous Recommendation</th>
                <th>Action Taken</th>
                <th>Status</th>
                <th>Evidence</th>
                <th>Action</th>
            </tr>

            <?php if (count($previousActionRows) > 0): ?>
                <?php foreach ($previousActionRows as $row): ?>
                    <tr>
                        <td><?php echo nlText($row["previous_recommendation"] ?? ""); ?></td>
                        <td><?php echo nlText($row["action_taken"] ?? ""); ?></td>
                        <td><?php echo safeText($row["status"] ?? ""); ?></td>
                        <td><?php echo nlText($row["evidence"] ?? ""); ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Delete this row?');">
                                <input type="hidden" name="action" value="delete_row">
                                <input type="hidden" name="table" value="course_report_previous_improvement_actions">
                                <input type="hidden" name="row_id" value="<?php echo safeText($row["id"]); ?>">
                                <button type="submit" class="btn btn-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5">No previous improvement action rows added.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>6. Course Improvement Plan</h2>

        <form method="POST">
            <input type="hidden" name="action" value="add_improvement_plan">

            <label>Improvement Area</label>
            <textarea name="improvement_area"></textarea>

            <label>Proposed Action</label>
            <textarea name="proposed_action"></textarea>

            <div class="form-grid">
                <div>
                    <label>Responsible Person</label>
                    <input type="text" name="responsible_person">
                </div>

                <div>
                    <label>Target Date</label>
                    <input type="date" name="target_date">
                </div>

                <div>
                    <label>Priority</label>
                    <select name="priority">
                        <?php foreach ($priorityOptions as $opt): ?>
                            <option value="<?php echo safeText($opt); ?>"><?php echo safeText($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Follow-up Status</label>
                    <select name="follow_up_status">
                        <?php foreach ($followUpOptions as $opt): ?>
                            <option value="<?php echo safeText($opt); ?>"><?php echo safeText($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-purple">Add Improvement Plan</button>
        </form>

        <br>

        <table>
            <tr>
                <th>Improvement Area</th>
                <th>Proposed Action</th>
                <th>Responsible</th>
                <th>Target Date</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Action</th>
            </tr>

            <?php if (count($improvementPlanRows) > 0): ?>
                <?php foreach ($improvementPlanRows as $row): ?>
                    <tr>
                        <td><?php echo nlText($row["improvement_area"] ?? ""); ?></td>
                        <td><?php echo nlText($row["proposed_action"] ?? ""); ?></td>
                        <td><?php echo safeText($row["responsible_person"] ?? ""); ?></td>
                        <td><?php echo safeText($row["target_date"] ?? ""); ?></td>
                        <td><?php echo safeText($row["priority"] ?? ""); ?></td>
                        <td><?php echo safeText($row["follow_up_status"] ?? ""); ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Delete this row?');">
                                <input type="hidden" name="action" value="delete_row">
                                <input type="hidden" name="table" value="course_report_improvement_plan">
                                <input type="hidden" name="row_id" value="<?php echo safeText($row["id"]); ?>">
                                <button type="submit" class="btn btn-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7">No improvement plan rows added.</td></tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>
