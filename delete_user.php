<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole("Admin");

// ===============================
// Validate user ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid user ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Prevent deleting yourself
// ===============================
if ((int)($_SESSION["user_id"] ?? 0) === $id) {
    die("You cannot delete your own account while logged in.");
}

// ===============================
// Check user exists before deleting
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM users
    WHERE id = :id
");
$stmt->execute([":id" => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User not found.");
}

$userLabel = ($user["full_name"] ?? "") . " | " . ($user["email"] ?? "") . " | " . ($user["role"] ?? "");

// ===============================
// Delete user
// ===============================
$deleteStmt = $conn->prepare("
    DELETE FROM users
    WHERE id = :id
");
$deleteStmt->execute([":id" => $id]);

// ===============================
// Log activity
// ===============================
logActivity(
    $conn,
    "Delete",
    "Users",
    $id,
    "User deleted: " . $userLabel
);

// ===============================
// Redirect
// ===============================
header("Location: users.php");
exit;