<?php
declare(strict_types=1);
namespace QualitySystem\Security;
final class PermissionMatrix
{
    private const MATRIX=[
        'tasks'=>[
            'view'=>['Admin','Quality Coordinator','Program Leader','Faculty','Reviewer'],
            'create'=>['Admin','Quality Coordinator','Program Leader'],
            'update'=>['Admin','Quality Coordinator','Program Leader','Faculty'],
            'delete'=>['Admin','Quality Coordinator'],
            'export'=>['Admin','Quality Coordinator','Program Leader','Faculty'],
        ],
        'administration'=>['view'=>['Admin'],'manage'=>['Admin']],
        'reports'=>['view'=>['Admin','Quality Coordinator','Program Leader','Faculty','Reviewer']],
        'course_reports'=>[
            'view'=>['Admin','Quality Coordinator','Program Leader','Faculty','Reviewer'],
            'create'=>['Admin','Quality Coordinator','Program Leader'],
            'update'=>['Admin','Quality Coordinator','Program Leader','Faculty'],
            'delete'=>['Admin','Quality Coordinator'],
        ],
        'programs'=>[
            'view'=>['Admin','Quality Coordinator','Program Leader','Reviewer'],
            'create'=>['Admin','Quality Coordinator','Program Leader'],
            'update'=>['Admin','Quality Coordinator','Program Leader'],
            'delete'=>['Admin','Quality Coordinator'],
            'manage'=>['Admin','Quality Coordinator','Program Leader'],
        ],
        'program_manuals'=>[
            'view'=>['Admin','Quality Coordinator','Program Leader','Reviewer'],
            'create'=>['Admin','Quality Coordinator','Program Leader'],
            'update'=>['Admin','Quality Coordinator','Program Leader'],
            'delete'=>['Admin','Quality Coordinator'],
            'approve'=>['Admin','Quality Coordinator'],
        ],
        'backups'=>['view'=>['Admin','Quality Coordinator'],'manage'=>['Admin','Quality Coordinator']],
    ];
    public static function allows(string $role,string $module,string $action):bool{return in_array($role,self::MATRIX[$module][$action]??[],true);}
    public static function roles(string $module,string $action):array{return self::MATRIX[$module][$action]??[];}
}
