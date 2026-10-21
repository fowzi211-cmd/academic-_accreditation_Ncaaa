<?php

use QualitySystem\Support\AppLogger;

// ===============================
// Log user activity
// ===============================
function logActivity($conn, $action_type, $module_name, $record_id = null, $description = "") {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $user_id = $_SESSION["user_id"] ?? null;
    $user_name = $_SESSION["full_name"] ?? "Unknown user";
    $user_role = $_SESSION["role"] ?? "Unknown role";

    $ip_address = $_SERVER["REMOTE_ADDR"] ?? "";
    $user_agent = $_SERVER["HTTP_USER_AGENT"] ?? "";

    try {
        $sql = "
            INSERT INTO activity_logs
            (
                user_id,
                user_name,
                user_role,
                action_type,
                module_name,
                record_id,
                description,
                ip_address,
                user_agent
            )
            VALUES
            (
                :user_id,
                :user_name,
                :user_role,
                :action_type,
                :module_name,
                :record_id,
                :description,
                :ip_address,
                :user_agent
            )
        ";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ":user_id" => $user_id,
            ":user_name" => $user_name,
            ":user_role" => $user_role,
            ":action_type" => $action_type,
            ":module_name" => $module_name,
            ":record_id" => $record_id,
            ":description" => $description,
            ":ip_address" => $ip_address,
            ":user_agent" => $user_agent
        ]);
    } catch (PDOException $e) {
        AppLogger::write('activity_log_failed', ['error' => $e->getMessage(), 'action_type' => $action_type, 'module_name' => $module_name]);
    }
}
