<?php
declare(strict_types=1);
return static function(PDO $pdo):void{$columns=$pdo->query('SHOW COLUMNS FROM course_reports')->fetchAll(PDO::FETCH_COLUMN);if(!in_array('created_by_user_id',$columns,true))$pdo->exec('ALTER TABLE course_reports ADD COLUMN created_by_user_id INT NULL AFTER course_specification_id, ADD KEY idx_course_reports_creator(created_by_user_id), ADD CONSTRAINT fk_course_reports_creator FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL');};
