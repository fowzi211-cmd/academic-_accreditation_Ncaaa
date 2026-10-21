<?php
declare(strict_types=1);
require_once __DIR__.'/auth_check.php';
require_once __DIR__.'/role_check.php';
requireRole(['Admin','Quality Coordinator']);
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT);
if(!$id){http_response_code(400);exit('Invalid program specification ID.');}
header('Location: /program-specifications/'.$id.'/delete',true,307);
exit;
