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
    die("Invalid study plan ID.");
}

$study_plan_id = (int)$_GET["id"];
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

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

function cleanValue($value) {
    $value = trim((string)$value);

    if ($value === "") {
        return null;
    }

    return $value;
}

function numberValue($value) {
    $value = trim((string)$value);

    if ($value === "") {
        return 0;
    }

    $value = str_replace(",", ".", $value);

    return is_numeric($value) ? (float)$value : 0;
}

function intValueOrNull($value) {
    $value = trim((string)$value);

    if ($value === "") {
        return null;
    }

    return is_numeric($value) ? (int)$value : null;
}

if (!tableExists($conn, "study_plans")) {
    die("study_plans table does not exist.");
}

if (!tableExists($conn, "study_plan_course_matrix")) {
    die("study_plan_course_matrix table does not exist.");
}

$stmt = $conn->prepare("
    SELECT *
    FROM study_plans
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $study_plan_id]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    die("Study plan not found.");
}

$expectedHeaders = [
    "course_sequence",
    "semester_no",
    "course_code_ar",
    "course_name_ar",
    "course_code_en",
    "course_name_en",
    "credit_hours",
    "course_requirement_type",
    "teaching_mode",
    "course_activity",

    "credit_theory",
    "credit_practical",
    "credit_studio",
    "credit_training",
    "credit_graduation_project",
    "credit_seminar",
    "credit_clinical_training",
    "credit_educational_applications",
    "credit_clinical_simulation",
    "credit_other",

    "contact_theory",
    "contact_practical",
    "contact_studio",
    "contact_training",
    "contact_graduation_project",
    "contact_seminar",
    "contact_clinical_training",
    "contact_educational_applications",
    "contact_clinical_simulation",
    "contact_other",

    "prerequisite_1_name",
    "prerequisite_2_name",
    "prerequisite_1_code",
    "prerequisite_2_code",
    "prerequisite_other",

    "responsible_department",
    "course_description",
    "track_name",
    "exit_point",
    "notes"
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_FILES["csv_file"]) || $_FILES["csv_file"]["error"] !== UPLOAD_ERR_OK) {
        $error = "Please upload a valid CSV file.";
    } else {
        $tmpName = $_FILES["csv_file"]["tmp_name"];
        $replaceExisting = isset($_POST["replace_existing"]) && $_POST["replace_existing"] === "1";

        $handle = fopen($tmpName, "r");

        if (!$handle) {
            $error = "Unable to read uploaded CSV file.";
        } else {
            $header = fgetcsv($handle, 0, ",", "\"", "\\");

            if (!$header || count($header) === 0) {
                $error = "CSV file is empty or header row is missing.";
            } else {
                if (isset($header[0])) {
                    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
                }

                $header = array_map(function ($h) {
                    return trim((string)$h);
                }, $header);

                $missingHeaders = [];

                foreach ($expectedHeaders as $requiredHeader) {
                    if (!in_array($requiredHeader, $header, true)) {
                        $missingHeaders[] = $requiredHeader;
                    }
                }

                if (count($missingHeaders) > 0) {
                    $error = "CSV header is missing required columns: " . implode(", ", $missingHeaders);
                } else {
                    $headerIndex = array_flip($header);

                    try {
                        $conn->beginTransaction();

                        if ($replaceExisting) {
                            $deleteStmt = $conn->prepare("
                                DELETE FROM study_plan_course_matrix
                                WHERE study_plan_id = :study_plan_id
                            ");
                            $deleteStmt->execute([":study_plan_id" => $study_plan_id]);
                        }

                        $insertStmt = $conn->prepare("
                            INSERT INTO study_plan_course_matrix
                            (
                                study_plan_id,
                                course_sequence,
                                semester_no,
                                course_code_ar,
                                course_name_ar,
                                course_code_en,
                                course_name_en,
                                credit_hours,
                                course_requirement_type,
                                teaching_mode,
                                course_activity,

                                credit_theory,
                                credit_practical,
                                credit_studio,
                                credit_training,
                                credit_graduation_project,
                                credit_seminar,
                                credit_clinical_training,
                                credit_educational_applications,
                                credit_clinical_simulation,
                                credit_other,

                                contact_theory,
                                contact_practical,
                                contact_studio,
                                contact_training,
                                contact_graduation_project,
                                contact_seminar,
                                contact_clinical_training,
                                contact_educational_applications,
                                contact_clinical_simulation,
                                contact_other,

                                prerequisite_1_name,
                                prerequisite_2_name,
                                prerequisite_1_code,
                                prerequisite_2_code,
                                prerequisite_other,

                                responsible_department,
                                course_description,
                                track_name,
                                exit_point,
                                notes
                            )
                            VALUES
                            (
                                :study_plan_id,
                                :course_sequence,
                                :semester_no,
                                :course_code_ar,
                                :course_name_ar,
                                :course_code_en,
                                :course_name_en,
                                :credit_hours,
                                :course_requirement_type,
                                :teaching_mode,
                                :course_activity,

                                :credit_theory,
                                :credit_practical,
                                :credit_studio,
                                :credit_training,
                                :credit_graduation_project,
                                :credit_seminar,
                                :credit_clinical_training,
                                :credit_educational_applications,
                                :credit_clinical_simulation,
                                :credit_other,

                                :contact_theory,
                                :contact_practical,
                                :contact_studio,
                                :contact_training,
                                :contact_graduation_project,
                                :contact_seminar,
                                :contact_clinical_training,
                                :contact_educational_applications,
                                :contact_clinical_simulation,
                                :contact_other,

                                :prerequisite_1_name,
                                :prerequisite_2_name,
                                :prerequisite_1_code,
                                :prerequisite_2_code,
                                :prerequisite_other,

                                :responsible_department,
                                :course_description,
                                :track_name,
                                :exit_point,
                                :notes
                            )
                        ");

                        $inserted = 0;
                        $skipped = 0;
                        $lineNumber = 1;

                        while (($row = fgetcsv($handle, 0, ",", "\"", "\\")) !== false) {
                            $lineNumber++;

                            $isEmptyRow = true;
                            foreach ($row as $cell) {
                                if (trim((string)$cell) !== "") {
                                    $isEmptyRow = false;
                                    break;
                                }
                            }

                            if ($isEmptyRow) {
                                $skipped++;
                                continue;
                            }

                            $get = function ($key) use ($row, $headerIndex) {
                                $index = $headerIndex[$key] ?? null;

                                if ($index === null) {
                                    return "";
                                }

                                return $row[$index] ?? "";
                            };

                            $courseCodeEn = trim((string)$get("course_code_en"));
                            $courseCodeAr = trim((string)$get("course_code_ar"));
                            $courseNameEn = trim((string)$get("course_name_en"));
                            $courseNameAr = trim((string)$get("course_name_ar"));

                            if ($courseCodeEn === "" && $courseCodeAr === "" && $courseNameEn === "" && $courseNameAr === "") {
                                $skipped++;
                                continue;
                            }

                            $insertStmt->execute([
                                ":study_plan_id" => $study_plan_id,
                                ":course_sequence" => intValueOrNull($get("course_sequence")),
                                ":semester_no" => intValueOrNull($get("semester_no")),

                                ":course_code_ar" => cleanValue($get("course_code_ar")),
                                ":course_name_ar" => cleanValue($get("course_name_ar")),
                                ":course_code_en" => cleanValue($get("course_code_en")),
                                ":course_name_en" => cleanValue($get("course_name_en")),

                                ":credit_hours" => numberValue($get("credit_hours")),
                                ":course_requirement_type" => cleanValue($get("course_requirement_type")),
                                ":teaching_mode" => cleanValue($get("teaching_mode")),
                                ":course_activity" => cleanValue($get("course_activity")),

                                ":credit_theory" => numberValue($get("credit_theory")),
                                ":credit_practical" => numberValue($get("credit_practical")),
                                ":credit_studio" => numberValue($get("credit_studio")),
                                ":credit_training" => numberValue($get("credit_training")),
                                ":credit_graduation_project" => numberValue($get("credit_graduation_project")),
                                ":credit_seminar" => numberValue($get("credit_seminar")),
                                ":credit_clinical_training" => numberValue($get("credit_clinical_training")),
                                ":credit_educational_applications" => numberValue($get("credit_educational_applications")),
                                ":credit_clinical_simulation" => numberValue($get("credit_clinical_simulation")),
                                ":credit_other" => numberValue($get("credit_other")),

                                ":contact_theory" => numberValue($get("contact_theory")),
                                ":contact_practical" => numberValue($get("contact_practical")),
                                ":contact_studio" => numberValue($get("contact_studio")),
                                ":contact_training" => numberValue($get("contact_training")),
                                ":contact_graduation_project" => numberValue($get("contact_graduation_project")),
                                ":contact_seminar" => numberValue($get("contact_seminar")),
                                ":contact_clinical_training" => numberValue($get("contact_clinical_training")),
                                ":contact_educational_applications" => numberValue($get("contact_educational_applications")),
                                ":contact_clinical_simulation" => numberValue($get("contact_clinical_simulation")),
                                ":contact_other" => numberValue($get("contact_other")),

                                ":prerequisite_1_name" => cleanValue($get("prerequisite_1_name")),
                                ":prerequisite_2_name" => cleanValue($get("prerequisite_2_name")),
                                ":prerequisite_1_code" => cleanValue($get("prerequisite_1_code")),
                                ":prerequisite_2_code" => cleanValue($get("prerequisite_2_code")),
                                ":prerequisite_other" => cleanValue($get("prerequisite_other")),

                                ":responsible_department" => cleanValue($get("responsible_department")),
                                ":course_description" => cleanValue($get("course_description")),
                                ":track_name" => cleanValue($get("track_name")),
                                ":exit_point" => cleanValue($get("exit_point")),
                                ":notes" => cleanValue($get("notes"))
                            ]);

                            $inserted++;
                        }

                        $conn->commit();

                        logActivitySafe(
                            $conn,
                            "Upload",
                            "Study Plan Course Matrix",
                            $study_plan_id,
                            "Study plan courses uploaded. Inserted: " . $inserted . " | Skipped: " . $skipped
                        );

                        fclose($handle);

                        header("Location: manage_study_plan_courses.php?id=" . $study_plan_id);
                        exit;
                    } catch (Exception $e) {
                        if ($conn->inTransaction()) {
                            $conn->rollBack();
                        }

                        $error = "Upload failed: " . $e->getMessage();
                    }
                }
            }

            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Upload Study Plan Courses CSV</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
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

        .header-list {
            columns: 2;
            font-size: 13px;
            line-height: 1.7;
        }

        code {
            background: #f1f1f1;
            padding: 2px 5px;
            border-radius: 4px;
        }

        @media (max-width: 800px) {
            .header-list {
                columns: 1;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Upload Study Plan Courses CSV</h1>
        <p>Import course rows into the detailed study plan matrix.</p>
    </div>

    <div class="actions">
    <a href="manage_study_plan_courses.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-secondary">
        Back to Courses
    </a>

    <a href="study_plan_profile.php?id=<?php echo safeText($study_plan_id); ?>" class="btn">
        Study Plan Profile
    </a>

    <a href="study_plan_course_template_csv.php" class="btn btn-purple">
        Download Template CSV
    </a>
</div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Selected Study Plan</h2>

        <table>
            <tr>
                <th>Plan Title</th>
                <td><?php echo safeText($plan["plan_title"]); ?></td>
                <th>Program</th>
                <td><?php echo safeText($plan["program_name"]); ?></td>
            </tr>

            <tr>
                <th>Version</th>
                <td><?php echo safeText($plan["plan_version"]); ?></td>
                <th>Academic Year</th>
                <td><?php echo safeText($plan["academic_year"]); ?></td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Upload CSV</h2>

        <form method="POST"
              action="upload_study_plan_courses_csv.php?id=<?php echo safeText($study_plan_id); ?>"
              enctype="multipart/form-data">

            <label>CSV File</label>
            <input type="file" name="csv_file" accept=".csv" required>

            <br><br>

            <label>
                <input type="checkbox" name="replace_existing" value="1">
                Replace existing courses in this study plan before upload
            </label>

            <br><br>

            <button type="submit" class="btn btn-purple">
                Upload Courses CSV
            </button>
        </form>
    </div>

    <div class="panel">
        <h2>Required CSV Headers</h2>

        <p>Your CSV first row must use these exact column names:</p>

        <div class="header-list">
            <?php foreach ($expectedHeaders as $header): ?>
                <div><code><?php echo safeText($header); ?></code></div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

</body>
</html>