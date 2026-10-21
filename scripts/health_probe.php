<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit('Not found.');}
$c=require 'C:\quality_system_config\database.php';$pdo=new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
echo json_encode(['database'=>'reachable','failed_logins_15m'=>(int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE succeeded=0 AND attempted_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)")->fetchColumn(),'tables'=>(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchColumn()],JSON_THROW_ON_ERROR);
