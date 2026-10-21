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
// Load users for optional link
// ===============================
$users = [];

if (tableExists($conn, "users")) {
    $stmt = $conn->query("
        SELECT id, full_name, email, role, status
        FROM users
        ORDER BY full_name ASC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Default values
// ===============================
$old = [
    "user_id" => "",
    "staff_code" => "",
    "full_name" => "",
    "academic_rank" => "",
    "qualification" => "",
    "specialty" => "",
    "department" => "Health Promotion and Education",
    "college" => "College of Public Health and Health Informatics",
    "institution" => "Umm Al-Qura University",
    "email" => "",
    "phone" => "",
    "employment_status" => "Active",
    "role_in_program" => "",
    "notes" => ""
];

// ===============================
// Handle submit
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($old as $key => $value) {
        $old[$key] = trim($_POST[$key] ?? "");
    }

    $user_id = !empty($old["user_id"]) ? (int)$old["user_id"] : null;

    if ($old["full_name"] === "") {
        $error = "Full name is required.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO program_staff
            (
                user_id,
                staff_code,
                full_name,
                academic_rank,
                qualification,
                specialty,
                department,
                college,
                institution,
                email,
                phone,
                employment_status,
                role_in_program,
                notes
            )
            VALUES
            (
                :user_id,
                :staff_code,
                :full_name,
                :academic_rank,
                :qualification,
                :specialty,
                :department,
                :college,
                :institution,
                :email,
                :phone,
                :employment_status,
                :role_in_program,
                :notes
            )
        ");

        $stmt->execute([
            ":user_id" => $user_id,
            ":staff_code" => $old["staff_code"] !== "" ? $old["staff_code"] : null,
            ":full_name" => $old["full_name"],
            ":academic_rank" => $old["academic_rank"] !== "" ? $old["academic_rank"] : null,
            ":qualification" => $old["qualification"] !== "" ? $old["qualification"] : null,
            ":specialty" => $old["specialty"] !== "" ? $old["specialty"] : null,
            ":department" => $old["department"] !== "" ? $old["department"] : null,
            ":college" => $old["college"] !== "" ? $old["college"] : null,
            ":institution" => $old["institution"] !== "" ? $old["institution"] : null,
            ":email" => $old["email"] !== "" ? $old["email"] : null,
            ":phone" => $old["phone"] !== "" ? $old["phone"] : null,
            ":employment_status" => $old["employment_status"] !== "" ? $old["employment_status"] : "Active",
            ":role_in_program" => $old["role_in_program"] !== "" ? $old["role_in_program"] : null,
            ":notes" => $old["notes"] !== "" ? $old["notes"] : null
        ]);

        $newId = $conn->lastInsertId();

        logActivitySafe(
            $conn,
            "Create",
            "Program Staff",
            $newId,
            "Program staff created: " . $old["full_name"]
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
    <title>Add Program Staff</title>
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
        <h1>Add Program Staff</h1>
        <p>Create a Health Promotion program staff profile and optionally link it to a quality system user account.</p>
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
        <form method="POST" action="create_program_staff.php">

            <div class="form-section">
                <h2>Basic Staff Information</h2>

                <div class="form-grid">
                    <div>
                        <label>Full Name <span style="color:red;">*</span></label>
                        <input type="text" name="full_name" required value="<?php echo safeText($old["full_name"]); ?>">
                    </div>

                    <div>
                        <label>Staff Code</label>
                        <input type="text" name="staff_code" value="<?php echo safeText($old["staff_code"]); ?>">
                    </div>

                    <div>
                        <label>Academic Rank</label>
                        <select name="academic_rank">
                            <option value="">-- Select Rank --</option>
                            <?php foreach ($rankOptions as $rank): ?>
                                <option value="<?php echo safeText($rank); ?>" <?php if ($old["academic_rank"] === $rank) echo "selected"; ?>>
                                    <?php echo safeText($rank); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Employment Status</label>
                        <select name="employment_status">
                            <?php foreach ($statusOptions as $status): ?>
                                <option value="<?php echo safeText($status); ?>" <?php if ($old["employment_status"] === $status) echo "selected"; ?>>
                                    <?php echo safeText($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Qualification</label>
                        <input type="text" name="qualification" value="<?php echo safeText($old["qualification"]); ?>" placeholder="Example: PhD in Community Health">
                    </div>

                    <div>
                        <label>Specialty</label>
                        <input type="text" name="specialty" value="<?php echo safeText($old["specialty"]); ?>" placeholder="Example: Health Promotion">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Institutional Details</h2>

                <div class="form-grid">
                    <div>
                        <label>Department</label>
                        <input type="text" name="department" value="<?php echo safeText($old["department"]); ?>">
                    </div>

                    <div>
                        <label>College</label>
                        <input type="text" name="college" value="<?php echo safeText($old["college"]); ?>">
                    </div>

                    <div>
                        <label>Institution</label>
                        <input type="text" name="institution" value="<?php echo safeText($old["institution"]); ?>">
                    </div>

                    <div>
                        <label>Role in Program</label>
                        <select name="role_in_program">
                            <option value="">-- Select Role --</option>
                            <?php foreach ($roleOptions as $role): ?>
                                <option value="<?php echo safeText($role); ?>" <?php if ($old["role_in_program"] === $role) echo "selected"; ?>>
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
                        <input type="email" name="email" value="<?php echo safeText($old["email"]); ?>">
                    </div>

                    <div>
                        <label>Phone</label>
                        <input type="text" name="phone" value="<?php echo safeText($old["phone"]); ?>">
                    </div>

                    <div>
                        <label>Linked System User</label>
                        <select name="user_id">
                            <option value="">-- Not linked --</option>

                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo safeText($user["id"]); ?>" <?php if ((string)$old["user_id"] === (string)$user["id"]) echo "selected"; ?>>
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
                <textarea name="notes"><?php echo safeText($old["notes"]); ?></textarea>
            </div>

            <br>

            <button type="submit" class="btn">Save Staff</button>
            <a href="program_staff.php" class="btn btn-secondary">Cancel</a>

        </form>
    </div>

</div>

</body>
</html>