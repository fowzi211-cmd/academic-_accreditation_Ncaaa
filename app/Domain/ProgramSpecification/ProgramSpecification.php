<?php
declare(strict_types=1);
namespace QualitySystem\Domain\ProgramSpecification;
final readonly class ProgramSpecification
{
    public function __construct(public ?int $id,public array $fields){}
}
