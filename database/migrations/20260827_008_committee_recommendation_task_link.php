<?php
declare(strict_types=1);
return static function(PDO $pdo):void{
    $columns=$pdo->query('SHOW COLUMNS FROM committee_recommendations')->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('linked_task_id',$columns,true)){
        $pdo->exec('ALTER TABLE committee_recommendations ADD COLUMN linked_task_id INT NULL AFTER linked_evidence_id, ADD KEY idx_recommendations_linked_task(linked_task_id), ADD CONSTRAINT fk_recommendations_linked_task FOREIGN KEY(linked_task_id) REFERENCES tasks(id) ON DELETE SET NULL');
    }
};
