<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Client;

use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Instance;

final class AgentHmacHeaderFactory
{
    public const HEADER_AGENT_ID = 'X-Agent-ID';
    public const HEADER_CUSTOMER_ID = 'X-Customer-ID';
    public const HEADER_TIMESTAMP = 'X-Timestamp';
    public const HEADER_SIGNATURE = 'X-Signature';
    public const HEADER_CONTENT_SHA256 = 'X-Content-SHA256';

    public function __construct(private readonly EncryptionService $encryptionService)
    {
    }

    /** @return array<string,string> */
    public function create(Instance $instance, string $method, string $requestUri, string $body = ''): array
    {
        $agent = $instance->getNode();
        $agentId = $agent->getId();
        $customerId = (string) $instance->getCustomer()->getId();
        if ($customerId === '') {
            throw new \RuntimeException('Instance customer id is required for agent HMAC authentication.');
        }

        $timestamp = (new \DateTimeImmutable())->format(\DateTimeImmutable::RFC3339);
        $bodyHash = hash('sha256', $body);
        $payload = self::signaturePayload($agentId, $customerId, $method, $requestUri, $timestamp, $bodyHash);
        $signature = hash_hmac('sha256', $payload, $this->encryptionService->decrypt($agent->getSecretPayload()));

        return [
            self::HEADER_AGENT_ID => $agentId,
            self::HEADER_CUSTOMER_ID => $customerId,
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_SIGNATURE => $signature,
            self::HEADER_CONTENT_SHA256 => $bodyHash,
        ];
    }

    public static function signaturePayload(string $agentId, string $customerId, string $method, string $requestUri, string $timestamp, string $bodyHash): string
    {
        return sprintf("%s\n%s\n%s\n%s\n%s\n%s", $agentId, $customerId, strtoupper($method), $requestUri, $timestamp, strtolower($bodyHash));
    }
}
