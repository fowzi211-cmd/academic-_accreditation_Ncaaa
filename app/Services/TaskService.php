<?php
declare(strict_types=1);
namespace QualitySystem\Services;
use PDO;use RuntimeException;use QualitySystem\Domain\Task\Task;use QualitySystem\Domain\Task\TaskValidator;use QualitySystem\Repositories\TaskRepository;use QualitySystem\Security\AuditLogger;use QualitySystem\Security\PermissionMatrix;
final class TaskService
{
    public function __construct(private readonly PDO $pdo,private readonly TaskRepository $repository){}
    public function save(array $input,string $role,int $userId,?int $id=null):int{
        $action=$id===null?'create':'update';if(!PermissionMatrix::allows($role,'tasks',$action))throw new RuntimeException('Forbidden',403);
        $errors=TaskValidator::validate($input);if($errors)throw new TaskValidationException($errors);
        $existing=$id!==null?$this->repository->findScoped($id,$role,$userId):null;if($id!==null&&!$existing)throw new RuntimeException('Task not found.',404);
        $assigned=($input['assigned_user_id']??'')!==''?(int)$input['assigned_user_id']:null;$program=($input['program_specification_id']??'')!==''?(int)$input['program_specification_id']:null;
        if($role==='Faculty'&&$existing){$assigned=$existing['assigned_user_id']!==null?(int)$existing['assigned_user_id']:null;$program=$existing['program_specification_id']!==null?(int)$existing['program_specification_id']:null;$input['task_title']=$existing['task_title'];$input['task_description']=$existing['task_description'];$input['priority']=$existing['priority'];}
        $task=new Task($id,trim((string)$input['task_title']),trim((string)($input['task_description']??'')),$assigned,$program,$id===null?$userId:(isset($existing['created_by_user_id'])?(int)$existing['created_by_user_id']:null),($input['due_date']??'')!==''?(string)$input['due_date']:null,(string)($input['priority']??'Medium'),(string)($input['status']??'Pending'),trim((string)($input['notes']??'')));
        $this->pdo->beginTransaction();try{$oldStatus=$existing['status']??null;if($id===null)$id=$this->repository->insert($task);else$this->repository->update($task);if($oldStatus!==null&&$oldStatus!==$task->status){$s=$this->pdo->prepare('INSERT INTO task_status_logs(task_id,old_status,new_status,changed_by,change_source,change_comment) VALUES(:task,:old,:new,:by,:source,:comment)');$s->execute([':task'=>$id,':old'=>$oldStatus,':new'=>$task->status,':by'=>$_SESSION['full_name']??'User',':source'=>'Task service',':comment'=>'Status updated through scoped task module.']);}AuditLogger::record($this->pdo,'task_'.$action,'allowed','Tasks',[], 'task',$id);$this->pdo->commit();return$id;}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }
    public function delete(int $id,string $role,int $userId):void{if(!PermissionMatrix::allows($role,'tasks','delete'))throw new RuntimeException('Forbidden',403);if(!$this->repository->findScoped($id,$role,$userId))throw new RuntimeException('Task not found.',404);$this->pdo->beginTransaction();try{$this->repository->delete($id);AuditLogger::record($this->pdo,'task_delete','allowed','Tasks',[],'task',$id);$this->pdo->commit();}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}}
}
final class TaskValidationException extends RuntimeException{public function __construct(public readonly array $errors){parent::__construct('Validation failed.',422);}}
