<?php
declare(strict_types=1);
namespace QualitySystem\Domain\ProgramSpecification;
final class ProgramSpecificationValidator
{
    public static function validate(array $input):array
    {
        $errors=[];
        if(trim((string)($input['specification_title']??''))==='')$errors[]='Specification title is required.';
        if(mb_strlen(trim((string)($input['specification_title']??'')))>255)$errors[]='Specification title must not exceed 255 characters.';
        if(trim((string)($input['program_name']??''))==='')$errors[]='Program name is required.';
        if(mb_strlen(trim((string)($input['program_name']??'')))>255)$errors[]='Program name must not exceed 255 characters.';
        if(($input['total_credit_hours']??'')!==''&&(!ctype_digit((string)$input['total_credit_hours'])||(int)$input['total_credit_hours']>1000))$errors[]='Total credit hours must be a whole number from 0 to 1000.';
        foreach(['last_review_date','approval_date'] as $field){$value=trim((string)($input[$field]??''));if($value!==''&&!self::date($value))$errors[]=ucwords(str_replace('_',' ',$field)).' must be a valid date.';}
        if(!in_array((string)($input['approval_status']??'Draft'),['Draft','Under Review','Approved','Archived'],true))$errors[]='Approval status is invalid.';
        if(!in_array((string)($input['specification_type']??'Updated'),['New','Updated'],true))$errors[]='Specification type is invalid.';
        return $errors;
    }
    private static function date(string $value):bool{$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);return$date!==false&&$date->format('Y-m-d')===$value;}
}
