<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FileServiceClient
{
    private const DEFAULT_EDITOR_LIMIT = 2_097_152;
    private const HEADER_AGENT_ID = 'X-Agent-ID';
    private const HEADER_CUSTOMER_ID = 'X-Customer-ID';
    private const HEADER_TIMESTAMP = 'X-Timestamp';
    private const HEADER_SIGNATURE = 'X-Signature';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EncryptionService $encryptionService,
        private readonly int $timeoutSeconds,
        private readonly int $defaultPort,
        private readonly string $defaultScheme,
        private readonly string $clientCertPath,
        private readonly string $clientKeyPath,
        private readonly string $clientCaPath,
    ) {
    }

    /**
     * @return array{root_path: string, path: string, entries: array<int, array{name: string, size: int, mode: string, modified_at: string, is_dir: bool}>}
     */
    public function list(Instance $instance, string $path): array
    {
        $normalizedPath = $this->normalizeRelativePath($path);
        $endpoint = $this->buildEndpoint($instance, '/files');

        $payload = $this->requestJson($instance, 'GET', $endpoint, [
            'path' => $normalizedPath,
        ]);

        return [
            'root_path' => (string) ($payload['root_path'] ?? ''),
            'path' => (string) ($payload['path'] ?? ''),
            'entries' => is_array($payload['entries'] ?? null) ? $payload['entries'] : [],
        ];
    }

    public function readFile(Instance $instance, string $path, string $name, ?int $maxBytes = null): string
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($instance, '/read');

        $response = $this->requestRaw($instance, 'GET', $endpoint, [
            'path' => $relative,
        ]);

        $maxBytes = $maxBytes ?? 0;
        if ($maxBytes > 0) {
            $length = $this->parseContentLength($response->getHeaders(false));
            if ($length !== null && $length > $maxBytes) {
                throw new \RuntimeException('File too large for editor.');
            }
        }

        $content = $response->getContent(false);
        if ($maxBytes > 0 && strlen($content) > $maxBytes) {
            throw new \RuntimeException('File too large for editor.');
        }

        return $content;
    }

    public function readFileForEditor(Instance $instance, string $path, string $name): string
    {
        return $this->readFile($instance, $path, $name, self::DEFAULT_EDITOR_LIMIT);
    }

    public function writeFile(Instance $instance, string $path, string $name, string $content): void
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($instance, '/write');

        $this->requestJson($instance, 'POST', $endpoint, [], [
            'path' => $relative,
            'content' => $content,
        ]);
    }

    public function uploadFile(Instance $instance, string $path, UploadedFile $upload): void
    {
        $endpoint = $this->buildEndpoint($instance, '/upload');
        $relativePath = $this->normalizeRelativePath($path);
        $formData = new FormDataPart([
            'path' => $relativePath,
            'upload' => DataPart::fromPath($upload->getPathname(), $upload->getClientOriginalName()),
        ]);

        $this->requestMultipart($instance, $endpoint, $formData);
    }

    public function makeDirectory(Instance $instance, string $path, string $name): void
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($instance, '/mkdir');

        $this->requestJson($instance, 'POST', $endpoint, [], [
            'path' => $relative,
        ]);
    }

    public function delete(Instance $instance, string $path, string $name): void
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($instance, '/delete');

        $this->requestJson($instance, 'POST', $endpoint, [], [
            'path' => $relative,
        ]);
    }

    public function rename(Instance $instance, string $path, string $name, string $newName): void
    {
        $this->assertValidName($name);
        $this->assertValidName($newName);
        $relative = $this->combinePath($path, $name);
        $newRelative = $this->combinePath($path, $newName);
        $endpoint = $this->buildEndpoint($instance, '/rename');

        $this->requestJson($instance, 'POST', $endpoint, [], [
            'path' => $relative,
            'new_path' => $newRelative,
        ]);
    }

    public function chmod(Instance $instance, string $path, string $name, int $mode): void
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($instance, '/chmod');

        $this->requestJson($instance, 'POST', $endpoint, [], [
            'path' => $relative,
            'mode' => $mode,
        ]);
    }

    public function extract(Instance $instance, string $path, string $name, string $destination): void
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($instance, '/extract');

        $this->requestJson($instance, 'POST', $endpoint, [], [
            'path' => $relative,
            'destination' => $this->normalizeRelativePath($destination),
        ]);
    }

    public function downloadFile(Instance $instance, string $path, string $name): string
    {
        return $this->readFile($instance, $path, $name, null);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     */
    private function requestJson(Instance $instance, string $method, string $endpoint, array $query = [], ?array $json = null): array
    {
        $response = $this->requestRaw($instance, $method, $endpoint, $query, $json);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $payload = $response->toArray(false);
            $message = is_array($payload) ? (string) ($payload['error'] ?? 'File service error.') : 'File service error.';
            throw new \RuntimeException($message);
        }

        $payload = $response->toArray(false);
        return is_array($payload) ? $payload : [];
    }

    private function requestMultipart(Instance $instance, string $endpoint, FormDataPart $formData): void
    {
        $config = $this->buildRequestOptions($instance, 'POST', $endpoint, []);
        $options = $config['options'];
        $options['headers'] = array_merge($options['headers'], $formData->getPreparedHeaders()->toArray());
        $options['body'] = $formData->bodyToIterable();

        $response = $this->httpClient->request('POST', $this->resolveBaseUrl($instance->getNode()) . $config['endpoint'], $options);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $payload = $response->toArray(false);
            $message = is_array($payload) ? (string) ($payload['error'] ?? 'File service error.') : 'File service error.';
            throw new \RuntimeException($message);
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     */
    private function requestRaw(Instance $instance, string $method, string $endpoint, array $query = [], ?array $json = null): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $options = $this->buildRequestOptions($instance, $method, $endpoint, $query, $json);

        try {
            return $this->httpClient->request($method, $this->resolveBaseUrl($instance->getNode()) . $options['endpoint'], $options['options']);
        } catch (TransportExceptionInterface $exception) {
            throw new \RuntimeException('File service unavailable.', 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     *
     * @return array{endpoint: string, options: array<string, mixed>}
     */
    private function buildRequestOptions(Instance $instance, string $method, string $endpoint, array $query = [], ?array $json = null): array
    {
        $endpointWithQuery = $endpoint;
        if ($query !== []) {
            $queryString = http_build_query($query);
            $endpointWithQuery = $queryString !== '' ? $endpoint . '?' . $queryString : $endpoint;
        }

        $headers = $this->buildAuthHeaders($instance, $method, $endpointWithQuery);
        $headers['Accept'] = 'application/json';

        $options = [
            'headers' => $headers,
            'timeout' => $this->timeoutSeconds,
        ];

        if ($json !== null) {
            $options['json'] = $json;
        }

        $tlsOptions = $this->buildTlsOptions();
        if ($tlsOptions !== []) {
            $options = array_merge($options, $tlsOptions);
        }

        return [
            'endpoint' => $endpointWithQuery,
            'options' => $options,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildAuthHeaders(Instance $instance, string $method, string $endpointWithQuery): array
    {
        $agent = $instance->getNode();
        $agentId = $agent->getId();
        $customerId = (string) $instance->getCustomer()->getId();
        $secret = $this->encryptionService->decrypt($agent->getSecretPayload());
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeImmutable::RFC3339);

        $payload = sprintf(
            "%s\n%s\n%s\n%s\n%s",
            $agentId,
            $customerId,
            strtoupper($method),
            $endpointWithQuery,
            $timestamp,
        );
        $signature = hash_hmac('sha256', $payload, $secret);

        return [
            self::HEADER_AGENT_ID => $agentId,
            self::HEADER_CUSTOMER_ID => $customerId,
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_SIGNATURE => $signature,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTlsOptions(): array
    {
        $options = [];
        if ($this->clientCertPath !== '') {
            $options['local_cert'] = $this->clientCertPath;
        }
        if ($this->clientKeyPath !== '') {
            $options['local_pk'] = $this->clientKeyPath;
        }
        if ($this->clientCaPath !== '') {
            $options['cafile'] = $this->clientCaPath;
        }

        return $options;
    }

    private function resolveBaseUrl(Agent $node): string
    {
        $metadata = $node->getMetadata();
        $metadata = is_array($metadata) ? $metadata : [];

        $url = $metadata['filesvc_url'] ?? null;
        if (is_string($url) && $url !== '') {
            return rtrim($url, '/');
        }

        $host = $metadata['filesvc_host'] ?? null;
        if (!is_string($host) || $host === '') {
            $host = $node->getLastHeartbeatIp();
        }
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('File service host not configured.');
        }

        $port = $metadata['filesvc_port'] ?? null;
        $port = is_numeric($port) ? (int) $port : $this->defaultPort;
        $scheme = $metadata['filesvc_scheme'] ?? null;
        $scheme = is_string($scheme) && $scheme !== '' ? $scheme : $this->defaultScheme;

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }

    private function buildEndpoint(Instance $instance, string $suffix): string
    {
        return sprintf('/v1/servers/%s%s', $instance->getId(), $suffix);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        $safe = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($safe);
                continue;
            }
            $safe[] = $segment;
        }

        return implode('/', $safe);
    }

    private function combinePath(string $path, string $name): string
    {
        $normalizedPath = $this->normalizeRelativePath($path);

        return $normalizedPath === '' ? $name : $normalizedPath . '/' . $name;
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    private function parseContentLength(array $headers): ?int
    {
        $lengthValues = $headers['content-length'] ?? null;
        if ($lengthValues === null || $lengthValues === []) {
            return null;
        }
        $length = $lengthValues[0] ?? null;
        if (!is_string($length) || !is_numeric($length)) {
            return null;
        }
        return (int) $length;
    }

    private function assertValidName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \RuntimeException('Invalid name.');
        }
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new \RuntimeException('Invalid name.');
        }
    }
}
