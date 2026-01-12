<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PublicServer;
use App\Entity\User;
use App\Enum\UserType;
use PHPUnit\Framework\TestCase;

final class PublicServerTest extends TestCase
{
    public function testPublicServerTracksFields(): void
    {
        $user = new User('admin@example.test', UserType::Admin);
        $lastCheckedAt = new \DateTimeImmutable('2024-01-02 03:04:05');
        $nextCheckAt = new \DateTimeImmutable('2024-01-02 03:05:05');

        $server = new PublicServer(
            siteId: 42,
            name: 'EU-1',
            category: 'hosting',
            gameKey: 'valheim',
            ip: '203.0.113.10',
            port: 2456,
            queryType: 'steam_a2s',
            checkIntervalSeconds: 60,
            createdBy: $user,
            queryPort: 2457,
            visiblePublic: true,
            visibleLoggedIn: false,
            sortOrder: 10,
            notesInternal: 'VIP server',
            statusCache: ['online' => true],
            lastCheckedAt: $lastCheckedAt,
            nextCheckAt: $nextCheckAt,
        );

        self::assertSame(42, $server->getSiteId());
        self::assertSame('EU-1', $server->getName());
        self::assertSame('hosting', $server->getCategory());
        self::assertSame('valheim', $server->getGameKey());
        self::assertSame('203.0.113.10', $server->getIp());
        self::assertSame(2456, $server->getPort());
        self::assertSame('steam_a2s', $server->getQueryType());
        self::assertSame(2457, $server->getQueryPort());
        self::assertTrue($server->isVisiblePublic());
        self::assertFalse($server->isVisibleLoggedIn());
        self::assertSame(10, $server->getSortOrder());
        self::assertSame('VIP server', $server->getNotesInternal());
        self::assertSame(['online' => true], $server->getStatusCache());
        self::assertSame($lastCheckedAt, $server->getLastCheckedAt());
        self::assertSame($nextCheckAt, $server->getNextCheckAt());
        self::assertSame(60, $server->getCheckIntervalSeconds());
        self::assertSame($user, $server->getCreatedBy());

        $server->setName('EU-2');
        $server->setVisibleLoggedIn(true);

        self::assertSame('EU-2', $server->getName());
        self::assertTrue($server->isVisibleLoggedIn());
    }
}
