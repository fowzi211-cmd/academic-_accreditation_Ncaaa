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
// Handle form submission
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";
    $role = trim($_POST["role"] ?? "");
    $status = trim($_POST["status"] ?? "");

    if ($full_name === "") {
        $error = "Full name is required.";
    } elseif ($email === "") {
        $error = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif ($password === "") {
        $error = "Password is required.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif ($role === "") {
        $error = "Role is required.";
    } elseif ($status === "") {
        $error = "Status is required.";
    } else {
        $checkStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM users
            WHERE email = :email
        ");
        $checkStmt->execute([":email" => $email]);
        $exists = (int)$checkStmt->fetchColumn();

        if ($exists > 0) {
            $error = "This email already exists.";
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $sql = "
                INSERT INTO users
                (
                    full_name,
                    email,
                    password_hash,
                    role,
                    status
                )
                VALUES
                (
                    :full_name,
                    :email,
                    :password_hash,
                    :role,
                    :status
                )
            ";

            $stmt = $conn->prepare($sql);

            $stmt->execute([
                ":full_name" => $full_name,
                ":email" => $email,
                ":password_hash" => $password_hash,
                ":role" => $role,
                ":status" => $status
            ]);

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
    <title>Add User</title>
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
        <h1>Add New User</h1>
        <p>Create a new system user and assign role-based access permissions.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="create_user.php" class="user-form">

            <div class="form-section">
                <h2>User Identity</h2>

                <label>Full Name</label>
                <input type="text" name="full_name" required placeholder="Example: Dr. Fowzi">

                <label>Email</label>
                <input type="email" name="email" required placeholder="example@domain.com">
            </div>

            <div class="form-section">
                <h2>Password</h2>

                <label>Password</label>
                <input type="password" name="password" required>
                <div class="hint">Minimum 8 characters.</div>

                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required>
            </div>

            <div class="form-section">
                <h2>Access and Status</h2>

                <label>Role</label>
                <select name="role" required>
                    <option value="">-- Select Role --</option>
                    <option value="Admin">Admin</option>
                    <option value="Quality Coordinator">Quality Coordinator</option>
                    <option value="Program Leader">Program Leader</option>
                    <option value="Faculty">Faculty</option>
                    <option value="Reviewer">Reviewer</option>
                </select>

                <label>Status</label>
                <select name="status" required>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Save User</button>
                <a href="users.php" class="btn btn-secondary">Back to Users</a>
            </div>

        </form>

    </div>

</div>

</body>
</html>