<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }
$admin = require 'C:\quality_system_config\database-admin.php';
$application = require 'C:\quality_system_config\database.php';
echo json_encode([
    'host' => $admin['host'],
    'port' => $admin['port'],
    'user' => $admin['user'],
    'password' => $admin['password'],
    'name' => $application['name'],
], JSON_THROW_ON_ERROR);
