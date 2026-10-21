<?php

declare(strict_types=1);

namespace QualitySystem\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class MajorPagesSmokeTest extends TestCase
{
    public static function majorPages(): array
    {
        return array_map(static fn(string $file): array => [$file], [
            'home.php', 'dashboard.php', 'index.php', 'tasks.php', 'course_reports.php',
            'course_specifications.php', 'semester_reports.php', 'kpis.php', 'program_staff.php',
            'committees.php', 'study_plans.php', 'program_specifications.php', 'health_check.php',
        ]);
    }

    #[Group('database')]
    #[DataProvider('majorPages')]
    public function testMajorPageRunsWithRestrictedAccount(string $file): void
    {
        $project = dirname(__DIR__);
        $code = sprintf(<<<'PHP'
chdir(%s);
ini_set('session.save_path', 'C:/quality_system_storage/temp_exports');
$_SERVER['SCRIPT_NAME'] = '/%s';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
session_id('smoke-' . bin2hex(random_bytes(8)));
session_start();
$_SESSION = ['user_id' => 1, 'full_name' => 'Smoke Test', 'role' => 'Admin'];
include %s;
PHP,
            var_export($project, true),
            $file,
            var_export($project . DIRECTORY_SEPARATOR . $file, true)
        );

        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', $code], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $project, getenv());

        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, "{$file} exited unexpectedly: {$stderr}");
        self::assertStringNotContainsString('Fatal error', $stderr . $stdout, "{$file} produced a fatal error");
        self::assertStringNotContainsString('Database schema is missing', $stderr . $stdout, "{$file} reported an incomplete schema");
    }
}
