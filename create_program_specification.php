<?php
declare(strict_types=1);
require_once __DIR__.'/auth_check.php';
require_once __DIR__.'/role_check.php';
requireRole(['Admin','Quality Coordinator','Program Leader']);
header('Location: /program-specifications/create',true,($_SERVER['REQUEST_METHOD']??'GET')==='POST'?307:302);
exit;
