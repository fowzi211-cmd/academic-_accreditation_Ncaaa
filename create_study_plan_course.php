<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

if (!isset($_GET["study_plan_id"]) || !is_numeric($_GET["study_plan_id"])) {
    die("Invalid study plan ID.");
}

$study_plan_id = (int)$_GET["study_plan_id"];
$error = "";

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

function decimalValue($value) {
    $value = trim((string)$value);

    if ($value === "") {
        return 0;
    }

    return (float)$value;
}

function nullIfEmpty($value) {
    $value = trim((string)$value);

    if ($value === "") {
        return null;
    }

    return $value;
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

$nextSeqStmt = $conn->prepare("
    SELECT COALESCE(MAX(course_sequence), 0) + 1
    FROM study_plan_course_matrix
    WHERE study_plan_id = :study_plan_id
");
$nextSeqStmt->execute([":study_plan_id" => $study_plan_id]);
$nextSequence = (int)$nextSeqStmt->fetchColumn();

$form = [
    "course_sequence" => (string)$nextSequence,
    "semester_no" => "",
    "course_code_ar" => "",
    "course_name_ar" => "",
    "course_code_en" => "",
    "course_name_en" => "",
    "credit_hours" => "0",
    "course_requirement_type" => "",
    "teaching_mode" => "",
    "course_activity" => "",

    "credit_theory" => "0",
    "credit_practical" => "0",
    "credit_studio" => "0",
    "credit_training" => "0",
    "credit_graduation_project" => "0",
    "credit_seminar" => "0",
    "credit_clinical_training" => "0",
    "credit_educational_applications" => "0",
    "credit_clinical_simulation" => "0",
    "credit_other" => "0",

    "contact_theory" => "0",
    "contact_practical" => "0",
    "contact_studio" => "0",
    "contact_training" => "0",
    "contact_graduation_project" => "0",
    "contact_seminar" => "0",
    "contact_clinical_training" => "0",
    "contact_educational_applications" => "0",
    "contact_clinical_simulation" => "0",
    "contact_other" => "0",

    "prerequisite_1_name" => "",
    "prerequisite_2_name" => "",
    "prerequisite_1_code" => "",
    "prerequisite_2_code" => "",
    "prerequisite_other" => "",

    "responsible_department" => "",
    "course_description" => "",
    "track_name" => "",
    "exit_point" => "",
    "notes" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? "");
    }

    if ($form["semester_no"] === "") {
        $error = "Semester number is required.";
    } elseif ($form["course_code_en"] === "" && $form["course_code_ar"] === "") {
        $error = "At least one course code is required.";
    } elseif ($form["course_name_en"] === "" && $form["course_name_ar"] === "") {
        $error = "At least one course name is required.";
    } else {
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

        $insertStmt->execute([
            ":study_plan_id" => $study_plan_id,
            ":course_sequence" => $form["course_sequence"] !== "" ? (int)$form["course_sequence"] : null,
            ":semester_no" => $form["semester_no"] !== "" ? (int)$form["semester_no"] : null,
            ":course_code_ar" => nullIfEmpty($form["course_code_ar"]),
            ":course_name_ar" => nullIfEmpty($form["course_name_ar"]),
            ":course_code_en" => nullIfEmpty($form["course_code_en"]),
            ":course_name_en" => nullIfEmpty($form["course_name_en"]),
            ":credit_hours" => decimalValue($form["credit_hours"]),
            ":course_requirement_type" => nullIfEmpty($form["course_requirement_type"]),
            ":teaching_mode" => nullIfEmpty($form["teaching_mode"]),
            ":course_activity" => nullIfEmpty($form["course_activity"]),

            ":credit_theory" => decimalValue($form["credit_theory"]),
            ":credit_practical" => decimalValue($form["credit_practical"]),
            ":credit_studio" => decimalValue($form["credit_studio"]),
            ":credit_training" => decimalValue($form["credit_training"]),
            ":credit_graduation_project" => decimalValue($form["credit_graduation_project"]),
            ":credit_seminar" => decimalValue($form["credit_seminar"]),
            ":credit_clinical_training" => decimalValue($form["credit_clinical_training"]),
            ":credit_educational_applications" => decimalValue($form["credit_educational_applications"]),
            ":credit_clinical_simulation" => decimalValue($form["credit_clinical_simulation"]),
            ":credit_other" => decimalValue($form["credit_other"]),

            ":contact_theory" => decimalValue($form["contact_theory"]),
            ":contact_practical" => decimalValue($form["contact_practical"]),
            ":contact_studio" => decimalValue($form["contact_studio"]),
            ":contact_training" => decimalValue($form["contact_training"]),
            ":contact_graduation_project" => decimalValue($form["contact_graduation_project"]),
            ":contact_seminar" => decimalValue($form["contact_seminar"]),
            ":contact_clinical_training" => decimalValue($form["contact_clinical_training"]),
            ":contact_educational_applications" => decimalValue($form["contact_educational_applications"]),
            ":contact_clinical_simulation" => decimalValue($form["contact_clinical_simulation"]),
            ":contact_other" => decimalValue($form["contact_other"]),

            ":prerequisite_1_name" => nullIfEmpty($form["prerequisite_1_name"]),
            ":prerequisite_2_name" => nullIfEmpty($form["prerequisite_2_name"]),
            ":prerequisite_1_code" => nullIfEmpty($form["prerequisite_1_code"]),
            ":prerequisite_2_code" => nullIfEmpty($form["prerequisite_2_code"]),
            ":prerequisite_other" => nullIfEmpty($form["prerequisite_other"]),

            ":responsible_department" => nullIfEmpty($form["responsible_department"]),
            ":course_description" => nullIfEmpty($form["course_description"]),
            ":track_name" => nullIfEmpty($form["track_name"]),
            ":exit_point" => nullIfEmpty($form["exit_point"]),
            ":notes" => nullIfEmpty($form["notes"])
        ]);

        $newCourseId = (int)$conn->lastInsertId();

        logActivitySafe(
            $conn,
            "Create",
            "Study Plan Course Matrix",
            $newCourseId,
            "Course added to study plan: " . ($form["course_code_en"] ?: $form["course_code_ar"])
        );

        header("Location: manage_study_plan_courses.php?id=" . $study_plan_id);
        exit;
    }
}

