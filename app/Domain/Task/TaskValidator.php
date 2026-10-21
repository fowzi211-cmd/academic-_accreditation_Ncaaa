<?php
declare(strict_types=1);
namespace QualitySystem\Domain\Task;
final class TaskValidator
{
    public static function validate(array $input):array{
        $errors=[];$title=trim((string)($input['task_title']??''));
        if($title==='')$errors['task_title']='Title is required.';elseif(mb_strlen($title)>255)$errors['task_title']='Title must not exceed 255 characters.';
        if(!in_array($input['priority']??'Medium',['Low','Medium','High'],true))$errors['priority']='Invalid priority.';
        if(!in_array($input['status']??'Pending',['Pending','In Progress','Completed','Cancelled'],true))$errors['status']='Invalid status.';
        $due=$input['due_date']??'';if($due!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$due))$errors['due_date']='Invalid due date.';
        return $errors;
    }
}
