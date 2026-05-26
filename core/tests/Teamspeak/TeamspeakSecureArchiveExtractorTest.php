<?php

declare(strict_types=1);

namespace App\Tests\Teamspeak;

use App\Module\Teamspeak\Application\Update\TeamspeakSecureArchiveExtractor;
use PHPUnit\Framework\TestCase;

final class TeamspeakSecureArchiveExtractorTest extends TestCase
{
    public function testRejectsPathTraversalViaValidator(): void
    {
        $extractor = new class extends TeamspeakSecureArchiveExtractor {
            public function expose(string $p): void { $ref = new \ReflectionMethod(TeamspeakSecureArchiveExtractor::class, 'assertSafePath'); $ref->setAccessible(true); $ref->invoke($this, $p); }
        };
        $this->expectException(\RuntimeException::class);
        $extractor->expose('../evil');
    }
}
