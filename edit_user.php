<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole("Admin");

$error = "";

// ===============================
// Check user ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid user ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Load user
// ===============================
$stmt = $conn->prepare("
    SELECT 
        id,
        full_name,
        email,
        role,
        status
    FROM users
    WHERE id = :id
");
$stmt->execute([":id" => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User not found.");
}

// ===============================
// Handle update
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $role = trim($_POST["role"] ?? "");
    $status = trim($_POST["status"] ?? "");
    $new_password = $_POST["new_password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($full_name === "") {
        $error = "Full name is required.";
    } elseif ($email === "") {
        $error = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif ($role === "") {
        $error = "Role is required.";
    } elseif ($status === "") {
        $error = "Status is required.";
    } elseif ($new_password !== "" && strlen($new_password) < 8) {
        $error = "New password must be at least 8 characters.";
    } elseif ($new_password !== "" && $new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // Check duplicate email except current user
        $checkStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM users 
            WHERE email = :email 
              AND id <> :id
        ");
        $checkStmt->execute([
            ":email" => $email,
            ":id" => $id
        ]);

        $exists = (int)$checkStmt->fetchColumn();

        if ($exists > 0) {
            $error = "This email is already used by another user.";
        } else {
            if ($new_password !== "") {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);

                $sql = "
                    UPDATE users
                    SET 
                        full_name = :full_name,
                        email = :email,
                        role = :role,
                        status = :status,
                        password_hash = :password_hash
                    WHERE id = :id
                ";

                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ":full_name" => $full_name,
                    ":email" => $email,
                    ":role" => $role,
                    ":status" => $status,
                    ":password_hash" => $password_hash,
                    ":id" => $id
                ]);
            } else {
                $sql = "
                    UPDATE users
                    SET 
                        full_name = :full_name,
                        email = :email,
                        role = :role,
                        status = :status
                    WHERE id = :id
                ";

                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ":full_name" => $full_name,
                    ":email" => $email,
                    ":role" => $role,
                    ":status" => $status,
                    ":id" => $id
                ]);
            }

            // If current admin edited their own account, update session values
            if ((int)($_SESSION["user_id"] ?? 0) === $id) {
                $_SESSION["full_name"] = $full_name;
                $_SESSION["email"] = $email;
                $_SESSION["role"] = $role;
            }

            header("Location: users.php");
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit User</title>
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

        .user-form {
            max-width: 700px;
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
            .user-form {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Edit User</h1>
        <p>Update user identity, email, role, account status, and optional password reset.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="edit_user.php?id=<?php echo htmlspecialchars($id); ?>" class="user-form">

            <div class="form-section">
                <h2>User Identity</h2>

                <label>Full Name</label>
                <input type="text" name="full_name" required
                       value="<?php echo htmlspecialchars($user["full_name"] ?? ""); ?>">

                <label>Email</label>
                <input type="email" name="email" required
                       value="<?php echo htmlspecialchars($user["email"] ?? ""); ?>">
            </div>

            <div class="form-section">
                <h2>Access and Status</h2>

                <label>Role</label>
                <select name="role" required>
                    <?php
                    $roles = ["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"];
                    foreach ($roles as $role):
                    ?>
                        <option value="<?php echo htmlspecialchars($role); ?>"
                            <?php if (($user["role"] ?? "") === $role) echo "selected"; ?>>
                            <?php echo htmlspecialchars($role); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Status</label>
                <select name="status" required>
                    <?php
                    $statuses = ["Active", "Inactive"];
                    foreach ($statuses as $status):
                    ?>
                        <option value="<?php echo htmlspecialchars($status); ?>"
                            <?php if (($user["status"] ?? "") === $status) echo "selected"; ?>>
                            <?php echo htmlspecialchars($status); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-section">
                <h2>Password Reset</h2>

                <label>New Password</label>
                <input type="password" name="new_password">
                <div class="hint">
                    Leave blank if you do not want to change the password. Minimum 8 characters if used.
                </div>

                <label>Confirm New Password</label>
                <input type="password" name="confirm_password">
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Update User</button>
                <a href="users.php" class="btn btn-secondary">Back to Users</a>
            </div>

        </form>

    </div>

</div>

</body>
</html>