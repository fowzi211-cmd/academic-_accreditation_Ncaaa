<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }
$c=require 'C:\quality_system_config\database.php';
$pdo=new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$report=['database'=>$c['name'],'foreign_keys'=>0,'orphan_counts'=>[],'duplicate_counts'=>[],'unindexed_foreign_keys'=>[]];
$fks=$pdo->query("SELECT k.TABLE_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,k.CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE k WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
$report['foreign_keys']=count($fks);
foreach($fks as $fk){
    foreach($fk as $value){if(!preg_match('/^[A-Za-z0-9_]+$/',(string)$value))throw new RuntimeException('Unsafe schema identifier.');}
    $sql="SELECT COUNT(*) FROM `{$fk['TABLE_NAME']}` c LEFT JOIN `{$fk['REFERENCED_TABLE_NAME']}` p ON p.`{$fk['REFERENCED_COLUMN_NAME']}`=c.`{$fk['COLUMN_NAME']}` WHERE c.`{$fk['COLUMN_NAME']}` IS NOT NULL AND p.`{$fk['REFERENCED_COLUMN_NAME']}` IS NULL";
    $count=(int)$pdo->query($sql)->fetchColumn(); if($count)$report['orphan_counts'][$fk['CONSTRAINT_NAME']]=$count;
}
$duplicateChecks=['users.email'=>'SELECT COUNT(*) FROM (SELECT email FROM users GROUP BY email HAVING COUNT(*)>1) d'];
foreach($duplicateChecks as $name=>$sql){$count=(int)$pdo->query($sql)->fetchColumn();if($count)$report['duplicate_counts'][$name]=$count;}
$report['unindexed_foreign_keys']=$pdo->query("SELECT DISTINCT k.TABLE_NAME,k.COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE k WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=k.CONSTRAINT_SCHEMA AND s.TABLE_NAME=k.TABLE_NAME AND s.COLUMN_NAME=k.COLUMN_NAME AND s.SEQ_IN_INDEX=1)")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit(($report['orphan_counts']||$report['duplicate_counts']||$report['unindexed_foreign_keys'])?1:0);
