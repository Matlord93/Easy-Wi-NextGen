<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Sinusbot;

use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\Sinusbot\AgentBadResponseException;
use App\Module\Core\Application\Sinusbot\AgentUnavailableException;
use App\Module\Core\Domain\Entity\SinusbotInstance;
use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SinusbotInstanceProvisioner
{
    private const HEADER_AGENT_ID = 'X-Agent-ID';
    private const HEADER_TIMESTAMP = 'X-Timestamp';
    private const HEADER_SIGNATURE = 'X-Signature';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretsCrypto $crypto,
        private readonly SinusbotQuotaValidator $quotaValidator,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function createInstanceForCustomer(User $customer, SinusbotNode $node, int $quota, ?string $username = null): SinusbotInstance
    {
        $this->quotaValidator->validate($quota);

        $payload = [
            'customerId' => $customer->getId(),
            'quota' => $quota,
            'username' => $this->normalizeOptional($username),
            'installDir' => $node->getInstallPath(),
            'instanceRoot' => $node->getInstanceRoot(),
            'webBindIp' => $node->getWebBindIp(),
            'webPortBase' => $node->getWebPortBase(),
        ];

        $response = $this->requestJson($node, 'POST', '/internal/sinusbot/instances', $payload);

        $instance = new SinusbotInstance(
            $node,
            $customer,
            (string) ($response['instanceId'] ?? ''),
            (string) ($response['username'] ?? ''),
            $quota,
            (string) ($response['status'] ?? 'stopped'),
        );

        $instance->setManageUrl($this->normalizeOptional($response['manageUrl'] ?? null));
        $instance->setSinusbotPassword($this->normalizeOptional($response['password'] ?? null), $this->crypto);

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        return $instance;
    }

    public function startInstance(SinusbotInstance $instance): void
    {
        $payload = $this->requestJson($instance->getNode(), 'POST', sprintf('/internal/sinusbot/instances/%s/start', $instance->getInstanceId()), []);
        $this->applyStatus($instance, $payload);
    }

    public function stopInstance(SinusbotInstance $instance): void
    {
        $payload = $this->requestJson($instance->getNode(), 'POST', sprintf('/internal/sinusbot/instances/%s/stop', $instance->getInstanceId()), []);
        $this->applyStatus($instance, $payload);
    }

    public function restartInstance(SinusbotInstance $instance): void
    {
        $payload = $this->requestJson($instance->getNode(), 'POST', sprintf('/internal/sinusbot/instances/%s/restart', $instance->getInstanceId()), []);
        $this->applyStatus($instance, $payload);
    }

    public function deleteInstance(SinusbotInstance $instance): void
    {
        $this->requestJson($instance->getNode(), 'DELETE', sprintf('/internal/sinusbot/instances/%s', $instance->getInstanceId()), []);
        $this->entityManager->remove($instance);
        $this->entityManager->flush();
    }

    public function resetPassword(SinusbotInstance $instance): string
    {
        $payload = $this->requestJson($instance->getNode(), 'POST', sprintf('/internal/sinusbot/instances/%s/reset-password', $instance->getInstanceId()), []);
        $password = $this->normalizeOptional($payload['password'] ?? null);

        $instance->setSinusbotPassword($password, $this->crypto);
        $instance->setSinusbotUsername((string) ($payload['username'] ?? $instance->getSinusbotUsername()));
        $this->entityManager->flush();

        return $password ?? '';
    }

    public function syncStatus(SinusbotInstance $instance): void
    {
        $payload = $this->requestJson($instance->getNode(), 'GET', sprintf('/internal/sinusbot/instances/%s/status', $instance->getInstanceId()), []);
        $this->applyStatus($instance, $payload);
    }

    public function updateQuota(SinusbotInstance $instance, int $quota): void
    {
        $this->quotaValidator->validate($quota);

        $payload = $this->requestJson(
            $instance->getNode(),
            'POST',
            sprintf('/internal/sinusbot/instances/%s/quota', $instance->getInstanceId()),
            ['quota' => $quota],
        );

        $instance->setBotQuota($quota);
        $this->applyStatus($instance, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(SinusbotNode $node, string $method, string $path, array $payload): array
    {
        $agentBaseUrl = rtrim($node->getAgentBaseUrl(), '/');
        if ($agentBaseUrl === '') {
            throw new AgentUnavailableException('Agent base URL missing for node.');
        }

        $body = $payload !== [] ? json_encode($payload, JSON_THROW_ON_ERROR) : '';
        $url = $agentBaseUrl . $path;

        $agentId = (string) $node->getAgent()->getId();
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeImmutable::RFC3339);
        $signature = $this->signPayload($agentId, $method, $path, $timestamp, $body, $node);

        $attempts = 0;
        do {
            $attempts++;
            try {
                $response = $this->httpClient->request($method, $url, [
                    'headers' => [
                        self::HEADER_AGENT_ID => $agentId,
                        self::HEADER_TIMESTAMP => $timestamp,
                        self::HEADER_SIGNATURE => $signature,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => $body,
                    'timeout' => $this->timeoutSeconds,
                ]);

                $statusCode = $response->getStatusCode();
                $content = $response->getContent(false);
                $data = $content !== '' ? json_decode($content, true) : [];

                if ($statusCode >= 400) {
                    $errorCode = is_array($data) ? ($data['error_code'] ?? 'agent_error') : 'agent_error';
                    $message = is_array($data) ? ($data['error'] ?? 'Agent error') : 'Agent error';
                    throw new AgentBadResponseException(sprintf('%s (%s)', $message, (string) $errorCode));
                }

                return is_array($data) ? $data : [];
            } catch (TransportExceptionInterface $exception) {
                $this->logger->warning('Sinusbot agent request failed.', [
                    'path' => $path,
                    'attempt' => $attempts,
                    'error' => $exception->getMessage(),
                ]);
                if ($attempts >= 2) {
                    throw new AgentUnavailableException('Sinusbot agent unavailable.', previous: $exception);
                }
            }
        } while ($attempts < 2);

        return [];
    }

    private function signPayload(string $agentId, string $method, string $path, string $timestamp, string $body, SinusbotNode $node): string
    {
        $token = $node->getAgentApiToken($this->crypto);
        $payload = sprintf("%s\n%s\n%s\n%s\n%s", $agentId, strtoupper($method), $path, $timestamp, $body);

        return hash_hmac('sha256', $payload, $token);
    }

    private function applyStatus(SinusbotInstance $instance, array $payload): void
    {
        if (isset($payload['status']) && is_string($payload['status'])) {
            $instance->setStatus($payload['status']);
        }
        $this->entityManager->flush();
    }

    private function normalizeOptional(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