$requirementTypeOptions = [
    "University Required Course",
    "University Elective Course",
    "College Required Course",
    "College Elective Course",
    "Program Required Course",
    "Program Elective Course",
    "Graduation Project",
    "Internship",
    "Other"
];

$teachingModeOptions = [
    "Face-to-face",
    "Online",
    "Blended",
    "Distance",
    "Other"
];

$activityOptions = [
    "Theory",
    "Practical",
    "Theory/Practical",
    "Training",
    "Graduation Project",
    "Seminar",
    "Clinical Training",
    "Educational Applications",
    "Clinical Simulation",
    "Other"
];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Add Study Plan Course</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 14px;
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(220px, 1fr));
            gap: 14px;
        }

        .form-section {
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid #ddd;
        }

        textarea {
            min-height: 130px;
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

        .small-text {
            font-size: 12px;
            color: #555;
        }

        @media (max-width: 900px) {
            .form-grid,
            .form-grid-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Add Study Plan Course</h1>
        <p>Add one course to the detailed bilingual study plan matrix.</p>
    </div>

    <div class="actions">
        <a href="manage_study_plan_courses.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-secondary">
            Back to Courses
        </a>

        <a href="study_plan_profile.php?id=<?php echo safeText($study_plan_id); ?>" class="btn">
            Study Plan Profile
        </a>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Study Plan</h2>

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
        <form method="POST" action="create_study_plan_course.php?study_plan_id=<?php echo safeText($study_plan_id); ?>">

            <h2>Basic Course Data</h2>

            <div class="form-grid">
                <div>
                    <label>Course Sequence</label>
                    <input type="number" name="course_sequence" value="<?php echo safeText($form["course_sequence"]); ?>">
                </div>

                <div>
                    <label>Semester <span style="color:red;">*</span></label>
                    <input type="number" name="semester_no" required value="<?php echo safeText($form["semester_no"]); ?>">
                </div>

                <div>
                    <label>Credit Hours</label>
                    <input type="number" step="0.01" name="credit_hours" value="<?php echo safeText($form["credit_hours"]); ?>">
                </div>

                <div>
                    <label>Course Code Arabic</label>
                    <input type="text" name="course_code_ar" value="<?php echo safeText($form["course_code_ar"]); ?>">
                </div>

                <div>
                    <label>Course Name Arabic</label>
                    <input type="text" name="course_name_ar" value="<?php echo safeText($form["course_name_ar"]); ?>">
                </div>

                <div>
                    <label>Course Code English</label>
                    <input type="text" name="course_code_en" value="<?php echo safeText($form["course_code_en"]); ?>">
                </div>

                <div>
                    <label>Course Name English</label>
                    <input type="text" name="course_name_en" value="<?php echo safeText($form["course_name_en"]); ?>">
                </div>

                <div>
                    <label>Course Requirement Type</label>
                    <select name="course_requirement_type">
                        <option value="">-- Select --</option>
                        <?php foreach ($requirementTypeOptions as $option): ?>
                            <option value="<?php echo safeText($option); ?>" <?php if ($form["course_requirement_type"] === $option) echo "selected"; ?>>
                                <?php echo safeText($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Teaching Mode</label>
                    <select name="teaching_mode">
                        <option value="">-- Select --</option>
                        <?php foreach ($teachingModeOptions as $option): ?>
                            <option value="<?php echo safeText($option); ?>" <?php if ($form["teaching_mode"] === $option) echo "selected"; ?>>
                                <?php echo safeText($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Course Activity</label>
                    <select name="course_activity">
                        <option value="">-- Select --</option>
                        <?php foreach ($activityOptions as $option): ?>
                            <option value="<?php echo safeText($option); ?>" <?php if ($form["course_activity"] === $option) echo "selected"; ?>>
                                <?php echo safeText($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-section">
                <h2>Credit Hours Distribution</h2>

                <div class="form-grid">
                    <div><label>Theory</label><input type="number" step="0.01" name="credit_theory" value="<?php echo safeText($form["credit_theory"]); ?>"></div>
                    <div><label>Practical</label><input type="number" step="0.01" name="credit_practical" value="<?php echo safeText($form["credit_practical"]); ?>"></div>
                    <div><label>Studio</label><input type="number" step="0.01" name="credit_studio" value="<?php echo safeText($form["credit_studio"]); ?>"></div>
                    <div><label>Training</label><input type="number" step="0.01" name="credit_training" value="<?php echo safeText($form["credit_training"]); ?>"></div>
                    <div><label>Graduation Project</label><input type="number" step="0.01" name="credit_graduation_project" value="<?php echo safeText($form["credit_graduation_project"]); ?>"></div>
                    <div><label>Seminar / Discussion</label><input type="number" step="0.01" name="credit_seminar" value="<?php echo safeText($form["credit_seminar"]); ?>"></div>
                    <div><label>Clinical Training</label><input type="number" step="0.01" name="credit_clinical_training" value="<?php echo safeText($form["credit_clinical_training"]); ?>"></div>
                    <div><label>Educational Applications</label><input type="number" step="0.01" name="credit_educational_applications" value="<?php echo safeText($form["credit_educational_applications"]); ?>"></div>
                    <div><label>Clinical Simulation</label><input type="number" step="0.01" name="credit_clinical_simulation" value="<?php echo safeText($form["credit_clinical_simulation"]); ?>"></div>
                    <div><label>Other</label><input type="number" step="0.01" name="credit_other" value="<?php echo safeText($form["credit_other"]); ?>"></div>
                </div>
            </div>

            <div class="form-section">
                <h2>Contact Hours Distribution</h2>

                <div class="form-grid">
                    <div><label>Theory</label><input type="number" step="0.01" name="contact_theory" value="<?php echo safeText($form["contact_theory"]); ?>"></div>
                    <div><label>Practical</label><input type="number" step="0.01" name="contact_practical" value="<?php echo safeText($form["contact_practical"]); ?>"></div>
                    <div><label>Studio</label><input type="number" step="0.01" name="contact_studio" value="<?php echo safeText($form["contact_studio"]); ?>"></div>
                    <div><label>Training</label><input type="number" step="0.01" name="contact_training" value="<?php echo safeText($form["contact_training"]); ?>"></div>
                    <div><label>Graduation Project</label><input type="number" step="0.01" name="contact_graduation_project" value="<?php echo safeText($form["contact_graduation_project"]); ?>"></div>
                    <div><label>Seminar / Discussion</label><input type="number" step="0.01" name="contact_seminar" value="<?php echo safeText($form["contact_seminar"]); ?>"></div>
                    <div><label>Clinical Training</label><input type="number" step="0.01" name="contact_clinical_training" value="<?php echo safeText($form["contact_clinical_training"]); ?>"></div>
                    <div><label>Educational Applications</label><input type="number" step="0.01" name="contact_educational_applications" value="<?php echo safeText($form["contact_educational_applications"]); ?>"></div>
                    <div><label>Clinical Simulation</label><input type="number" step="0.01" name="contact_clinical_simulation" value="<?php echo safeText($form["contact_clinical_simulation"]); ?>"></div>
                    <div><label>Other</label><input type="number" step="0.01" name="contact_other" value="<?php echo safeText($form["contact_other"]); ?>"></div>
                </div>
            </div>

            <div class="form-section">
                <h2>Prerequisites</h2>

                <div class="form-grid-2">
                    <div>
                        <label>Prerequisite 1 Name</label>
                        <input type="text" name="prerequisite_1_name" value="<?php echo safeText($form["prerequisite_1_name"]); ?>">
                    </div>

                    <div>
                        <label>Prerequisite 1 Code</label>
                        <input type="text" name="prerequisite_1_code" value="<?php echo safeText($form["prerequisite_1_code"]); ?>">
                    </div>

                    <div>
                        <label>Prerequisite 2 Name</label>
                        <input type="text" name="prerequisite_2_name" value="<?php echo safeText($form["prerequisite_2_name"]); ?>">
                    </div>

                    <div>
                        <label>Prerequisite 2 Code</label>
                        <input type="text" name="prerequisite_2_code" value="<?php echo safeText($form["prerequisite_2_code"]); ?>">
                    </div>
                </div>

                <label>Other Prerequisites</label>
                <textarea name="prerequisite_other"><?php echo safeText($form["prerequisite_other"]); ?></textarea>
            </div>

            <div class="form-section">
                <h2>Department and Description</h2>

                <div class="form-grid-2">
                    <div>
                        <label>Responsible Department</label>
                        <input type="text" name="responsible_department" value="<?php echo safeText($form["responsible_department"]); ?>">
                    </div>

                    <div>
                        <label>Track Name</label>
                        <input type="text" name="track_name" value="<?php echo safeText($form["track_name"]); ?>">
                    </div>

                    <div>
                        <label>Exit Point</label>
                        <input type="text" name="exit_point" value="<?php echo safeText($form["exit_point"]); ?>">
                    </div>
                </div>

                <label>Short Course Description</label>
                <textarea name="course_description"><?php echo safeText($form["course_description"]); ?></textarea>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($form["notes"]); ?></textarea>
            </div>

            <br>

            <button type="submit" class="btn">Save Course</button>

            <a href="manage_study_plan_courses.php?id=<?php echo safeText($study_plan_id); ?>" class="btn btn-secondary">
                Cancel
            </a>

        </form>
    </div>

</div>

</body>
</html>