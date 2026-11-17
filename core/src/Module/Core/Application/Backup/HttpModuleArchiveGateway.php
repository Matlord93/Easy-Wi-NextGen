<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Application\AgentCredentialsResolver;
use App\Module\Core\Application\AgentEndpointResolverInterface;
use App\Module\Core\Application\Backup\Adapter\RestoreReport;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Gameserver\Infrastructure\Client\AgentHmacHeaderFactory;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AsAlias(id: ModuleArchiveGatewayInterface::class)]
final readonly class HttpModuleArchiveGateway implements ModuleArchiveGatewayInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private AgentEndpointResolverInterface $endpoints,
        private AgentCredentialsResolver $credentials,
        private BackupOperationHeartbeat $heartbeat,
    ) {
    }

    #[\Override]
    public function snapshot(Agent $agent, string $customerId, array $descriptor, string $destinationArchive): void
    {
        $body = $this->descriptorJson($descriptor, $customerId, false);
        $uri = '/v1/module-backups/snapshot';
        $options = $this->options($agent, $customerId, $uri, hash('sha256', $body));
        $options['headers']['Content-Type'] = 'application/json';
        $options['body'] = $body;
        $this->heartbeat->pulse(true);
        $response = $this->http->request('POST', $this->url($agent, $uri), $options);
        if ($response->getStatusCode() !== 200) {
            $status = $response->getStatusCode();
            $response->cancel();
            throw new \RuntimeException('Agent snapshot failed (HTTP '.$status.'); no archive was recorded.');
        }
        $headers = $response->getHeaders(false);
        $expected = $headers['x-archive-sha256'][0] ?? '';
        if (preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
            $response->cancel();
            throw new \RuntimeException('Agent snapshot did not supply a valid archive checksum.');
        }
        try {
            $this->verifyResponse($agent, $uri, $options['headers'], $headers, $expected);
        } catch (\Throwable $error) {
            $response->cancel();
            throw $error;
        }
        if (str_contains($destinationArchive, "\0") || str_contains($destinationArchive, '://') || !is_dir(dirname($destinationArchive))) {
            $response->cancel();
            throw new \InvalidArgumentException('Archive destination must be a new file inside local staging.');
        }
        $stream = @fopen($destinationArchive, 'x+b');
        if ($stream === false) {
            $response->cancel();
            throw new \RuntimeException('Unable to create private local archive staging file.');
        }
        $complete = false;
        try {
            if (!chmod($destinationArchive, 0600)) {
                throw new \RuntimeException('Unable to restrict archive staging permissions.');
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            foreach ($this->http->stream($response) as $chunk) {
                $this->heartbeat->pulse();
                if ($chunk->isTimeout()) {
                    throw new \RuntimeException('Agent snapshot transfer timed out.');
                }
                $content = $chunk->getContent();
                hash_update($hash, $content);
                $length = strlen($content);
                $bytes += $length;
                $offset = 0;
                while ($offset < $length) {
                    $written = fwrite($stream, substr($content, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('Unable to persist agent snapshot chunk.');
                    }
                    $offset += $written;
                }
            }
            $lengthHeader = $headers['content-length'][0] ?? null;
            if ($bytes === 0 || !hash_equals($expected, hash_final($hash))
                || ($lengthHeader !== null && (string) $bytes !== $lengthHeader) || !fflush($stream)) {
                throw new \RuntimeException('Agent snapshot was truncated or failed checksum verification.');
            }
            $this->heartbeat->pulse(true);
            $complete = true;
        } finally {
            fclose($stream);
            if (!$complete) {
                $response->cancel();
                @unlink($destinationArchive);
            }
        }
    }

    #[\Override]
    public function restore(Agent $agent, string $customerId, array $descriptor, string $archivePath, bool $dryRun): RestoreReport
    {
        $workspace = null;
        try {
            if (str_contains($archivePath, "\0") || str_contains($archivePath, '://') || !is_file($archivePath) || !is_readable($archivePath)) {
                throw new \InvalidArgumentException('Restore payload must be a readable local archive.');
            }
            $json = $this->descriptorJson($descriptor, $customerId, $dryRun);
            $workspace = BackupTemporaryDirectory::create();
            $description = $workspace.'/descriptor.json';
            if (file_put_contents($description, $json) !== strlen($json) || !chmod($description, 0600)) {
                throw new \RuntimeException('Unable to stage restore descriptor.');
            }
            $envelopePath = $workspace.'/request.tar';
            (new BackupTarWriter($this->heartbeat))->writeFiles(['descriptor.json' => $description, 'archive.tar.gz' => $archivePath], $envelopePath);
            $hash = BackupFileChecksum::sha256($envelopePath, $this->heartbeat);
            $body = fopen($envelopePath, 'rb');
            if ($body === false) {
                throw new \RuntimeException('Unable to prepare restore upload.');
            }
            try {
                $uri = '/v1/module-backups/restore';
                $options = $this->options($agent, $customerId, $uri, $hash);
                $options['headers']['Content-Type'] = 'application/x-tar';
                $options['body'] = $body;
                $this->heartbeat->pulse(true);
                $response = $this->http->request('POST', $this->url($agent, $uri), $options);
                $status = $response->getStatusCode();
                if ($status === 200) {
                    $headers = $response->getHeaders(false);
                    $claimed = $headers['x-response-sha256'][0] ?? '';
                    if (preg_match('/^[a-f0-9]{64}$/D', $claimed) !== 1) {
                        $response->cancel();
                        throw new \RuntimeException('Agent restore response is missing its checksum.');
                    }
                    try {
                        $this->verifyResponse($agent, $uri, $options['headers'], $headers, $claimed);
                    } catch (\Throwable $error) {
                        $response->cancel();
                        throw $error;
                    }
                }
                $content = $this->readRestoreResponse($response);
                if ($status === 200) {
                    $this->verifyResponse($agent, $uri, $options['headers'], $response->getHeaders(false), hash('sha256', $content));
                }
                $data = JsonMap::decode($content);
                $this->heartbeat->pulse(true);
                $messages = JsonMap::strings($data);
                $agentMessage = $messages['message'] ?? null;
                if ($status !== 200 || !is_array($data) || ($data['success'] ?? null) !== true || ($data['dry_run'] ?? null) !== $dryRun) {
                    $message = is_string($agentMessage) ? $agentMessage : 'Agent restore failed or did not confirm the requested operation.';

                    return new RestoreReport($dryRun, false, $message);
                }

                return new RestoreReport($dryRun, true, is_string($agentMessage) ? $agentMessage : 'Agent restore completed.');
            } finally {
                fclose($body);
            }
        } catch (\Throwable $error) {
            return new RestoreReport($dryRun, false, 'Agent restore failed: '.$error->getMessage());
        } finally {
            if ($workspace !== null) {
                BackupTemporaryDirectory::remove($workspace, 'easywi-backup-work-');
            }
        }
    }

    /** @param array<string, scalar|array|null> $descriptor */
    private function descriptorJson(array $descriptor, string $customerId, bool $dryRun): string
    {
        if ($customerId === '' || strpbrk($customerId, "\r\n\0") !== false || !is_string($descriptor['resource_id'] ?? null) || $descriptor['resource_id'] === ''
            || !in_array($descriptor['module'] ?? null, ['game', 'mail', 'voice', 'db', 'musicbot'], true)) {
            throw new \InvalidArgumentException('Module backup requires an explicit customer, resource and supported module.');
        }
        $json = json_encode(['descriptor' => $descriptor, 'customer_id' => $customerId, 'dry_run' => $dryRun], JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) {
            throw new \InvalidArgumentException('Module backup descriptor exceeds 64 KiB.');
        }

        return $json;
    }

    private function url(Agent $agent, string $uri): string
    {
        return rtrim($this->endpoints->resolveForAgent($agent)->getBaseUrl(), '/').$uri;
    }

    private function readRestoreResponse(ResponseInterface $response): string
    {
        $content = '';
        try {
            foreach ($this->http->stream($response) as $chunk) {
                $this->heartbeat->pulse();
                if ($chunk->isTimeout()) {
                    throw new \RuntimeException('Agent restore response timed out.');
                }
                $data = $chunk->getContent();
                if (strlen($content) + strlen($data) > 65536) {
                    throw new \RuntimeException('Agent restore response exceeds 64 KiB.');
                }
                $content .= $data;
            }

            return $content;
        } catch (\Throwable $error) {
            $response->cancel();
            throw $error;
        }
    }

    /** @return array{headers:array<string,string>,timeout:int,max_duration:int,max_redirects:int,on_progress:callable} */
    private function options(Agent $agent, string $customerId, string $uri, string $hash): array
    {
        $credentials = $this->credentials->resolveForAgent($agent);
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeImmutable::RFC3339_EXTENDED);
        $signature = hash_hmac('sha256', AgentHmacHeaderFactory::signaturePayload($credentials->getAgentId(), $customerId, 'POST', $uri, $timestamp, $hash), $credentials->getSecret());
        $configured = filter_var(getenv('EASYWI_MODULE_BACKUP_TIMEOUT_SECONDS'), FILTER_VALIDATE_INT);
        $timeout = is_int($configured) && $configured >= 60 ? $configured : 7200;

        return [
            'headers' => ['X-Agent-ID' => $credentials->getAgentId(), 'X-Customer-ID' => $customerId, 'X-Timestamp' => $timestamp, 'X-Signature' => $signature, 'X-Content-SHA256' => $hash, 'Accept-Encoding' => 'identity'],
            'timeout' => $timeout, 'max_duration' => $timeout, 'max_redirects' => 0,
            'on_progress' => function (): void { $this->heartbeat->pulse(); },
        ];
    }

    /**
     * Bind a successful response to this exact signed request, including on HTTP installations.
     * @param array<string, string> $requestHeaders
     * @param array<string, list<string>> $responseHeaders
     */
    private function verifyResponse(Agent $agent, string $uri, array $requestHeaders, array $responseHeaders, string $bodyHash): void
    {
        $checksum = $responseHeaders['x-response-sha256'][0] ?? '';
        $signature = $responseHeaders['x-operation-signature'][0] ?? '';
        $canonical = implode("\n", [
            $requestHeaders['X-Agent-ID'], $requestHeaders['X-Customer-ID'], 'POST', $uri,
            $requestHeaders['X-Timestamp'], $requestHeaders['X-Content-SHA256'], '200', $bodyHash,
        ]);
        $expected = hash_hmac('sha256', $canonical, $this->credentials->resolveForAgent($agent)->getSecret());
        if (!hash_equals($bodyHash, $checksum) || !hash_equals($expected, $signature)) {
            throw new \RuntimeException('Agent response failed request-bound signature verification.');
        }
    }
}
