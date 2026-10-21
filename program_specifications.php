<?php
declare(strict_types=1);
require_once __DIR__.'/auth_check.php';
require_once __DIR__.'/role_check.php';
requireRole(['Admin','Quality Coordinator','Program Leader','Reviewer']);
header('Location: /program-specifications'.(!empty($_SERVER['QUERY_STRING'])?'?'.$_SERVER['QUERY_STRING']:''),true,302);
exit;
