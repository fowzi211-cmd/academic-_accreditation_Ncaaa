<?php

declare(strict_types=1);

namespace QualitySystem\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    public function testStoragePathStaysInsideConfiguredRoot(): void
    {
        self::assertSame(
            'C:\\quality_system_test_storage' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'example.pdf',
            storagePath('uploads/example.pdf')
        );
    }

    public function testStoragePathRejectsTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        storagePath('../db.php');
    }

    public function testDownloadNameRemovesHeaderControlCharacters(): void
    {
        self::assertSame('report_Injected.pdf', safeDownloadName("report\r\nInjected.pdf"));
    }
}
