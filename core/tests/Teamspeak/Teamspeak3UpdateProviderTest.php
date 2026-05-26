<?php

declare(strict_types=1);

namespace App\Tests\Teamspeak;

use App\Module\Teamspeak\Application\Update\Teamspeak3UpdateProvider;
use PHPUnit\Framework\TestCase;

final class Teamspeak3UpdateProviderTest extends TestCase
{
    public function testReturnsSourceNotConfigured(): void
    {
        $provider = new Teamspeak3UpdateProvider();
        $result = $provider->checkForUpdates('3.13.7', 'linux', 'amd64');
        self::assertSame('source_not_configured', $result->status);
    }
}
