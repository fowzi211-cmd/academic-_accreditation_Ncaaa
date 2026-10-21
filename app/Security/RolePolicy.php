<?php
declare(strict_types=1);
namespace QualitySystem\Security;
final class RolePolicy
{
    public const ROLES = ['Admin', 'Quality Coordinator', 'Program Leader', 'Faculty', 'Reviewer'];
    public static function allows(string $role, array|string $allowedRoles): bool { return in_array($role, (array)$allowedRoles, true); }
    public static function ownsRecord(string $role, int $userId, ?int $ownerId, array $managerRoles = ['Admin', 'Quality Coordinator', 'Program Leader']): bool
    { return self::allows($role, $managerRoles) || ($ownerId !== null && $ownerId === $userId); }
}
