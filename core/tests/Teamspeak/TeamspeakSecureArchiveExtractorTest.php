<?php

declare(strict_types=1);

namespace App\Tests\Teamspeak;

use App\Module\Teamspeak\Application\Update\TeamspeakSecureArchiveExtractor;
use PHPUnit\Framework\TestCase;

final class TeamspeakSecureArchiveExtractorTest extends TestCase
{
    public function testRejectsPathTraversalViaValidator(): void
    {
        $extractor = new TeamspeakSecureArchiveExtractor();
        $ref = new \ReflectionMethod(TeamspeakSecureArchiveExtractor::class, 'assertSafePath');
        $ref->setAccessible(true);
        $this->expectException(\RuntimeException::class);
        $ref->invoke($extractor, '../evil');
    }
}
