<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Mail;

final readonly class DkimProvisionRequestDto
{
    public function __construct(
        public int $domainId,
        public string $domain,
        public string $selector,
        public string $publicKeyPem,
        public string $agentNodeId,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $domainId = is_numeric($payload['domain_id'] ?? null) ? (int) $payload['domain_id'] : 0;
        $domain = strtolower(self::payloadText($payload['domain'] ?? null));
        $selector = strtolower(self::payloadText($payload['selector'] ?? null));
        $publicKeyPem = self::payloadText($payload['public_key_pem'] ?? null);
        $agentNodeId = self::payloadText($payload['agent_node_id'] ?? null);

        if ($domainId <= 0 || $domain === '' || $selector === '' || $publicKeyPem === '' || $agentNodeId === '') {
            throw new \InvalidArgumentException('Invalid DKIM provision payload.');
        }

        return new self($domainId, $domain, $selector, $publicKeyPem, $agentNodeId);
    }

    private static function payloadText(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
