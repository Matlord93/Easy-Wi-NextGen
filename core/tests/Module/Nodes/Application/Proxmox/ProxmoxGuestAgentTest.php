<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Application\Proxmox;

use App\Module\Nodes\Application\Proxmox\ProxmoxApiClient;
use App\Module\Nodes\Application\Proxmox\ProxmoxGuestAgent;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProxmoxGuestAgentTest extends TestCase
{
    public function testFilesystemTimeoutBacksOffThenRecoversWithTheNormalSuccessLifetime(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            if (++$calls === 1) {
                throw new TimeoutException('Synthetic guest agent timeout.');
            }

            return new MockResponse('{"data":{"result":[{"name":"sda1","mountpoint":"/","type":"ext4","total-bytes":1000,"used-bytes":500}]}}');
        });
        $clock = new MockClock();
        $reader = new ProxmoxGuestAgent(new ProxmoxApiClient($http, new NullLogger()), new ArrayAdapter(clock: $clock));
        $connection = new ProxmoxConnection('https://pve.example.test:8006', 'panel@pve!test', 'synthetic');

        $failure = $reader->filesystemUsage($connection, 'pve-a', 202, 'digest');
        self::assertSame('unavailable', $failure['state']);
        $clock->sleep(61);
        self::assertSame($failure, $reader->filesystemUsage($connection, 'pve-a', 202, 'digest'));
        self::assertSame(1, $calls);
        $clock->sleep(238);
        self::assertSame($failure, $reader->filesystemUsage($connection, 'pve-a', 202, 'digest'));
        self::assertSame(1, $calls);

        $clock->sleep(1);
        self::assertSame('available', $reader->filesystemUsage($connection, 'pve-a', 202, 'digest')['state']);
        self::assertSame(2, $calls);
        $clock->sleep(29);
        self::assertSame('available', $reader->filesystemUsage($connection, 'pve-a', 202, 'digest')['state']);
        self::assertSame(2, $calls);
        $clock->sleep(1);
        $reader->filesystemUsage($connection, 'pve-a', 202, 'digest');
        self::assertSame(3, $calls);
    }

    public function testFailureBackoffIsScopedToGuestConfigurationAndCredentials(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): never {
            ++$calls;
            throw new TimeoutException('Synthetic guest agent timeout.');
        });
        $reader = new ProxmoxGuestAgent(new ProxmoxApiClient($http, new NullLogger()), new ArrayAdapter());
        $connection = new ProxmoxConnection('https://pve.example.test:8006', 'panel@pve!test', 'synthetic');

        $reader->filesystemUsage($connection, 'pve-a', 202, 'digest');
        $reader->filesystemUsage($connection, 'pve-a', 202, 'digest');
        self::assertSame(1, $calls);
        $reader->filesystemUsage($connection, 'pve-a', 203, 'digest');
        self::assertSame(2, $calls);
        $reader->filesystemUsage($connection, 'pve-a', 202, 'changed-digest');
        self::assertSame(3, $calls);
        $reader->filesystemUsage(new ProxmoxConnection($connection->baseUrl, $connection->tokenId, 'rotated'), 'pve-a', 202, 'digest');
        self::assertSame(4, $calls);
    }
}
