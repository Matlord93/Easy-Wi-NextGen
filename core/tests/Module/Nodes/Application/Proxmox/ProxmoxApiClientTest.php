<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Application\Proxmox;

use App\Module\Nodes\Application\Proxmox\ProxmoxApiClient;
use App\Module\Nodes\Domain\Proxmox\ProxmoxApiException;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProxmoxApiClientTest extends TestCase
{
    public function testListsQemuAndLxcGuestsWithTokenAuthentication(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return str_contains($url, '/qemu')
                ? new MockResponse('{"data":[{"vmid":100,"name":"web"}]}')
                : new MockResponse('{"data":[{"vmid":101,"name":"cache"}]}');
        });

        $guests = $this->client($http)->virtualMachines($this->connection(), 'pve node/1');

        self::assertSame([100, 101], array_column($guests, 'vmid'));
        self::assertStringContainsString('/nodes/pve%20node%2F1/qemu', $requests[0][1]);
        self::assertSame('PVEAPIToken=panel@pve!easywi=secret', $requests[0][2]['normalized_headers']['authorization'][0]);
    }

    public function testStartsGuestAndReturnsTaskId(): void
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringEndsWith('/nodes/pve1/qemu/123/status/start', $url);

            return new MockResponse('{"data":"UPID:pve1:task"}');
        });

        self::assertSame(
            ['task' => 'UPID:pve1:task'],
            $this->client($http)->changePowerState($this->connection(), 'pve1', 123, 'start'),
        );
    }

    public function testCreatesValidatedSnapshot(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringEndsWith('/nodes/pve1/lxc/200/snapshot', $url);
            self::assertSame('release-1', $options['body']['snapname']);
            self::assertSame('Before update', $options['body']['description']);

            return new MockResponse('{"data":"UPID:snapshot"}');
        });

        $result = $this->client($http)->createSnapshot(
            $this->connection(),
            'pve1',
            200,
            'release-1',
            ' Before update ',
            'lxc',
        );

        self::assertSame(['task' => 'UPID:snapshot'], $result);
    }

    public function testRejectsUnsupportedPowerActionBeforeRequest(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('HTTP must not be called.'));

        $this->expectException(\InvalidArgumentException::class);
        $this->client($http)->changePowerState($this->connection(), 'pve1', 123, 'destroy');
    }

    public function testExposesApiFailureWithoutLeakingCredentials(): void
    {
        $http = new MockHttpClient(new MockResponse('{"message":"permission denied","data":null}', ['http_code' => 403]));

        try {
            $this->client($http)->nodes($this->connection());
            self::fail('Expected API exception.');
        } catch (ProxmoxApiException $exception) {
            self::assertSame(403, $exception->statusCode);
            self::assertSame('/nodes', $exception->requestPath);
            self::assertSame('permission denied', $exception->getMessage());
            self::assertStringNotContainsString('secret', (string) $exception);
        }
    }

    public function testConnectionRequiresHttpsAndTokenFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProxmoxConnection('http://pve.example.test:8006', 'panel@pve', 'secret');
    }

    private function client(MockHttpClient $http): ProxmoxApiClient
    {
        return new ProxmoxApiClient($http, new NullLogger());
    }

    private function connection(): ProxmoxConnection
    {
        return new ProxmoxConnection('https://pve.example.test:8006', 'panel@pve!easywi', 'secret');
    }
}
