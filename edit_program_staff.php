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
    die("Invalid staff ID.");
}

$id = (int)$_GET["id"];
$error = "";

// ===============================
// Helper functions
// ===============================
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

if (!tableExists($conn, "program_staff")) {
    die("program_staff table does not exist.");
}

// ===============================
// Load staff record
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM program_staff
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    die("Program staff record not found.");
}

// ===============================
// Load users for optional link
// ===============================
$users = [];

if (tableExists($conn, "users")) {
    $userStmt = $conn->query("
        SELECT id, full_name, email, role, status
        FROM users
        ORDER BY full_name ASC
    ");
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Form values
// ===============================
$form = $staff;

// ===============================
// Handle submit
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $form["user_id"] = trim($_POST["user_id"] ?? "");
    $form["staff_code"] = trim($_POST["staff_code"] ?? "");
    $form["full_name"] = trim($_POST["full_name"] ?? "");
    $form["academic_rank"] = trim($_POST["academic_rank"] ?? "");
    $form["qualification"] = trim($_POST["qualification"] ?? "");
    $form["specialty"] = trim($_POST["specialty"] ?? "");
    $form["department"] = trim($_POST["department"] ?? "");
    $form["college"] = trim($_POST["college"] ?? "");
    $form["institution"] = trim($_POST["institution"] ?? "");
    $form["email"] = trim($_POST["email"] ?? "");
    $form["phone"] = trim($_POST["phone"] ?? "");
    $form["employment_status"] = trim($_POST["employment_status"] ?? "Active");
    $form["role_in_program"] = trim($_POST["role_in_program"] ?? "");
    $form["notes"] = trim($_POST["notes"] ?? "");

    $user_id = $form["user_id"] !== "" ? (int)$form["user_id"] : null;

    if ($form["full_name"] === "") {
        $error = "Full name is required.";
    } else {
        $updateStmt = $conn->prepare("
            UPDATE program_staff
            SET
                user_id = :user_id,
                staff_code = :staff_code,
                full_name = :full_name,
                academic_rank = :academic_rank,
                qualification = :qualification,
                specialty = :specialty,
                department = :department,
                college = :college,
                institution = :institution,
                email = :email,
                phone = :phone,
                employment_status = :employment_status,
                role_in_program = :role_in_program,
                notes = :notes
            WHERE id = :id
        ");

        $updateStmt->execute([
            ":user_id" => $user_id,
            ":staff_code" => $form["staff_code"] !== "" ? $form["staff_code"] : null,
            ":full_name" => $form["full_name"],
            ":academic_rank" => $form["academic_rank"] !== "" ? $form["academic_rank"] : null,
            ":qualification" => $form["qualification"] !== "" ? $form["qualification"] : null,
            ":specialty" => $form["specialty"] !== "" ? $form["specialty"] : null,
            ":department" => $form["department"] !== "" ? $form["department"] : null,
            ":college" => $form["college"] !== "" ? $form["college"] : null,
            ":institution" => $form["institution"] !== "" ? $form["institution"] : null,
            ":email" => $form["email"] !== "" ? $form["email"] : null,
            ":phone" => $form["phone"] !== "" ? $form["phone"] : null,
            ":employment_status" => $form["employment_status"] !== "" ? $form["employment_status"] : "Active",
            ":role_in_program" => $form["role_in_program"] !== "" ? $form["role_in_program"] : null,
            ":notes" => $form["notes"] !== "" ? $form["notes"] : null,
            ":id" => $id
        ]);

        logActivitySafe(
            $conn,
            "Update",
            "Program Staff",
            $id,
            "Program staff updated: " . $form["full_name"]
        );

        header("Location: program_staff.php");
        exit;
    }
}

$statusOptions = ["Active", "Inactive", "On Leave", "External", "Former"];
$rankOptions = ["Professor", "Associate Professor", "Assistant Professor", "Lecturer", "Teaching Assistant", "Specialist", "Consultant", "Administrative Staff"];
$roleOptions = ["Program Leader", "Quality Coordinator", "Course Coordinator", "Committee Chair", "Committee Secretary", "Committee Member", "Faculty Member", "Reviewer", "Administrative Support"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Program Staff</title>
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

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .hint {
            font-size: 12px;
            color: #555;
            margin-top: 5px;
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
        <h1>Edit Program Staff</h1>
        <p>Update Health Promotion program staff profile and optional linked system user account.</p>
    </div>

    <div class="actions">
        <a href="program_staff.php" class="btn btn-secondary">Back to Program Staff</a>
        <a href="upload_program_staff_excel.php" class="btn btn-purple">Upload Staff CSV</a>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <form method="POST" action="edit_program_staff.php?id=<?php echo safeText($id); ?>">

            <div class="form-section">
                <h2>Basic Staff Information</h2>

                <div class="form-grid">
                    <div>
                        <label>Full Name <span style="color:red;">*</span></label>
                        <input type="text" name="full_name" required value="<?php echo safeText($form["full_name"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Staff Code</label>
                        <input type="text" name="staff_code" value="<?php echo safeText($form["staff_code"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Academic Rank</label>
                        <select name="academic_rank">
                            <option value="">-- Select Rank --</option>
                            <?php foreach ($rankOptions as $rank): ?>
                                <option value="<?php echo safeText($rank); ?>" <?php if (($form["academic_rank"] ?? "") === $rank) echo "selected"; ?>>
                                    <?php echo safeText($rank); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Employment Status</label>
                        <select name="employment_status">
                            <?php foreach ($statusOptions as $status): ?>
                                <option value="<?php echo safeText($status); ?>" <?php if (($form["employment_status"] ?? "") === $status) echo "selected"; ?>>
                                    <?php echo safeText($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Qualification</label>
                        <input type="text" name="qualification" value="<?php echo safeText($form["qualification"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Specialty</label>
                        <input type="text" name="specialty" value="<?php echo safeText($form["specialty"] ?? ""); ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Institutional Details</h2>

                <div class="form-grid">
                    <div>
                        <label>Department</label>
                        <input type="text" name="department" value="<?php echo safeText($form["department"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>College</label>
                        <input type="text" name="college" value="<?php echo safeText($form["college"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Institution</label>
                        <input type="text" name="institution" value="<?php echo safeText($form["institution"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Role in Program</label>
                        <select name="role_in_program">
                            <option value="">-- Select Role --</option>
                            <?php foreach ($roleOptions as $role): ?>
                                <option value="<?php echo safeText($role); ?>" <?php if (($form["role_in_program"] ?? "") === $role) echo "selected"; ?>>
                                    <?php echo safeText($role); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Contact and System Link</h2>

                <div class="form-grid">
                    <div>
                        <label>Email</label>
                        <input type="email" name="email" value="<?php echo safeText($form["email"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Phone</label>
                        <input type="text" name="phone" value="<?php echo safeText($form["phone"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Linked System User</label>
                        <select name="user_id">
                            <option value="">-- Not linked --</option>

                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo safeText($user["id"]); ?>" <?php if ((string)($form["user_id"] ?? "") === (string)$user["id"]) echo "selected"; ?>>
                                    <?php
                                        echo safeText(
                                            $user["full_name"] . " | " .
                                            $user["email"] . " | " .
                                            $user["role"] . " | " .
                                            $user["status"]
                                        );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="hint">
                            Optional. Use this only if this staff member has a login account in the system.
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Notes</h2>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($form["notes"] ?? ""); ?></textarea>
            </div>

            <br>

            <button type="submit" class="btn">Update Staff</button>
            <a href="program_staff.php" class="btn btn-secondary">Cancel</a>

        </form>
    </div>

</div>

</body>
</html>