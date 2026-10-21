<?php
declare(strict_types=1);
namespace QualitySystem\Tests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QualitySystem\Security\RolePolicy;
final class AuthorizationPolicyTest extends TestCase
{
    public static function roleCases(): array
    {
        return [
            ['Admin', ['Admin'], true], ['Quality Coordinator', ['Admin'], false],
            ['Reviewer', ['Admin', 'Reviewer'], true], ['Faculty', ['Admin', 'Reviewer'], false],
            ['Program Leader', ['Admin', 'Quality Coordinator', 'Program Leader'], true],
        ];
    }
    #[DataProvider('roleCases')]
    public function testRoleMatrix(string $role, array $allowed, bool $expected): void { self::assertSame($expected, RolePolicy::allows($role, $allowed)); }
    public function testFacultyCanAccessOnlyOwnedRecord(): void
    {
        self::assertTrue(RolePolicy::ownsRecord('Faculty', 42, 42));
        self::assertFalse(RolePolicy::ownsRecord('Faculty', 42, 43));
        self::assertTrue(RolePolicy::ownsRecord('Admin', 1, 43));
    }
    public function testAllKnownRolesAreUnique(): void { self::assertSame(RolePolicy::ROLES, array_values(array_unique(RolePolicy::ROLES))); }
}
