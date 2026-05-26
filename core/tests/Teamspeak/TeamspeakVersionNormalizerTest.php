<?php

declare(strict_types=1);

namespace App\Tests\Teamspeak;

use App\Module\Teamspeak\Application\Update\TeamspeakVersionNormalizer;
use PHPUnit\Framework\TestCase;

final class TeamspeakVersionNormalizerTest extends TestCase
{
    public function testNormalizesArchiveVersion(): void
    {
        self::assertSame('6.0.0-beta8', TeamspeakVersionNormalizer::normalize('6.0.0-beta8.tar.bz2'));
        self::assertSame('6.0.0-beta8', TeamspeakVersionNormalizer::normalize('teamspeak-server_linux_amd64-v6.0.0-beta8.tar.bz2'));
        self::assertSame('6.0.0-beta10', TeamspeakVersionNormalizer::normalize('v6.0.0-beta10'));
    }

    public function testIgnoresNonVersionFilename(): void
    {
        self::assertNull(TeamspeakVersionNormalizer::normalize('teamspeak6-server-linux-amd64.tar.xz'));
    }
}
