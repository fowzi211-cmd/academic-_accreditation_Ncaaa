<?php
declare(strict_types=1);
namespace QualitySystem\Repositories;
use PDO;use QualitySystem\Domain\Task\Task;
final class TaskRepository
{
    public function __construct(private readonly PDO $pdo){}
    private function scope(string $role,int $userId,array &$params,string $alias='t'):string{
        if(in_array($role,['Admin','Quality Coordinator'],true))return '1=1';
        $params[':scope_assigned']=$userId;$params[':scope_creator']=$userId;$params[':scope_program']=$userId;
        return "($alias.assigned_user_id=:scope_assigned OR $alias.created_by_user_id=:scope_creator OR $alias.program_specification_id IN (SELECT program_specification_id FROM user_program_access WHERE user_id=:scope_program))";
    }
    public function paginate(string $role,int $userId,int $page,int $perPage,string $search=''):array{
        $params=[];$where=$this->scope($role,$userId,$params);if($search!==''){$where.=' AND (t.task_title LIKE :search OR t.task_description LIKE :search)';$params[':search']='%'.$search.'%';}
        $count=$this->pdo->prepare("SELECT COUNT(*) FROM tasks t WHERE $where");$count->execute($params);
        $sql="SELECT t.*,u.full_name assigned_name,p.program_name FROM tasks t LEFT JOIN users u ON u.id=t.assigned_user_id LEFT JOIN program_specifications p ON p.id=t.program_specification_id WHERE $where ORDER BY t.id DESC LIMIT :limit OFFSET :offset";
        $statement=$this->pdo->prepare($sql);foreach($params as $key=>$value)$statement->bindValue($key,$value);$statement->bindValue(':limit',$perPage,PDO::PARAM_INT);$statement->bindValue(':offset',($page-1)*$perPage,PDO::PARAM_INT);$statement->execute();return ['rows'=>$statement->fetchAll(PDO::FETCH_ASSOC),'total'=>(int)$count->fetchColumn()];
    }
    public function findScoped(int $id,string $role,int $userId):?array{$params=[':id'=>$id];$scope=$this->scope($role,$userId,$params);$s=$this->pdo->prepare("SELECT * FROM tasks t WHERE t.id=:id AND $scope LIMIT 1");$s->execute($params);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
    public function insert(Task $task):int{$s=$this->pdo->prepare('INSERT INTO tasks(task_title,task_description,responsible_person,assigned_user_id,program_specification_id,created_by_user_id,due_date,priority,status,notes) SELECT :title,:description,u.full_name,:assigned_value,:program,:creator,:due,:priority,:status,:notes FROM (SELECT 1) seed LEFT JOIN users u ON u.id=:assigned_join');$s->execute([':title'=>$task->title,':description'=>$task->description,':assigned_value'=>$task->assignedUserId,':assigned_join'=>$task->assignedUserId,':program'=>$task->programId,':creator'=>$task->createdByUserId,':due'=>$task->dueDate,':priority'=>$task->priority,':status'=>$task->status,':notes'=>$task->notes]);return(int)$this->pdo->lastInsertId();}
    public function update(Task $task):void{$s=$this->pdo->prepare('UPDATE tasks t LEFT JOIN users u ON u.id=:assigned_join SET t.task_title=:title,t.task_description=:description,t.responsible_person=u.full_name,t.assigned_user_id=:assigned_value,t.program_specification_id=:program,t.due_date=:due,t.priority=:priority,t.status=:status,t.notes=:notes WHERE t.id=:id');$s->execute([':id'=>$task->id,':title'=>$task->title,':description'=>$task->description,':assigned_value'=>$task->assignedUserId,':assigned_join'=>$task->assignedUserId,':program'=>$task->programId,':due'=>$task->dueDate,':priority'=>$task->priority,':status'=>$task->status,':notes'=>$task->notes]);}
    public function delete(int $id):void{$s=$this->pdo->prepare('DELETE FROM tasks WHERE id=:id');$s->execute([':id'=>$id]);}
    public function activeUsers():array{return $this->pdo->query("SELECT id,full_name,email FROM users WHERE status='Active' ORDER BY full_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);}
    public function programs(string $role,int $userId):array{if(in_array($role,['Admin','Quality Coordinator'],true))return $this->pdo->query('SELECT id,program_name FROM program_specifications ORDER BY program_name LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);$s=$this->pdo->prepare('SELECT p.id,p.program_name FROM program_specifications p JOIN user_program_access a ON a.program_specification_id=p.id WHERE a.user_id=:user ORDER BY p.program_name LIMIT 500');$s->execute([':user'=>$userId]);return$s->fetchAll(PDO::FETCH_ASSOC);}
}
