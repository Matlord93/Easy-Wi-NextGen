<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\AgentCredentialsResolver;
use App\Module\Core\Application\AgentEndpoint;
use App\Module\Core\Application\AgentEndpointResolverInterface;
use App\Module\Core\Application\Backup\BackupOperationHeartbeat;
use App\Module\Core\Application\Backup\BackupTemporaryDirectory;
use App\Module\Core\Application\Backup\HttpModuleArchiveGateway;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Gameserver\Infrastructure\Client\AgentHmacHeaderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpModuleArchiveGatewayTest extends TestCase
{
    private const string SECRET = 'authenticated-module-agent-secret';
    private const array DESCRIPTOR = ['module' => 'game', 'resource_id' => '42', 'path' => '/home/easywi/42', 'service' => 'game42.service'];
    private string $directory;
    private Agent $agent;
    private EncryptionServiceInterface $encryption;
    private BackupOperationHeartbeat $heartbeat;

    protected function setUp(): void
    {
        $this->directory = BackupTemporaryDirectory::create('easywi-backup-snapshot-');
        $this->encryption = new EncryptionService('test', 'test:'.base64_encode(str_repeat('x', 32)));
        $this->agent = new Agent('gateway-agent', $this->encryption->encrypt(self::SECRET));
        $this->heartbeat = new BackupOperationHeartbeat();
    }

    protected function tearDown(): void
    {
        BackupTemporaryDirectory::remove($this->directory, 'easywi-backup-snapshot-');
    }

    public function testSnapshotStreamsAndAuthenticatesActualBytesAndRequestBinding(): void
    {
        $payload = str_repeat('archive-stream-block', 10000);
        $pulses = 0;
        $this->heartbeat->enter(static function () use (&$pulses): void { ++$pulses; });
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($payload): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://public-agent.example.test:9746/v1/module-backups/snapshot', $url);
            self::assertSame(0, $options['max_redirects']);
            $request = $options['body'];
            self::assertIsString($request);
            $data = json_decode($request, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['descriptor' => self::DESCRIPTOR, 'customer_id' => 'customer42', 'dry_run' => false], $data);
            $headers = $this->requestHeaders($options);
            self::assertSame('identity', $headers['accept-encoding']);
            self::assertSame(hash('sha256', $request), $headers['x-content-sha256']);
            self::assertSame(hash_hmac('sha256', AgentHmacHeaderFactory::signaturePayload('gateway-agent', 'customer42', 'POST', '/v1/module-backups/snapshot', $headers['x-timestamp'], $headers['x-content-sha256']), self::SECRET), $headers['x-signature']);
            return $this->signedResponse($options, '/v1/module-backups/snapshot', $payload, str_split($payload, 4096));
        });
        $this->gateway($client)->snapshot($this->agent, 'customer42', self::DESCRIPTOR, $this->directory.'/archive.tar.gz');
        self::assertSame(hash('sha256', $payload), hash_file('sha256', $this->directory.'/archive.tar.gz'));
        self::assertSame(0600, fileperms($this->directory.'/archive.tar.gz') & 0777);
        self::assertGreaterThanOrEqual(2, $pulses);
    }

    /** @return iterable<string, array{string}> */
    public static function snapshotFailures(): iterable
    {
        foreach (['missing-signature', 'invalid-signature', 'missing-response-hash', 'truncated-body', 'wrong-archive-hash', 'wrong-content-length', 'transport-failure'] as $fault) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('snapshotFailures')]
    public function testSnapshotRejectsUnauthenticatedOrIncompleteTransfersAndLeavesNoPartialFile(string $fault): void
    {
        $payload = 'complete-archive-data';
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($payload, $fault): MockResponse {
            $body = $fault === 'truncated-body' ? ['complete-'] : [$payload];
            if ($fault === 'transport-failure') {
                $body = (static function (): \Generator {
                    yield 'incomplete';
                    yield new TransportException('connection closed during archive transfer');
                })();
            }
            $overrides = match ($fault) {
                'missing-signature' => ['X-Operation-Signature' => ''],
                'invalid-signature' => ['X-Operation-Signature' => str_repeat('0', 64)],
                'missing-response-hash' => ['X-Response-SHA256' => ''],
                'wrong-archive-hash' => ['X-Archive-SHA256' => str_repeat('0', 64)],
                'wrong-content-length' => ['Content-Length' => '999'],
                default => [],
            };
            return $this->signedResponse($options, '/v1/module-backups/snapshot', $payload, $body, 200, $overrides);
        });
        $rejected = false;
        try {
            $this->gateway($client)->snapshot($this->agent, 'customer42', self::DESCRIPTOR, $this->directory.'/archive.tar.gz');
        } catch (\RuntimeException) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'The invalid archive transfer was accepted: '.$fault);
        self::assertFileDoesNotExist($this->directory.'/archive.tar.gz');
    }

    public function testRestoreSignsActualTarEnvelopeContainingExactDescriptorAndPayload(): void
    {
        $payload = 'actual-backup-gzip-tar-payload';
        file_put_contents($this->directory.'/source.tar.gz', $payload);
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($payload): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://public-agent.example.test:9746/v1/module-backups/restore', $url);
            self::assertIsResource($options['body']);
            $body = stream_get_contents($options['body']);
            rewind($options['body']);
            file_put_contents($this->directory.'/captured-request.tar', $body);
            $envelope = new \PharData($this->directory.'/captured-request.tar');
            self::assertCount(2, $envelope);
            self::assertSame($payload, $envelope['archive.tar.gz']->getContent());
            self::assertSame(['descriptor' => self::DESCRIPTOR, 'customer_id' => 'customer42', 'dry_run' => true], json_decode($envelope['descriptor.json']->getContent(), true, flags: JSON_THROW_ON_ERROR));
            $headers = $this->requestHeaders($options);
            self::assertSame(hash('sha256', $body), $headers['x-content-sha256']);
            self::assertSame('application/x-tar', $headers['content-type']);
            self::assertSame(hash_hmac('sha256', AgentHmacHeaderFactory::signaturePayload('gateway-agent', 'customer42', 'POST', '/v1/module-backups/restore', $headers['x-timestamp'], hash('sha256', $body)), self::SECRET), $headers['x-signature']);
            return $this->signedResponse($options, '/v1/module-backups/restore', json_encode(['success' => true, 'dry_run' => true, 'message' => 'Validated by Agent.'], JSON_THROW_ON_ERROR));
        });
        $result = $this->gateway($client)->restore($this->agent, 'customer42', self::DESCRIPTOR, $this->directory.'/source.tar.gz', true);
        self::assertTrue($result->success());
        self::assertTrue($result->dryRun());
    }

    /** @return iterable<string, array{int, bool, bool, string}> */
    public static function restoreFailures(): iterable
    {
        yield 'HTTP failure cannot claim success' => [500, true, false, 'valid'];
        yield 'wrong operation cannot claim success' => [200, true, true, 'valid'];
        yield 'reported failure cannot claim success' => [200, false, false, 'valid'];
        yield 'unsigned response cannot claim success' => [200, true, false, 'missing'];
        yield 'invalid signature cannot claim success' => [200, true, false, 'invalid'];
        yield 'modified body cannot claim success' => [200, true, false, 'body-tamper'];
        yield 'oversized response cannot exhaust memory or claim success' => [200, true, false, 'oversized'];
    }

    #[DataProvider('restoreFailures')]
    public function testRestoreCannotReportSuccessWithoutAuthenticatedCompletedMatchingOperation(int $status, bool $success, bool $reportedDryRun, string $authentication): void
    {
        file_put_contents($this->directory.'/source.tar.gz', 'payload');
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($status, $success, $reportedDryRun, $authentication): MockResponse {
            $json = json_encode(['success' => $success, 'dry_run' => $reportedDryRun, 'message' => $authentication === 'oversized' ? str_repeat('x', 70000) : 'Agent response.'], JSON_THROW_ON_ERROR);
            $headers = match ($authentication) {
                'missing' => ['X-Operation-Signature' => ''],
                'invalid' => ['X-Operation-Signature' => str_repeat('f', 64)],
                default => [],
            };
            $body = $authentication === 'body-tamper' ? $json.' ' : $json;
            return $this->signedResponse($options, '/v1/module-backups/restore', $json, $body, $status, $headers);
        });
        $result = $this->gateway($client)->restore($this->agent, 'customer42', self::DESCRIPTOR, $this->directory.'/source.tar.gz', false);
        self::assertFalse($result->success());
        self::assertFalse($result->dryRun());
    }

    private function gateway(MockHttpClient $client): HttpModuleArchiveGateway
    {
        $endpoints = $this->createStub(AgentEndpointResolverInterface::class);
        $endpoints->method('resolveForAgent')->willReturn(new AgentEndpoint('https://public-agent.example.test:9746'));
        return new HttpModuleArchiveGateway($client, $endpoints, new AgentCredentialsResolver($this->encryption), $this->heartbeat);
    }

    private function requestHeaders(array $options): array
    {
        $headers = [];
        foreach ($options['headers'] as $line) {
            [$key, $value] = explode(':', $line, 2);
            $headers[strtolower($key)] = trim($value);
        }
        return $headers;
    }

    private function signedResponse(array $options, string $uri, string $authenticatedBody, string|iterable|null $body = null, int $status = 200, array $overrides = []): MockResponse
    {
        $request = $this->requestHeaders($options);
        $hash = hash('sha256', $authenticatedBody);
        $signature = hash_hmac('sha256', implode("\n", [$request['x-agent-id'], $request['x-customer-id'], 'POST', $uri, $request['x-timestamp'], $request['x-content-sha256'], (string) $status, $hash]), self::SECRET);
        $headers = array_replace(['X-Archive-SHA256' => $hash, 'X-Response-SHA256' => $hash, 'X-Operation-Signature' => $signature, 'Content-Length' => (string) strlen($authenticatedBody)], $overrides);
        return new MockResponse($body ?? $authenticatedBody, ['http_code' => $status, 'response_headers' => $headers]);
    }
}
