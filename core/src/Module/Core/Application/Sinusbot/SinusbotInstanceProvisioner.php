<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Sinusbot;

use App\Module\Core\Application\AgentConfigurationException;
use App\Module\Core\Application\AgentEndpointResolver;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\SinusbotInstance;
use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class SinusbotInstanceProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretsCrypto $crypto,
        private readonly AgentClient $agentClient,
        private readonly AgentEndpointResolver $endpointResolver,
        private readonly SinusbotQuotaValidator $quotaValidator,
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

        $instanceId = (string) ($response['instanceId'] ?? '');
        $username = $this->resolveUsername($response, $username, $customer);
        $status = $this->normalizeOptional($response['status'] ?? null) ?? 'unknown';

        $instance = new SinusbotInstance(
            $node,
            $customer,
            $instanceId,
            $username,
            $quota,
            $status,
        );

        $this->applyInstancePayload($instance, $response);
        if ($instance->getManageUrl() === null && $instance->getWebPort() !== null) {
            $instance->setManageUrl($this->buildManageUrl($node, $instance->getWebPort()));
        }

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        return $instance;
    }

    public function startInstance(SinusbotInstance $instance): void
    {
        $payload = $this->requestJson($instance->getNode(), 'POST', sprintf('/internal/sinusbot/instances/%s/start', $instance->getInstanceId()), []);
        $this->applyInstancePayload($instance, $payload);
    }

    public function stopInstance(SinusbotInstance $instance): void
    {
        $payload = $this->requestJson($instance->getNode(), 'POST', sprintf('/internal/sinusbot/instances/%s/stop', $instance->getInstanceId()), []);
        $this->applyInstancePayload($instance, $payload);
    }

    public function restartInstance(SinusbotInstance $instance): void
    {
        $payload = $this->requestJson($instance->getNode(), 'POST', sprintf('/internal/sinusbot/instances/%s/restart', $instance->getInstanceId()), []);
        $this->applyInstancePayload($instance, $payload);
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
        $this->applyInstancePayload($instance, $payload);
        $this->entityManager->flush();

        return $password ?? '';
    }

    public function syncStatus(SinusbotInstance $instance): void
    {
        $payload = $this->requestJson($instance->getNode(), 'GET', sprintf('/internal/sinusbot/instances/%s', $instance->getInstanceId()), []);
        $this->applyInstancePayload($instance, $payload);
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
        $this->applyInstancePayload($instance, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(SinusbotNode $node, string $method, string $path, array $payload): array
    {
        return $this->agentClient->requestJson($node, $method, $path, $payload);
    }

    private function applyInstancePayload(SinusbotInstance $instance, array $payload): void
    {
        if (isset($payload['status']) && is_string($payload['status'])) {
            $instance->setStatus($payload['status']);
        }
        if (isset($payload['username']) && is_string($payload['username']) && $payload['username'] !== '') {
            $instance->setSinusbotUsername($payload['username']);
        }
        if (array_key_exists('password', $payload)) {
            $instance->setSinusbotPassword($this->normalizeOptional($payload['password'] ?? null), $this->crypto);
        }

        $botId = $this->normalizeOptional($payload['botId'] ?? $payload['bot_id'] ?? null);
        if ($botId !== null) {
            $instance->setBotId($botId);
        }

        $webPort = $this->normalizeInt($payload['webPort'] ?? $payload['web_port'] ?? null);
        if ($webPort !== null) {
            $instance->setWebPort($webPort);
        }

        $manageUrl = $this->normalizeOptional($payload['manageUrl'] ?? $payload['manage_url'] ?? null);
        if ($manageUrl === null) {
            $manageUrl = $this->buildManageUrl($instance->getNode(), $instance->getWebPort());
        }
        $instance->setManageUrl($manageUrl);
        if ($instance->getBotId() === null && $instance->getInstanceId() !== '') {
            $instance->setBotId($instance->getInstanceId());
        }
        $instance->setLastSeenAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    private function normalizeOptional(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function normalizeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;
            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveUsername(array $payload, ?string $requestedUsername, User $customer): string
    {
        $responseUsername = $this->normalizeOptional($payload['username'] ?? null);
        if ($responseUsername !== null) {
            return $responseUsername;
        }

        $requested = $this->normalizeOptional($requestedUsername);
        if ($requested !== null) {
            return $requested;
        }

        return sprintf('customer-%d', $customer->getId());
    }

    private function buildManageUrl(SinusbotNode $node, ?int $webPort): ?string
    {
        if ($webPort === null) {
            return null;
        }

        $host = trim($node->getWebBindIp());
        $scheme = 'http';

        if ($host === '' || $host === '0.0.0.0' || $host === '::') {
            $host = trim((string) $node->getAgent()->getLastHeartbeatIp());
        }

        $baseUrl = $node->getAgent()->getServiceBaseUrl();
        if ($baseUrl === '') {
            try {
                $baseUrl = $this->endpointResolver->resolveForNode($node)->getBaseUrl();
            } catch (AgentConfigurationException) {
                $baseUrl = '';
            }
        }
        if (($host === '' || $host === '0.0.0.0' || $host === '::') && $baseUrl !== '') {
            $parts = parse_url($baseUrl);
            if (is_array($parts)) {
                $host = $parts['host'] ?? $host;
                $scheme = $parts['scheme'] ?? $scheme;
            }
        }

        if ($host === '' || $host === '0.0.0.0' || $host === '::') {
            return null;
        }

        return sprintf('%s://%s:%d', $scheme, $host, $webPort);
    }
}
