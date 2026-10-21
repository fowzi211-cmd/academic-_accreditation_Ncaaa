<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

$error = "";

// ===============================
// Load system settings for defaults
// ===============================
$settingsStmt = $conn->query("
    SELECT *
    FROM system_settings
    ORDER BY id ASC
    LIMIT 1
");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

$defaultInstitution = $settings["institution_name"] ?? "";
$defaultCollege = $settings["college_name"] ?? "";
$defaultDepartment = $settings["department_name"] ?? "";
$defaultProgram = $settings["program_name"] ?? "";

// ===============================
// Handle form submission
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $course_code = trim($_POST["course_code"] ?? "");
    $course_title = trim($_POST["course_title"] ?? "");

    $program_name = trim($_POST["program_name"] ?? "");
    $department_name = trim($_POST["department_name"] ?? "");
    $college_name = trim($_POST["college_name"] ?? "");
    $institution_name = trim($_POST["institution_name"] ?? "");

    $specification_version = trim($_POST["specification_version"] ?? "");
    $last_revision_date = trim($_POST["last_revision_date"] ?? "");

    $credit_hours = trim($_POST["credit_hours"] ?? "");
    $theoretical_hours = trim($_POST["theoretical_hours"] ?? "");
    $practical_hours = trim($_POST["practical_hours"] ?? "");

    $course_type_a = trim($_POST["course_type_a"] ?? "");
    $course_type_b = trim($_POST["course_type_b"] ?? "");
    $level_year = trim($_POST["level_year"] ?? "");

    $course_description = trim($_POST["course_description"] ?? "");
    $pre_requisites = trim($_POST["pre_requisites"] ?? "");
    $co_requisites = trim($_POST["co_requisites"] ?? "");
    $course_objectives = trim($_POST["course_objectives"] ?? "");

    $approval_council = trim($_POST["approval_council"] ?? "");
    $approval_reference_no = trim($_POST["approval_reference_no"] ?? "");
    $approval_date = trim($_POST["approval_date"] ?? "");

    $status = trim($_POST["status"] ?? "Active");

    if ($course_code === "") {
        $error = "Course code is required.";
    } elseif ($course_title === "") {
        $error = "Course title is required.";
    } else {
        $sql = "
            INSERT INTO course_specifications
            (
                course_code,
                course_title,
                program_name,
                department_name,
                college_name,
                institution_name,
                specification_version,
                last_revision_date,
                credit_hours,
                theoretical_hours,
                practical_hours,
                course_type_a,
                course_type_b,
                level_year,
                course_description,
                pre_requisites,
                co_requisites,
                course_objectives,
                approval_council,
                approval_reference_no,
                approval_date,
                status
            )
            VALUES
            (
                :course_code,
                :course_title,
                :program_name,
                :department_name,
                :college_name,
                :institution_name,
                :specification_version,
                :last_revision_date,
                :credit_hours,
                :theoretical_hours,
                :practical_hours,
                :course_type_a,
                :course_type_b,
                :level_year,
                :course_description,
                :pre_requisites,
                :co_requisites,
                :course_objectives,
                :approval_council,
                :approval_reference_no,
                :approval_date,
                :status
            )
        ";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ":course_code" => $course_code,
            ":course_title" => $course_title,
            ":program_name" => $program_name,
            ":department_name" => $department_name,
            ":college_name" => $college_name,
            ":institution_name" => $institution_name,
            ":specification_version" => $specification_version,
            ":last_revision_date" => $last_revision_date !== "" ? $last_revision_date : null,
            ":credit_hours" => $credit_hours,
            ":theoretical_hours" => $theoretical_hours,
            ":practical_hours" => $practical_hours,
            ":course_type_a" => $course_type_a,
            ":course_type_b" => $course_type_b,
            ":level_year" => $level_year,
            ":course_description" => $course_description,
            ":pre_requisites" => $pre_requisites,
            ":co_requisites" => $co_requisites,
            ":course_objectives" => $course_objectives,
            ":approval_council" => $approval_council,
            ":approval_reference_no" => $approval_reference_no,
            ":approval_date" => $approval_date,
            ":status" => $status
        ]);

        $newSpecId = $conn->lastInsertId();

        logActivity(
            $conn,
            "Create",
            "Course Specifications",
            $newSpecId,
            "Course specification created: " . $course_code . " - " . $course_title
        );

        header("Location: manage_course_specification_details.php?id=" . $newSpecId);
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Add Course Specification</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .spec-form {
            max-width: 1100px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(220px, 1fr));
            gap: 14px;
        }

        .form-section {
            margin-top: 22px;
            padding-top: 14px;
            border-top: 1px solid #ddd;
        }

        .form-section:first-child {
            margin-top: 0;
            padding-top: 0;
            border-top: none;
        }

        textarea {
            min-height: 110px;
        }

        .large-textarea {
            min-height: 160px;
        }

        .hint {
            font-size: 12px;
            color: #555;
            margin-top: 5px;
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

        .button-row {
            margin-top: 18px;
        }

        @media (max-width: 800px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .spec-form {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Add Course Specification</h1>
        <p>Create the approved source specification for a course. Detailed CLOs, topics, assessments, resources, and facilities will be added after saving.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="create_course_specification.php" class="spec-form">

            <div class="form-section">
                <h2>Course Identity</h2>

                <div class="form-grid">
                    <div>
                        <label>Course Code</label>
                        <input type="text" name="course_code" required placeholder="Example: HP1001">
                    </div>

                    <div>
                        <label>Course Title</label>
                        <input type="text" name="course_title" required placeholder="Example: Medical Terminology in Public Health">
                    </div>

                    <div>
                        <label>Program</label>
                        <input type="text" name="program_name" value="<?php echo htmlspecialchars($defaultProgram); ?>">
                    </div>

                    <div>
                        <label>Department</label>
                        <input type="text" name="department_name" value="<?php echo htmlspecialchars($defaultDepartment); ?>">
                    </div>

                    <div>
                        <label>College</label>
                        <input type="text" name="college_name" value="<?php echo htmlspecialchars($defaultCollege); ?>">
                    </div>

                    <div>
                        <label>Institution</label>
                        <input type="text" name="institution_name" value="<?php echo htmlspecialchars($defaultInstitution); ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Specification Version</h2>

                <div class="form-grid">
                    <div>
                        <label>Version</label>
                        <input type="text" name="specification_version" placeholder="Example: 1">
                    </div>

                    <div>
                        <label>Last Revision Date</label>
                        <input type="date" name="last_revision_date">
                    </div>

                    <div>
                        <label>Status</label>
                        <select name="status">
                            <option value="Active">Active</option>
                            <option value="Draft">Draft</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Course Identification</h2>

                <div class="form-grid">
                    <div>
                        <label>Credit Hours</label>
                        <input type="text" name="credit_hours" placeholder="Example: 2">
                    </div>

                    <div>
                        <label>Theoretical Hours</label>
                        <input type="text" name="theoretical_hours" placeholder="Example: 2 hrs Theoretical">
                    </div>

                    <div>
                        <label>Practical Hours</label>
                        <input type="text" name="practical_hours" placeholder="Example: 0">
                    </div>

                    <div>
                        <label>Course Type A</label>
                        <select name="course_type_a">
                            <option value="">-- Select --</option>
                            <option value="University">University</option>
                            <option value="College">College</option>
                            <option value="Department">Department</option>
                            <option value="Track">Track</option>
                            <option value="Others">Others</option>
                        </select>
                    </div>

                    <div>
                        <label>Course Type B</label>
                        <select name="course_type_b">
                            <option value="">-- Select --</option>
                            <option value="Required">Required</option>
                            <option value="Elective">Elective</option>
                        </select>
                    </div>

                    <div>
                        <label>Level / Year</label>
                        <input type="text" name="level_year" placeholder="Example: Level 1 / 1st Year">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Description and Requirements</h2>

                <label>Course General Description</label>
                <textarea name="course_description" class="large-textarea" placeholder="Write the course general description."></textarea>

                <label>Pre-requisites</label>
                <textarea name="pre_requisites" placeholder="Example: N/A"></textarea>

                <label>Co-requisites</label>
                <textarea name="co_requisites" placeholder="Example: N/A"></textarea>

                <label>Course Main Objectives</label>
                <textarea name="course_objectives" class="large-textarea" placeholder="Write the main objectives of the course."></textarea>
            </div>

            <div class="form-section">
                <h2>Specification Approval</h2>

                <div class="form-grid">
                    <div>
                        <label>Council / Committee</label>
                        <input type="text" name="approval_council" placeholder="Example: Department Council">
                    </div>

                    <div>
                        <label>Reference No.</label>
                        <input type="text" name="approval_reference_no" placeholder="Example: 07/46/400161014467">
                    </div>

                    <div>
                        <label>Approval Date</label>
                        <input type="text" name="approval_date" placeholder="Example: 16/10/1446 H – 14/04/2025">
                    </div>
                </div>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Save Course Specification</button>
                <a href="course_specifications.php" class="btn btn-secondary">Back to Course Specifications</a>
            </div>

        </form>

    </div>

</div>

</body>
</html>