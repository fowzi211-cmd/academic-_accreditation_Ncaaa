<?php
declare(strict_types=1);
namespace QualitySystem\Controllers;
require_once dirname(__DIR__,2).'/security.php';
use PDO;use QualitySystem\Repositories\TaskRepository;use QualitySystem\Security\PermissionMatrix;use QualitySystem\Services\TaskService;use QualitySystem\Services\TaskValidationException;
final class TaskController
{
    private TaskRepository $repository;private TaskService $service;
    public function __construct(private readonly PDO $pdo,private readonly string $root){$this->repository=new TaskRepository($pdo);$this->service=new TaskService($pdo,$this->repository);}
    public function dispatch(string $path,string $method):void{
        $role=(string)($_SESSION['role']??'');$userId=(int)($_SESSION['user_id']??0);if(!PermissionMatrix::allows($role,'tasks','view')){$this->abort(403,'Access denied.');}
        if($path==='/tasks'&&$method==='GET'){$this->index($role,$userId);return;}
        if($path==='/tasks/create'){if(!PermissionMatrix::allows($role,'tasks','create'))$this->abort(403,'Access denied.');$method==='POST'?$this->save(null,$role,$userId):$this->form(null,$role,$userId);return;}
        if(preg_match('#^/tasks/(\d+)/edit$#',$path,$m)){if(!PermissionMatrix::allows($role,'tasks','update'))$this->abort(403,'Access denied.');$method==='POST'?$this->save((int)$m[1],$role,$userId):$this->form((int)$m[1],$role,$userId);return;}
        if(preg_match('#^/tasks/(\d+)/delete$#',$path,$m)&&$method==='POST'){$this->service->delete((int)$m[1],$role,$userId);header('Location: /tasks');return;}
        $this->abort(404,'Not found.');
    }
    private function index(string $role,int $userId):void{$page=max(1,(int)($_GET['page']??1));$perPage=min(50,max(10,(int)($_GET['per_page']??20)));$search=mb_substr(trim((string)($_GET['search']??'')),0,100);$result=$this->repository->paginate($role,$userId,$page,$perPage,$search);$this->render('tasks/index',['rows'=>$result['rows'],'total'=>$result['total'],'page'=>$page,'perPage'=>$perPage,'search'=>$search,'role'=>$role]);}
    private function form(?int $id,string $role,int $userId,array $errors=[],array $input=[]):void{$task=$id!==null?$this->repository->findScoped($id,$role,$userId):null;if($id!==null&&!$task)$this->abort(404,'Task not found.');if($input)$task=array_merge($task??[],$input);$this->render('tasks/form',['task'=>$task,'errors'=>$errors,'users'=>$this->repository->activeUsers(),'programs'=>$this->repository->programs($role,$userId),'role'=>$role]);}
    private function save(?int $id,string $role,int $userId):void{try{$saved=$this->service->save($_POST,$role,$userId,$id);header('Location: /tasks');}catch(TaskValidationException $e){http_response_code(422);$this->form($id,$role,$userId,$e->errors,$_POST);}catch(\RuntimeException $e){$this->abort(in_array($e->getCode(),[403,404],true)?$e->getCode():500,$e->getMessage());}}
    private function render(string $template,array $data):void{extract($data,EXTR_SKIP);require $this->root.'/templates/'.$template.'.php';}
    private function abort(int $status,string $message):never{http_response_code($status);exit(htmlspecialchars($message,ENT_QUOTES,'UTF-8'));}
}
