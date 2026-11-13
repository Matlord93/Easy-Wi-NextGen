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
    public function testDiskExpansionSendsAbsoluteSizeWithServerDigest(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('PUT', $method);
            self::assertStringEndsWith('/nodes/pve1/qemu/101/resize', $url);
            parse_str($options['body'], $body);
            self::assertSame(['disk' => 'scsi0', 'size' => '40G', 'digest' => 'server-digest'], $body);
            return new MockResponse('{"data":"UPID:pve1:resize"}');
        });
        self::assertSame(['task' => 'UPID:pve1:resize'], $this->client($http)->growDisk($this->connection(), 'pve1', 101, 'qemu', 'scsi0', 40, 'server-digest'));
    }

    public function testTlsPreferenceAppliesPerRequestWithoutChangingOtherConnections(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [$options['verify_peer'], $options['verify_host']];

            return new MockResponse('{"data":[]}');
        });
        $client = $this->client($http);
        $client->nodes($this->connection());
        $client->nodes(new ProxmoxConnection('https://pve.example.test:8006', 'panel@pve!test', 'secret', false));
        $client->nodes($this->connection());

        self::assertSame([[true, true], [false, false], [true, true]], $seen);
    }

    public function testTransportDiagnosticsClassifyTlsWithoutLeakingCredentials(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('SSL certificate problem for secret-token-value');
        });
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Proxmox API transport failure.', self::callback(static fn (array $context): bool => $context['reason'] === 'tls' && !str_contains(json_encode($context), 'secret-token-value')));
        try { (new ProxmoxApiClient($http, $logger))->clusterResources($this->connection()); self::fail('Expected transport error'); }
        catch (ProxmoxApiException $error) { self::assertStringContainsString('TLS verification failed', $error->getMessage()); self::assertStringNotContainsString('secret-token-value', $error->getMessage()); }
    }

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
        self::assertSame('Authorization: PVEAPIToken=panel@pve!easywi=secret', $requests[0][2]['normalized_headers']['authorization'][0]);
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
            self::assertIsString($options['body']);
            parse_str($options['body'], $body);
            self::assertSame('release-1', $body['snapname']);
            self::assertSame('Before update', $body['description']);

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

    public function testTokenCannotFollowRedirectsAndTlsAndTimeoutsAreEnforced(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertTrue($options['verify_peer']);
            self::assertTrue($options['verify_host']);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(20.0, $options['max_duration']);

            return new MockResponse('{"data":[]}');
        });
        $this->client($http)->nodes($this->connection());
    }

    public function testConnectionsForDifferentClustersDoNotShareTokens(): void
    {
        $headers = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$headers): MockResponse {
            $headers[$url] = $options['normalized_headers']['authorization'][0];

            return new MockResponse('{"data":[]}');
        });
        $client = $this->client($http);
        $client->nodes($this->connection());
        $client->nodes(new ProxmoxConnection('https://second.example.test:8006', 'other@pve!panel', 'another-synthetic-token'));
        self::assertSame('Authorization: PVEAPIToken=panel@pve!easywi=secret', $headers['https://pve.example.test:8006/api2/json/nodes']);
        self::assertSame('Authorization: PVEAPIToken=other@pve!panel=another-synthetic-token', $headers['https://second.example.test:8006/api2/json/nodes']);
    }

    public function testMalformedListIsAControlledApiFailure(): void
    {
        $this->expectException(ProxmoxApiException::class);
        $this->client(new MockHttpClient(new MockResponse('{"data":true}')))->nodes($this->connection());
    }

    public function testInvalidJsonDoesNotExposeUpstreamContentOrToken(): void
    {
        try {
            $this->client(new MockHttpClient(new MockResponse('<html>private-upstream-error</html>', ['http_code' => 500])))->nodes($this->connection());
            self::fail('Malformed response accepted.');
        } catch (ProxmoxApiException $error) {
            self::assertStringNotContainsString('private-upstream-error', (string) $error);
            self::assertStringNotContainsString('PVEAPIToken', (string) $error);
        }
    }

    public static function unsafeConnections(): array
    {
        return [
            ['https://user:password@pve.example.test:8006', 'panel@pve!test', 'secret'],
            ['https://pve.example.test/api2/json', 'panel@pve!test', 'secret'],
            ['https://pve.example.test/?query=1', 'panel@pve!test', 'secret'],
            ['https://pve.example.test/#fragment', 'panel@pve!test', 'secret'],
            ['https://pve.example.test', "panel@pve!test\r\nX-Other: value", 'secret'],
            ['https://pve.example.test', 'panel@pve!test', "secret\r\nX-Other: value"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeConnections')]
    public function testUnsafeOriginsOrHeadersAreRejected(string $url, string $token, string $secret): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProxmoxConnection($url, $token, $secret);
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
