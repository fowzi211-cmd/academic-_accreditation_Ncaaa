<?php
declare(strict_types=1);
return static function(PDO $pdo):void{
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_program_access (
        user_id INT NOT NULL, program_specification_id INT NOT NULL,
        access_level VARCHAR(30) NOT NULL DEFAULT 'Member', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(user_id,program_specification_id), KEY idx_upa_program(program_specification_id),
        CONSTRAINT fk_upa_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_upa_program FOREIGN KEY(program_specification_id) REFERENCES program_specifications(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $columns=$pdo->query('SHOW COLUMNS FROM tasks')->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('assigned_user_id',$columns,true))$pdo->exec('ALTER TABLE tasks ADD COLUMN assigned_user_id INT NULL AFTER responsible_person, ADD KEY idx_tasks_assigned_user(assigned_user_id), ADD CONSTRAINT fk_tasks_assigned_user FOREIGN KEY(assigned_user_id) REFERENCES users(id) ON DELETE SET NULL');
    if(!in_array('program_specification_id',$columns,true))$pdo->exec('ALTER TABLE tasks ADD COLUMN program_specification_id INT NULL AFTER assigned_user_id, ADD KEY idx_tasks_program(program_specification_id), ADD CONSTRAINT fk_tasks_program FOREIGN KEY(program_specification_id) REFERENCES program_specifications(id) ON DELETE SET NULL');
    if(!in_array('created_by_user_id',$columns,true))$pdo->exec('ALTER TABLE tasks ADD COLUMN created_by_user_id INT NULL AFTER program_specification_id, ADD KEY idx_tasks_created_by(created_by_user_id), ADD CONSTRAINT fk_tasks_created_by FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL');
    $pdo->exec("UPDATE tasks t JOIN users u ON LOWER(TRIM(t.responsible_person)) IN (LOWER(TRIM(u.full_name)),LOWER(TRIM(u.email))) SET t.assigned_user_id=u.id WHERE t.assigned_user_id IS NULL");
};
