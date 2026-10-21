<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/storage.php';

// ===============================
// Require login
// ===============================
if (!isset($_SESSION["user_id"])) {
    header('Location: /login.php');
    exit;
}
