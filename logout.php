<?php
require_once __DIR__ . '/security.php';

// ===============================
// Clear all session variables
// ===============================
$_SESSION = [];

// ===============================
// Remove session cookie if used
// ===============================
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ===============================
// Destroy session
// ===============================
session_destroy();

// ===============================
// Redirect to login
// ===============================
header("Location: login.php");
exit;
