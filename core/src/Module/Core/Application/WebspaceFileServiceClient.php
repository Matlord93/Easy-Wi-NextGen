<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Application\Exception\FileServiceException;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Webspace;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebspaceFileServiceClient
{
    private const DEFAULT_EDITOR_LIMIT = 1_048_576;
    private const HEADER_AGENT_ID = 'X-Agent-ID';
    private const HEADER_CUSTOMER_ID = 'X-Customer-ID';
    private const HEADER_TIMESTAMP = 'X-Timestamp';
    private const HEADER_SIGNATURE = 'X-Signature';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EncryptionService $encryptionService,
        private readonly int $timeoutSeconds,
        private readonly int $defaultServicePort,
        private readonly string $defaultServiceScheme,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return array{root_path: string, path: string, entries: array<int, array{name: string, size: int, mode: string, modified_at: string, is_dir: bool}>}
     */
    public function list(Webspace $webspace, string $path): array
    {
        $normalizedPath = $this->normalizeRelativePath($path);
        $endpoint = $this->buildEndpoint($webspace, '/files');

        $payload = $this->requestJson($webspace, 'GET', $endpoint, [
            'path' => $normalizedPath,
        ]);

        return [
            'root_path' => (string) ($payload['root_path'] ?? ''),
            'path' => (string) ($payload['path'] ?? ''),
            'entries' => is_array($payload['entries'] ?? null) ? $payload['entries'] : [],
        ];
    }

    public function readFile(Webspace $webspace, string $path, string $name, ?int $maxBytes = null): string
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($webspace, '/read');

        $response = $this->requestRaw($webspace, 'GET', $endpoint, [
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

    public function readFileForEditor(Webspace $webspace, string $path, string $name): string
    {
        return $this->readFile($webspace, $path, $name, self::DEFAULT_EDITOR_LIMIT);
    }

    public function writeFile(Webspace $webspace, string $path, string $name, string $content): void
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($webspace, '/write');

        $this->requestJson($webspace, 'POST', $endpoint, [], [
            'path' => $relative,
            'content' => $content,
        ]);
    }

    public function uploadFile(Webspace $webspace, string $path, UploadedFile $upload): void
    {
        $endpoint = $this->buildEndpoint($webspace, '/upload');
        $relativePath = $this->normalizeRelativePath($path);
        $formData = new FormDataPart([
            'path' => $relativePath,
            'upload' => DataPart::fromPath($upload->getPathname(), $upload->getClientOriginalName()),
        ]);

        $this->requestMultipart($webspace, $endpoint, $formData);
    }

    public function makeDirectory(Webspace $webspace, string $path, string $name): void
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($webspace, '/mkdir');

        $this->requestJson($webspace, 'POST', $endpoint, [], [
            'path' => $relative,
        ]);
    }

    public function delete(Webspace $webspace, string $path, string $name): void
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($webspace, '/delete');

        $this->requestJson($webspace, 'POST', $endpoint, [], [
            'path' => $relative,
        ]);
    }

    public function rename(Webspace $webspace, string $path, string $name, string $newName): void
    {
        $this->assertValidName($name);
        $this->assertValidName($newName);
        $relative = $this->combinePath($path, $name);
        $newRelative = $this->combinePath($path, $newName);
        $endpoint = $this->buildEndpoint($webspace, '/rename');

        $this->requestJson($webspace, 'POST', $endpoint, [], [
            'path' => $relative,
            'new_path' => $newRelative,
        ]);
    }

    public function downloadFile(Webspace $webspace, string $path, string $name): string
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($webspace, '/download');

        $response = $this->requestRaw($webspace, 'GET', $endpoint, [
            'path' => $relative,
        ]);

        return $response->getContent(false);
    }

    /**
     * @return array{ok: bool, status_code: int|null, error: string|null, url: string, body: mixed}
     */
    public function ping(Webspace $webspace): array
    {
        $baseUrl = $this->resolveBaseUrl($webspace->getNode());
        $healthUrl = $baseUrl . '/health';

        $options = [
            'timeout' => min(5, $this->timeoutSeconds),
            'max_duration' => min(5, $this->timeoutSeconds),
        ];

        try {
            $response = $this->httpClient->request('GET', $healthUrl, $options);
            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
            $decoded = json_decode($body, true);
        } catch (TimeoutExceptionInterface $exception) {
            return [
                'ok' => false,
                'status_code' => null,
                'error' => 'timeout',
                'url' => $healthUrl,
                'body' => null,
            ];
        } catch (TransportExceptionInterface $exception) {
            return [
                'ok' => false,
                'status_code' => null,
                'error' => 'unreachable',
                'url' => $healthUrl,
                'body' => null,
            ];
        }

        return [
            'ok' => $statusCode >= 200 && $statusCode < 300,
            'status_code' => $statusCode,
            'error' => $statusCode >= 200 && $statusCode < 300 ? null : 'bad_status',
            'url' => $healthUrl,
            'body' => is_array($decoded) ? $decoded : $body,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     */
    private function requestJson(Webspace $webspace, string $method, string $endpoint, array $query = [], ?array $json = null): array
    {
        $response = $this->requestRaw($webspace, $method, $endpoint, $query, $json);
        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            throw new FileServiceException('agent_unreachable', 'Agent file API unavailable.', 502, [], $exception);
        }
        if ($status >= 500) {
            throw new FileServiceException('agent_unreachable', 'Agent file API unavailable.', 502, [
                'status_code' => $status,
            ]);
        }
        if ($status < 200 || $status >= 300) {
            try {
                $payload = $response->toArray(false);
            } catch (\Throwable $exception) {
                $body = $response->getContent(false);
                throw new FileServiceException('agent_error', 'Agent file API error.', 502, [
                    'status_code' => $status,
                    'response_body' => $body,
                ], $exception);
            }
            $message = 'Agent file API error.';
            if (is_array($payload)) {
                $errorField = $payload['error'] ?? null;
                if (is_string($errorField) && $errorField !== '') {
                    $message = $errorField;
                } elseif (is_array($errorField) && is_string($errorField['message'] ?? null) && $errorField['message'] !== '') {
                    $message = $errorField['message'];
                }
            }
            $errorCode = $this->extractErrorCode($payload) ?? match ($status) {
                401, 403 => 'agent_unauthorized',
                404 => 'agent_not_found',
                default => 'agent_error',
            };
            throw new FileServiceException($errorCode, $message, 502, [
                'status_code' => $status,
                'response_body' => $payload,
            ]);
        }

        try {
            $payload = $response->toArray(false);
        } catch (\Throwable $exception) {
            throw new FileServiceException('agent_unreachable', 'Agent file API unavailable.', 502, [], $exception);
        }
        return is_array($payload) ? $payload : [];
    }

    private function requestMultipart(Webspace $webspace, string $endpoint, FormDataPart $formData): void
    {
        $config = $this->buildRequestOptions($webspace, 'POST', $endpoint, []);
        $options = $config['options'];
        $options['headers'] = array_merge($options['headers'], $formData->getPreparedHeaders()->toArray());
        $options['body'] = $formData->bodyToIterable();

        $response = $this->httpClient->request('POST', $this->resolveBaseUrl($webspace->getNode()) . $config['endpoint'], $options);
        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            throw new \RuntimeException('Agent file API unavailable.', 0, $exception);
        }
        if ($status >= 500) {
            throw new FileServiceException('agent_unreachable', 'Agent file API unavailable.', 502, [
                'status_code' => $status,
            ]);
        }
        if ($status < 200 || $status >= 300) {
            try {
                $payload = $response->toArray(false);
            } catch (\Throwable $exception) {
                $body = $response->getContent(false);
                throw new FileServiceException('agent_error', 'Agent file API error.', 502, [
                    'status_code' => $status,
                    'response_body' => $body,
                ], $exception);
            }
            $message = 'Agent file API error.';
            if (is_array($payload)) {
                $errorField = $payload['error'] ?? null;
                if (is_string($errorField) && $errorField !== '') {
                    $message = $errorField;
                } elseif (is_array($errorField) && is_string($errorField['message'] ?? null) && $errorField['message'] !== '') {
                    $message = $errorField['message'];
                }
            }
            $errorCode = $this->extractErrorCode($payload) ?? match ($status) {
                401, 403 => 'agent_unauthorized',
                404 => 'agent_not_found',
                default => 'agent_error',
            };
            throw new FileServiceException($errorCode, $message, 502, [
                'status_code' => $status,
                'response_body' => $payload,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     */
    private function requestRaw(Webspace $webspace, string $method, string $endpoint, array $query = [], ?array $json = null): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $options = $this->buildRequestOptions($webspace, $method, $endpoint, $query, $json);
        $baseUrl = $this->resolveBaseUrl($webspace->getNode());
        $fullUrl = $baseUrl . $options['endpoint'];
        $startedAt = microtime(true);

        $this->logger->debug('agent.file_api.request', [
            'agent_id' => $webspace->getNode()->getId(),
            'webspace_id' => $webspace->getId(),
            'customer_id' => $webspace->getCustomer()->getId(),
            'resolved_path' => $webspace->getPath(),
            'method' => $method,
            'url' => $fullUrl,
            'timeout_seconds' => $this->timeoutSeconds,
        ]);

        try {
            $response = $this->httpClient->request($method, $fullUrl, $options['options']);
        } catch (TimeoutExceptionInterface $exception) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logger->error('agent.file_api.transport_error', [
                'agent_id' => $webspace->getNode()->getId(),
                'webspace_id' => $webspace->getId(),
                'customer_id' => $webspace->getCustomer()->getId(),
                'method' => $method,
                'url' => $fullUrl,
                'timeout_seconds' => $this->timeoutSeconds,
                'duration_ms' => $durationMs,
                'error' => $exception->getMessage(),
            ]);
            throw new FileServiceException('agent_timeout', 'Agent file API timed out.', 504, [], $exception);
        } catch (TransportExceptionInterface $exception) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logger->error('agent.file_api.transport_error', [
                'agent_id' => $webspace->getNode()->getId(),
                'webspace_id' => $webspace->getId(),
                'customer_id' => $webspace->getCustomer()->getId(),
                'method' => $method,
                'url' => $fullUrl,
                'timeout_seconds' => $this->timeoutSeconds,
                'duration_ms' => $durationMs,
                'error' => $exception->getMessage(),
            ]);
            throw new FileServiceException('agent_unreachable', 'Agent file API unavailable.', 502, [], $exception);
        }

        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logger->error('agent.file_api.status_error', [
                'agent_id' => $webspace->getNode()->getId(),
                'webspace_id' => $webspace->getId(),
                'customer_id' => $webspace->getCustomer()->getId(),
                'method' => $method,
                'url' => $fullUrl,
                'timeout_seconds' => $this->timeoutSeconds,
                'duration_ms' => $durationMs,
                'error' => $exception->getMessage(),
            ]);
            throw new FileServiceException('agent_unreachable', 'Agent file API unavailable.', 502, [], $exception);
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->logger->debug('agent.file_api.response', [
            'agent_id' => $webspace->getNode()->getId(),
            'webspace_id' => $webspace->getId(),
            'customer_id' => $webspace->getCustomer()->getId(),
            'method' => $method,
            'url' => $fullUrl,
            'status_code' => $status,
            'duration_ms' => $durationMs,
        ]);

        return $response;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     *
     * @return array{endpoint: string, options: array<string, mixed>}
     */
    private function buildRequestOptions(Webspace $webspace, string $method, string $endpoint, array $query = [], ?array $json = null): array
    {
        $endpointWithQuery = $endpoint;
        if ($query !== []) {
            $queryString = http_build_query($query);
            $endpointWithQuery = $queryString !== '' ? $endpoint . '?' . $queryString : $endpoint;
        }

        $headers = $this->buildAuthHeaders($webspace, $method, $endpointWithQuery);
        $headers['Accept'] = 'application/json';
        $headers['X-Server-Root'] = rtrim($webspace->getPath(), '/');

        $options = [
            'headers' => $headers,
            'timeout' => $this->timeoutSeconds,
            'max_duration' => $this->timeoutSeconds,
        ];

        if ($json !== null) {
            $options['json'] = $json;
        }

        $requestId = $this->requestStack->getCurrentRequest()?->headers->get('X-Request-ID');
        if (is_string($requestId) && $requestId !== '') {
            $headers['X-Request-ID'] = $requestId;
        }

        $options['headers'] = $headers;

        return [
            'endpoint' => $endpointWithQuery,
            'options' => $options,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildAuthHeaders(Webspace $webspace, string $method, string $endpointWithQuery): array
    {
        $agent = $webspace->getNode();
        $agentId = $agent->getId();
        $customerId = (string) $webspace->getCustomer()->getId();
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

    private function resolveBaseUrl(Agent $node): string
    {
        $baseUrl = $node->getServiceBaseUrl();
        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/');
        }

        $host = $node->getLastHeartbeatIp();
        if (!is_string($host) || $host === '') {
            throw new FileServiceException('agent_misconfigured', 'Agent service host not configured.', 422);
        }

        $port = $this->defaultServicePort > 0 ? $this->defaultServicePort : 8087;
        $scheme = $this->defaultServiceScheme !== '' ? $this->defaultServiceScheme : 'http';

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function extractErrorCode(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }
        $error = $payload['error'] ?? null;
        if (is_array($error)) {
            $code = $error['code'] ?? null;
            return is_string($code) && $code !== '' ? $code : null;
        }
        if (is_string($error) && $error !== '') {
            return $error;
        }
        return null;
    }

    private function buildEndpoint(Webspace $webspace, string $suffix): string
    {
        return sprintf('/v1/servers/webspace-%s%s', $webspace->getId(), $suffix);
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
                throw new \RuntimeException('Path traversal is not allowed.');
            }
            $safe[] = $segment;
        }

        return implode('/', $safe);
    }

    private function combinePath(string $path, string $name): string
    {
        $relativePath = $this->normalizeRelativePath($path);
        $safeName = $this->normalizeName($name);

        return $relativePath === '' ? $safeName : sprintf('%s/%s', $relativePath, $safeName);
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Invalid name.');
        }

        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Invalid name.');
        }

        return $name;
    }

    private function assertValidName(string $name): void
    {
        $this->normalizeName($name);
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    private function parseContentLength(array $headers): ?int
    {
        $lengthHeader = $headers['content-length'][0] ?? null;
        if ($lengthHeader === null) {
            return null;
        }
        if (!is_string($lengthHeader) || $lengthHeader === '') {
            return null;
        }
        if (!ctype_digit($lengthHeader)) {
            return null;
        }

        return (int) $lengthHeader;
    }
}
