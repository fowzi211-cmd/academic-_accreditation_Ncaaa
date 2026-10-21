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
$error = "";
$message = "";

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

function firstNonEmpty($a, $b) {
    $a = trim((string)($a ?? ""));
    $b = trim((string)($b ?? ""));

    if ($a !== "") {
        return $a;
    }

    if ($b !== "") {
        return $b;
    }

    return null;
}

function buildPrerequisiteText($course) {
    $parts = [];

    $p1Name = trim((string)($course["prerequisite_1_name"] ?? ""));
    $p1Code = trim((string)($course["prerequisite_1_code"] ?? ""));
    $p2Name = trim((string)($course["prerequisite_2_name"] ?? ""));
    $p2Code = trim((string)($course["prerequisite_2_code"] ?? ""));
    $other = trim((string)($course["prerequisite_other"] ?? ""));

    if ($p1Code !== "" || $p1Name !== "") {
        $parts[] = trim($p1Code . " " . $p1Name);
    }

    if ($p2Code !== "" || $p2Name !== "") {
        $parts[] = trim($p2Code . " " . $p2Name);
    }

    if ($other !== "") {
        $parts[] = $other;
    }

    return implode("\n", $parts);
}

function normalizeRequirementType($value) {
    $value = trim((string)($value ?? ""));

    if ($value === "") {
        return null;
    }

    if (stripos($value, "institution") !== false || stripos($value, "university") !== false) {
        return "Institution";
    }

    if (stripos($value, "college") !== false) {
        return "College";
    }

    if (stripos($value, "program") !== false) {
        return "Program";
    }

    if (stripos($value, "internship") !== false) {
        return "Program";
    }

    if (stripos($value, "project") !== false) {
        return "Program";
    }

    return $value;
}

function requiredOrElective($value) {
    $value = trim((string)($value ?? ""));

    if (stripos($value, "elective") !== false) {
        return "Elective";
    }

    return "Required";
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "study_plans")) {
    die("study_plans table does not exist.");
}

if (!tableExists($conn, "study_plan_course_matrix")) {
    die("study_plan_course_matrix table does not exist.");
}

if (!tableExists($conn, "program_specification_courses")) {
    die("program_specification_courses table does not exist.");
}

