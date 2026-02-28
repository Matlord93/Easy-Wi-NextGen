<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;

final class GdprExportCleanupCommandTest extends TestCase
{
    public function testPlaceholder(): void
    {
        self::markTestSkipped('Covered via integration smoke in console environment.');
    }
}
