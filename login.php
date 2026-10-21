<?php
require_once __DIR__ . '/security.php';

require_once "db.php";
require_once "log_activity.php";

use QualitySystem\Controllers\LoginController;

$error = "";

// ===============================
// If already logged in, go home
// ===============================
if (isset($_SESSION["user_id"])) {
    header("Location: home.php");
    exit;
}

// ===============================
// Handle login
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if ($email === "" || $password === "") {
        $error = "Email and password are required.";
    } else {
        $result=(new LoginController($conn))->authenticate($email,$password,$clientIp);
        $user=$result['user'];
        if ($result['status'] !== 'allowed') {
            $error = $result['status'] === 'throttled' ? 'Too many sign-in attempts. Please wait and try again.' : 'Invalid email or password.';
        } else {
            session_regenerate_id(true);
            $_SESSION["user_id"] = $user["id"];
            $_SESSION["full_name"] = $user["full_name"];
            $_SESSION["email"] = $user["email"];
            $_SESSION["role"] = $user["role"];

            logActivity(
                $conn,
                "Login",
                "Authentication",
                $user["id"],
                "User logged in successfully."
            );

            header("Location: home.php");
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Login - Quality System</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .login-wrapper {
            min-height: 80vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-box {
            background: white;
            padding: 28px;
            width: 420px;
            max-width: 100%;
            border: 1px solid #ddd;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .login-title {
            text-align: center;
            margin-top: 0;
            margin-bottom: 8px;
        }

        .login-subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 22px;
            font-size: 14px;
        }

        .login-box .btn {
            width: 100%;
            margin-top: 18px;
            padding: 10px 14px;
            font-size: 14px;
        }

        .login-footer {
            margin-top: 18px;
            text-align: center;
            color: #777;
            font-size: 12px;
        }
    </style>
</head>

<body>

<div class="login-wrapper">

    <div class="login-box">
        <h1 class="login-title">Quality System Login</h1>

        <div class="login-subtitle">
            Sign in to access the Quality Management System.
        </div>

        <?php if ($error !== ""): ?>
            <div class="alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <?php echo csrfField(); ?>

            <label for="email">Email</label>
            <input type="email" id="email" name="email" autocomplete="username" required autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>

            <button type="submit" class="btn">Login</button>

        </form>

        <div class="login-footer">
            Local Quality Management System
        </div>
    </div>

</div>

</body>
</html>
