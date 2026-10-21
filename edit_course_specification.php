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
// Validate ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid course specification ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Load specification
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM course_specifications
    WHERE id = :id
");
$stmt->execute([":id" => $id]);
$spec = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$spec) {
    die("Course specification not found.");
}

// ===============================
// Handle update
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
            UPDATE course_specifications
            SET
                course_code = :course_code,
                course_title = :course_title,
                program_name = :program_name,
                department_name = :department_name,
                college_name = :college_name,
                institution_name = :institution_name,
                specification_version = :specification_version,
                last_revision_date = :last_revision_date,
                credit_hours = :credit_hours,
                theoretical_hours = :theoretical_hours,
                practical_hours = :practical_hours,
                course_type_a = :course_type_a,
                course_type_b = :course_type_b,
                level_year = :level_year,
                course_description = :course_description,
                pre_requisites = :pre_requisites,
                co_requisites = :co_requisites,
                course_objectives = :course_objectives,
                approval_council = :approval_council,
                approval_reference_no = :approval_reference_no,
                approval_date = :approval_date,
                status = :status
            WHERE id = :id
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
            ":status" => $status,
            ":id" => $id
        ]);

        logActivity(
            $conn,
            "Update",
            "Course Specifications",
            $id,
            "Course specification updated: " . $course_code . " - " . $course_title
        );

        header("Location: view_course_specification.php?id=" . $id);
        exit;
    }
}

// ===============================
// Helper functions
// ===============================
function safeText($text) {
    return htmlspecialchars((string)($text ?? ""));
}

function selectedValue($currentValue, $optionValue) {
    return (string)$currentValue === (string)$optionValue ? "selected" : "";
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Course Specification</title>
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
        <h1>Edit Course Specification</h1>
        <p>Update the main approved course specification record. Use Manage Details for CLOs, topics, assessments, resources, facilities, and contact hours.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="edit_course_specification.php?id=<?php echo safeText($id); ?>" class="spec-form">

            <div class="form-section">
                <h2>Course Identity</h2>

                <div class="form-grid">
                    <div>
                        <label>Course Code</label>
                        <input type="text" name="course_code" required
                               value="<?php echo safeText($spec["course_code"]); ?>">
                    </div>

                    <div>
                        <label>Course Title</label>
                        <input type="text" name="course_title" required
                               value="<?php echo safeText($spec["course_title"]); ?>">
                    </div>

                    <div>
                        <label>Program</label>
                        <input type="text" name="program_name"
                               value="<?php echo safeText($spec["program_name"]); ?>">
                    </div>

                    <div>
                        <label>Department</label>
                        <input type="text" name="department_name"
                               value="<?php echo safeText($spec["department_name"]); ?>">
                    </div>

                    <div>
                        <label>College</label>
                        <input type="text" name="college_name"
                               value="<?php echo safeText($spec["college_name"]); ?>">
                    </div>

                    <div>
                        <label>Institution</label>
                        <input type="text" name="institution_name"
                               value="<?php echo safeText($spec["institution_name"]); ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Specification Version</h2>

                <div class="form-grid">
                    <div>
                        <label>Version</label>
                        <input type="text" name="specification_version"
                               value="<?php echo safeText($spec["specification_version"]); ?>">
                    </div>

                    <div>
                        <label>Last Revision Date</label>
                        <input type="date" name="last_revision_date"
                               value="<?php echo safeText($spec["last_revision_date"]); ?>">
                    </div>

                    <div>
                        <label>Status</label>
                        <select name="status">
                            <option value="Active" <?php echo selectedValue($spec["status"], "Active"); ?>>Active</option>
                            <option value="Draft" <?php echo selectedValue($spec["status"], "Draft"); ?>>Draft</option>
                            <option value="Inactive" <?php echo selectedValue($spec["status"], "Inactive"); ?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Course Identification</h2>

                <div class="form-grid">
                    <div>
                        <label>Credit Hours</label>
                        <input type="text" name="credit_hours"
                               value="<?php echo safeText($spec["credit_hours"]); ?>">
                    </div>

                    <div>
                        <label>Theoretical Hours</label>
                        <input type="text" name="theoretical_hours"
                               value="<?php echo safeText($spec["theoretical_hours"]); ?>">
                    </div>

                    <div>
                        <label>Practical Hours</label>
                        <input type="text" name="practical_hours"
                               value="<?php echo safeText($spec["practical_hours"]); ?>">
                    </div>

                    <div>
                        <label>Course Type A</label>
                        <select name="course_type_a">
                            <option value="">-- Select --</option>
                            <option value="University" <?php echo selectedValue($spec["course_type_a"], "University"); ?>>University</option>
                            <option value="College" <?php echo selectedValue($spec["course_type_a"], "College"); ?>>College</option>
                            <option value="Department" <?php echo selectedValue($spec["course_type_a"], "Department"); ?>>Department</option>
                            <option value="Track" <?php echo selectedValue($spec["course_type_a"], "Track"); ?>>Track</option>
                            <option value="Others" <?php echo selectedValue($spec["course_type_a"], "Others"); ?>>Others</option>
                        </select>
                    </div>

                    <div>
                        <label>Course Type B</label>
                        <select name="course_type_b">
                            <option value="">-- Select --</option>
                            <option value="Required" <?php echo selectedValue($spec["course_type_b"], "Required"); ?>>Required</option>
                            <option value="Elective" <?php echo selectedValue($spec["course_type_b"], "Elective"); ?>>Elective</option>
                        </select>
                    </div>

                    <div>
                        <label>Level / Year</label>
                        <input type="text" name="level_year"
                               value="<?php echo safeText($spec["level_year"]); ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Description and Requirements</h2>

                <label>Course General Description</label>
                <textarea name="course_description" class="large-textarea"><?php echo safeText($spec["course_description"]); ?></textarea>

                <label>Pre-requisites</label>
                <textarea name="pre_requisites"><?php echo safeText($spec["pre_requisites"]); ?></textarea>

                <label>Co-requisites</label>
                <textarea name="co_requisites"><?php echo safeText($spec["co_requisites"]); ?></textarea>

                <label>Course Main Objectives</label>
                <textarea name="course_objectives" class="large-textarea"><?php echo safeText($spec["course_objectives"]); ?></textarea>
            </div>

            <div class="form-section">
                <h2>Specification Approval</h2>

                <div class="form-grid">
                    <div>
                        <label>Council / Committee</label>
                        <input type="text" name="approval_council"
                               value="<?php echo safeText($spec["approval_council"]); ?>">
                    </div>

                    <div>
                        <label>Reference No.</label>
                        <input type="text" name="approval_reference_no"
                               value="<?php echo safeText($spec["approval_reference_no"]); ?>">
                    </div>

                    <div>
                        <label>Approval Date</label>
                        <input type="text" name="approval_date"
                               value="<?php echo safeText($spec["approval_date"]); ?>">
                    </div>
                </div>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Update Course Specification</button>

                <a href="view_course_specification.php?id=<?php echo safeText($id); ?>" class="btn btn-purple">
                    View Specification
                </a>

                <a href="manage_course_specification_details.php?id=<?php echo safeText($id); ?>" class="btn">
                    Manage Details
                </a>

                <a href="course_specifications.php" class="btn btn-secondary">
                    Back to Course Specifications
                </a>
            </div>

        </form>

    </div>

</div>

</body>
</html>