$specStmt = $conn->prepare("
    SELECT ps.*, sp.plan_title, sp.plan_version, sp.academic_year
    FROM program_specifications ps
    LEFT JOIN study_plans sp ON ps.study_plan_id = sp.id
    WHERE ps.id = :id
    LIMIT 1
");
$specStmt->execute([":id" => $spec_id]);
$spec = $specStmt->fetch(PDO::FETCH_ASSOC);

if (!$spec) {
    die("Program specification not found.");
}

$study_plan_id = (int)($spec["study_plan_id"] ?? 0);

if ($study_plan_id <= 0) {
    die("This program specification is not linked to a study plan. Edit the specification and select a study plan first.");
}

$planStmt = $conn->prepare("
    SELECT *
    FROM study_plans
    WHERE id = :id
    LIMIT 1
");
$planStmt->execute([":id" => $study_plan_id]);
$plan = $planStmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    die("Linked study plan not found.");
}

$sourceCoursesStmt = $conn->prepare("
    SELECT *
    FROM study_plan_course_matrix
    WHERE study_plan_id = :study_plan_id
    ORDER BY semester_no ASC, course_sequence ASC, id ASC
");
$sourceCoursesStmt->execute([":study_plan_id" => $study_plan_id]);
$sourceCourses = $sourceCoursesStmt->fetchAll(PDO::FETCH_ASSOC);

$existingStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM program_specification_courses
    WHERE program_specification_id = :id
");
$existingStmt->execute([":id" => $spec_id]);
$existingCount = (int)$existingStmt->fetchColumn();

$sourceCount = count($sourceCourses);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $replaceExisting = isset($_POST["replace_existing"]) && $_POST["replace_existing"] === "1";

    if ($sourceCount === 0) {
        $error = "The linked study plan has no courses to import.";
    } elseif ($existingCount > 0 && !$replaceExisting) {
        $error = "Program specification already has imported courses. Tick replace existing courses if you want to re-import.";
    } else {
        try {
            $conn->beginTransaction();

            if ($replaceExisting) {
                $deleteStmt = $conn->prepare("
                    DELETE FROM program_specification_courses
                    WHERE program_specification_id = :id
                ");
                $deleteStmt->execute([":id" => $spec_id]);
            }

            $insertStmt = $conn->prepare("
                INSERT INTO program_specification_courses
                (
                    program_specification_id,
                    study_plan_course_id,
                    level_no,
                    course_code,
                    course_title,
                    required_or_elective,
                    prerequisite_courses,
                    credit_hours,
                    requirement_type,
                    notes
                )
                VALUES
                (
                    :program_specification_id,
                    :study_plan_course_id,
                    :level_no,
                    :course_code,
                    :course_title,
                    :required_or_elective,
                    :prerequisite_courses,
                    :credit_hours,
                    :requirement_type,
                    :notes
                )
            ");

            $inserted = 0;
            $skipped = 0;

            foreach ($sourceCourses as $course) {
                $courseCode = firstNonEmpty($course["course_code_en"] ?? "", $course["course_code_ar"] ?? "");
                $courseTitle = firstNonEmpty($course["course_name_en"] ?? "", $course["course_name_ar"] ?? "");

                if ($courseCode === null && $courseTitle === null) {
                    $skipped++;
                    continue;
                }

                $requirementTypeRaw = $course["course_requirement_type"] ?? "";
                $requirementType = normalizeRequirementType($requirementTypeRaw);
                $requiredElective = requiredOrElective($requirementTypeRaw);
                $prereqText = buildPrerequisiteText($course);

                $insertStmt->execute([
                    ":program_specification_id" => $spec_id,
                    ":study_plan_course_id" => (int)$course["id"],
                    ":level_no" => !empty($course["semester_no"]) ? (int)$course["semester_no"] : null,
                    ":course_code" => $courseCode,
                    ":course_title" => $courseTitle,
                    ":required_or_elective" => $requiredElective,
                    ":prerequisite_courses" => $prereqText !== "" ? $prereqText : null,
                    ":credit_hours" => $course["credit_hours"] ?? 0,
                    ":requirement_type" => $requirementType,
                    ":notes" => "Imported from study_plan_course_matrix ID: " . (int)$course["id"]
                ]);

                $inserted++;
            }

            $conn->commit();

            logActivitySafe(
                $conn,
                "Import",
                "Program Specification Courses",
                $spec_id,
                "Imported courses from study plan ID " . $study_plan_id . ". Inserted: " . $inserted . " | Skipped: " . $skipped
            );

            header("Location: program_specification_profile.php?id=" . $spec_id);
            exit;
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $error = "Import failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Import Courses from Study Plan</title>
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

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .alert-warning {
            background: #fff3cd;
            border: 1px solid #ffecb5;
            color: #664d03;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .small-text {
            font-size: 12px;
            color: #555;
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
        <h1>Import Courses from Study Plan</h1>
        <p>Copy the linked study plan course matrix into the program specification course table.</p>
    </div>

    <div class="actions">
        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
            Back to Program Specification
        </a>

        <a href="study_plan_profile.php?id=<?php echo safeText($study_plan_id); ?>" class="btn">
            Linked Study Plan
        </a>

        <a href="manage_study_plan_courses.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-purple">
            Manage Study Plan Courses
        </a>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($existingCount > 0): ?>
        <div class="alert-warning">
            This program specification already has <?php echo safeText($existingCount); ?> course records.
            Use replace only if you want to delete and re-import them from the linked study plan.
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Program Specification</h2>

        <table>
            <tr>
                <th>Specification</th>
                <td><?php echo safeText($spec["specification_title"] ?? ""); ?></td>
                <th>Program</th>
                <td><?php echo safeText($spec["program_name"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Program Code</th>
                <td><?php echo safeText($spec["program_code"] ?? ""); ?></td>
                <th>Total Credit Hours</th>
                <td><?php echo safeText($spec["total_credit_hours"] ?? ""); ?></td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Linked Study Plan</h2>

        <table>
            <tr>
                <th>Study Plan</th>
                <td><?php echo safeText($plan["plan_title"] ?? ""); ?></td>
                <th>Version</th>
                <td><?php echo safeText($plan["plan_version"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Academic Year</th>
                <td><?php echo safeText($plan["academic_year"] ?? ""); ?></td>
                <th>Declared Credits</th>
                <td><?php echo safeText($plan["total_credit_hours"] ?? ""); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($sourceCount); ?></div>
            <div class="summary-label">Courses in Study Plan</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($existingCount); ?></div>
            <div class="summary-label">Existing Spec Courses</div>
        </div>

        <div class="summary-card">
            <div class="summary-number">
                <?php
                $totalSourceCredits = 0;
                foreach ($sourceCourses as $course) {
                    $totalSourceCredits += (float)($course["credit_hours"] ?? 0);
                }
                echo safeText($totalSourceCredits);
                ?>
            </div>
            <div class="summary-label">Study Plan Credits</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($spec["total_credit_hours"] ?? ""); ?></div>
            <div class="summary-label">Specification Credits</div>
        </div>
    </div>

    <div class="panel">
        <h2>Import Options</h2>

        <form method="POST" action="import_program_specification_from_study_plan.php?id=<?php echo safeText($spec_id); ?>">

            <label>
                <input type="checkbox" name="replace_existing" value="1">
                Replace existing program specification courses before import
            </label>

            <br><br>

            <button type="submit" class="btn btn-purple">
                Import Courses from Study Plan
            </button>

            <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
                Cancel
            </a>

        </form>
    </div>

    <div class="panel">
        <h2>Preview of Study Plan Courses</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Semester</th>
                    <th>Sequence</th>
                    <th>Course Code</th>
                    <th>Course Title</th>
                    <th>Credits</th>
                    <th>Requirement Type</th>
                    <th>Prerequisites</th>
                </tr>

                <?php if (count($sourceCourses) > 0): ?>
                    <?php foreach ($sourceCourses as $course): ?>
                        <tr>
                            <td><?php echo safeText($course["semester_no"] ?? ""); ?></td>
                            <td><?php echo safeText($course["course_sequence"] ?? ""); ?></td>
                            <td>
                                <?php echo safeText(firstNonEmpty($course["course_code_en"] ?? "", $course["course_code_ar"] ?? "")); ?>
                            </td>
                            <td>
                                <?php echo safeText(firstNonEmpty($course["course_name_en"] ?? "", $course["course_name_ar"] ?? "")); ?>
                            </td>
                            <td><?php echo safeText($course["credit_hours"] ?? ""); ?></td>
                            <td><?php echo safeText(normalizeRequirementType($course["course_requirement_type"] ?? "")); ?></td>
                            <td><?php echo nl2br(safeText(buildPrerequisiteText($course))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7">No courses found in the linked study plan.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>