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

if (!tableExists($conn, "study_plans")) {
    die("study_plans table does not exist. Run the study plan SQL first.");
}

$form = [
    "plan_title" => "",
    "program_name" => "Health Promotion and Education Program",
    "department_name" => "Department of Health Promotion and Education",
    "college_name" => "College of Public Health and Health Informatics",
    "institution_name" => "Umm Al-Qura University",
    "plan_version" => "",
    "academic_year" => "",
    "total_credit_hours" => "134",
    "approval_status" => "Draft",
    "approval_date" => "",
    "notes" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? "");
    }

    if ($form["plan_title"] === "") {
        $error = "Study plan title is required.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO study_plans
            (
                plan_title,
                program_name,
                department_name,
                college_name,
                institution_name,
                plan_version,
                academic_year,
                total_credit_hours,
                approval_status,
                approval_date,
                notes
            )
            VALUES
            (
                :plan_title,
                :program_name,
                :department_name,
                :college_name,
                :institution_name,
                :plan_version,
                :academic_year,
                :total_credit_hours,
                :approval_status,
                :approval_date,
                :notes
            )
        ");

        $stmt->execute([
            ":plan_title" => $form["plan_title"],
            ":program_name" => $form["program_name"] !== "" ? $form["program_name"] : null,
            ":department_name" => $form["department_name"] !== "" ? $form["department_name"] : null,
            ":college_name" => $form["college_name"] !== "" ? $form["college_name"] : null,
            ":institution_name" => $form["institution_name"] !== "" ? $form["institution_name"] : null,
            ":plan_version" => $form["plan_version"] !== "" ? $form["plan_version"] : null,
            ":academic_year" => $form["academic_year"] !== "" ? $form["academic_year"] : null,
            ":total_credit_hours" => $form["total_credit_hours"] !== "" ? (int)$form["total_credit_hours"] : null,
            ":approval_status" => $form["approval_status"] !== "" ? $form["approval_status"] : "Draft",
            ":approval_date" => $form["approval_date"] !== "" ? $form["approval_date"] : null,
            ":notes" => $form["notes"] !== "" ? $form["notes"] : null
        ]);

        $newId = (int)$conn->lastInsertId();

        logActivitySafe(
            $conn,
            "Create",
            "Study Plans",
            $newId,
            "Study plan created: " . $form["plan_title"]
        );

        header("Location: study_plan_profile.php?id=" . $newId);
        exit;
    }
}

$statusOptions = ["Draft", "Under Review", "Approved", "Archived"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Add Study Plan</title>
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
            grid-template-columns: repeat(2, minmax(220px, 1fr));
            gap: 14px;
        }

        textarea {
            min-height: 120px;
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

        @media (max-width: 800px) {
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
        <h1>Add Study Plan</h1>
        <p>Create a study plan record before adding or uploading courses.</p>
    </div>

    <div class="actions">
        <a href="study_plans.php" class="btn btn-secondary">Back to Study Plans</a>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <form method="POST" action="create_study_plan.php">

            <h2>Study Plan Identity</h2>

            <div class="form-grid">
                <div>
                    <label>Plan Title <span style="color:red;">*</span></label>
                    <input type="text"
                           name="plan_title"
                           required
                           value="<?php echo safeText($form["plan_title"]); ?>"
                           placeholder="Example: Health Promotion Study Plan 2026">
                </div>

                <div>
                    <label>Program Name</label>
                    <input type="text" name="program_name" value="<?php echo safeText($form["program_name"]); ?>">
                </div>

                <div>
                    <label>Department Name</label>
                    <input type="text" name="department_name" value="<?php echo safeText($form["department_name"]); ?>">
                </div>

                <div>
                    <label>College Name</label>
                    <input type="text" name="college_name" value="<?php echo safeText($form["college_name"]); ?>">
                </div>

                <div>
                    <label>Institution Name</label>
                    <input type="text" name="institution_name" value="<?php echo safeText($form["institution_name"]); ?>">
                </div>

                <div>
                    <label>Plan Version</label>
                    <input type="text"
                           name="plan_version"
                           value="<?php echo safeText($form["plan_version"]); ?>"
                           placeholder="Example: V1, 2026 Plan">
                </div>

                <div>
                    <label>Academic Year</label>
                    <input type="text"
                           name="academic_year"
                           value="<?php echo safeText($form["academic_year"]); ?>"
                           placeholder="Example: 2026">
                </div>

                <div>
                    <label>Total Credit Hours</label>
                    <input type="number"
                           name="total_credit_hours"
                           value="<?php echo safeText($form["total_credit_hours"]); ?>">
                </div>

                <div>
                    <label>Approval Status</label>
                    <select name="approval_status">
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?php echo safeText($status); ?>"
                                <?php if ($form["approval_status"] === $status) echo "selected"; ?>>
                                <?php echo safeText($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Approval Date</label>
                    <input type="date" name="approval_date" value="<?php echo safeText($form["approval_date"]); ?>">
                </div>
            </div>

            <br>

            <label>Notes</label>
            <textarea name="notes"><?php echo safeText($form["notes"]); ?></textarea>

            <br>

            <button type="submit" class="btn">Save Study Plan</button>
            <a href="study_plans.php" class="btn btn-secondary">Cancel</a>

        </form>
    </div>

</div>

</body>
</html>