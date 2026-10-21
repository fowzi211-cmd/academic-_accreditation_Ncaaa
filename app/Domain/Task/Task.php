<?php
declare(strict_types=1);
namespace QualitySystem\Domain\Task;
final readonly class Task
{
    public function __construct(public ?int $id,public string $title,public string $description,public ?int $assignedUserId,public ?int $programId,public ?int $createdByUserId,public ?string $dueDate,public string $priority,public string $status,public string $notes){}
    public static function fromArray(array $row):self{return new self(isset($row['id'])?(int)$row['id']:null,(string)($row['task_title']??''),(string)($row['task_description']??''),isset($row['assigned_user_id'])?(int)$row['assigned_user_id']:null,isset($row['program_specification_id'])?(int)$row['program_specification_id']:null,isset($row['created_by_user_id'])?(int)$row['created_by_user_id']:null,$row['due_date']?:null,(string)($row['priority']??'Medium'),(string)($row['status']??'Pending'),(string)($row['notes']??''));}
}
