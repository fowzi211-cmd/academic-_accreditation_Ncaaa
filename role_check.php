<?php
require_once __DIR__ . '/security.php';

// ===============================
// Require one or more allowed roles
// ===============================
function requireRole($allowedRoles) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION["user_id"])) {
        header("Location: login.php");
        exit;
    }

    if (!isset($_SESSION["role"])) {
        die("Access denied. No role assigned.");
    }

    if (!is_array($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }

    if (!\QualitySystem\Security\RolePolicy::allows((string)$_SESSION["role"], $allowedRoles)) {
        http_response_code(403);
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
            \QualitySystem\Security\AuditLogger::record($GLOBALS['conn'], 'authorization_denied', 'denied', basename($_SERVER['SCRIPT_NAME'] ?? 'unknown'), ['required_roles' => $allowedRoles]);
        }
        exit("Access denied. You do not have permission to access this page.");
    }
}

// ===============================
// Check exact role
// ===============================
function isRole($role) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return isset($_SESSION["role"]) && $_SESSION["role"] === $role;
}

// ===============================
// Check whether current user has one of the allowed roles
// ===============================
function hasRole($allowedRoles) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION["role"])) {
        return false;
    }

    if (!is_array($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }

    return \QualitySystem\Security\RolePolicy::allows((string)$_SESSION["role"], $allowedRoles);
}

function requireProgramSpecificationAccess(PDO $conn, int $programSpecificationId): void {
    $role = (string)($_SESSION['role'] ?? '');
    $exists = $conn->prepare('SELECT 1 FROM program_specifications WHERE id = :program LIMIT 1');
    $exists->execute([':program' => $programSpecificationId]);
    if (!$exists->fetchColumn()) {
        header('Location: /program-specifications', true, 302);
        exit;
    }
    if (in_array($role, ['Admin', 'Quality Coordinator'], true)) return;
    $statement = $conn->prepare('SELECT 1 FROM user_program_access WHERE user_id = :user AND program_specification_id = :program LIMIT 1');
    $statement->execute([':user' => (int)($_SESSION['user_id'] ?? 0), ':program' => $programSpecificationId]);
    if (!$statement->fetchColumn()) {
        http_response_code(404);
        exit('Program specification not found.');
    }
}

function requireCourseReportAccess(PDO $conn, int $courseReportId): void {
    $role = (string)($_SESSION['role'] ?? '');
    if (in_array($role, ['Admin', 'Quality Coordinator'], true)) return;
    $statement = $conn->prepare('SELECT 1 FROM course_reports cr LEFT JOIN course_specifications cs ON cs.id=cr.course_specification_id WHERE cr.id=:report AND (cr.created_by_user_id=:creator OR cs.program_specification_id IN (SELECT program_specification_id FROM user_program_access WHERE user_id=:program_user)) LIMIT 1');
    $statement->execute([':report'=>$courseReportId,':creator'=>(int)($_SESSION['user_id']??0),':program_user'=>(int)($_SESSION['user_id']??0)]);
    if (!$statement->fetchColumn()) { http_response_code(404); exit('Course report not found.'); }
}
