<?php
declare(strict_types=1);
namespace QualitySystem\Tests;
use PHPUnit\Framework\TestCase;
use QualitySystem\Http\CompatibilityRouter;
final class RouterSecurityTest extends TestCase
{
    public function testAuthenticationRedirectUsesAbsoluteLoginPath(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/auth_check.php');
        self::assertStringContainsString("Location: /login.php", $source);
        self::assertStringNotContainsString('Location: login.php', $source);
    }
    public function testRouterBlocksInternalDirectories(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/app/Http/CompatibilityRouter.php');
        foreach (['database', 'tests', 'scripts', 'app', 'vendor'] as $directory) self::assertStringContainsString($directory, $source);
    }
    public function testRouterRejectsTraversal(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/app/Http/CompatibilityRouter.php');
        self::assertStringContainsString("str_contains(\$path, '..')", $source);
    }
}
