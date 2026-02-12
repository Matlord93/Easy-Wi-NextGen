<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application;

use App\Module\Core\Application\WebspacePathSanitizer;
use PHPUnit\Framework\TestCase;

final class WebspacePathSanitizerTest extends TestCase
{
    public function testRejectsAbsoluteAndTraversalPaths(): void
    {
        $sanitizer = new WebspacePathSanitizer();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_path');
        $sanitizer->sanitizeRelativePath('/etc/passwd');
    }

    public function testRejectsTraversal(): void
    {
        $sanitizer = new WebspacePathSanitizer();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path_outside_webspace_root');
        $sanitizer->sanitizeRelativePath('../outside');
    }

    public function testNormalizesSafeRelativePath(): void
    {
        $sanitizer = new WebspacePathSanitizer();

        self::assertSame('public/site', $sanitizer->sanitizeRelativePath('./public//site'));
    }

    public function testRejectsControlCharacters(): void
    {
        $sanitizer = new WebspacePathSanitizer();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_path');
        $sanitizer->sanitizeRelativePath("public\x00bad");
    }

    public function testNormalizesWindowsLikeSeparators(): void
    {
        $sanitizer = new WebspacePathSanitizer();

        self::assertSame('public/uploads', $sanitizer->sanitizeRelativePath('public\\uploads'));
    }
}
