<?php

declare(strict_types=1);

namespace QualitySystem\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

final class ProjectSecurityTest extends TestCase
{
    private function phpFiles(): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__)));
        $files = new RegexIterator($iterator, '/\.php$/i');

        foreach ($files as $file) {
            $path = $file->getPathname();
            if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            yield $path;
        }
    }

    public function testNoLegacyDeleteLinksRemain(): void
    {
        foreach ($this->phpFiles() as $path) {
            self::assertDoesNotMatchRegularExpression(
                '/<a\s+[^>]*href=["\']delete[^"\']*\.php/i',
                (string)file_get_contents($path),
                "Legacy destructive GET link remains in {$path}"
            );
        }
    }

    public function testEveryWebPostHandlerLoadsSecurityBootstrap(): void
    {
        foreach ($this->phpFiles() as $path) {
            $source = (string)file_get_contents($path);
            if (!str_contains($source, '$_POST') && !preg_match('/REQUEST_METHOD.{0,30}POST/s', $source)) {
                continue;
            }

            if (str_ends_with($path, DIRECTORY_SEPARATOR . 'security.php')) {
                continue;
            }

            self::assertMatchesRegularExpression(
                '/auth_check\.php|security\.php/',
                $source,
                "POST handler does not load centralized CSRF enforcement: {$path}"
            );
        }
    }

    public function testWebEndpointsContainNoRuntimeDdl(): void
    {
        foreach ($this->phpFiles() as $path) {
            if (str_contains($path, DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            self::assertDoesNotMatchRegularExpression(
                '/\b(?:CREATE\s+TABLE|ALTER\s+TABLE|CREATE\s+INDEX|DROP\s+TABLE)\b/i',
                (string)file_get_contents($path),
                "Runtime DDL remains in web-accessible code: {$path}"
            );
        }
    }
}
