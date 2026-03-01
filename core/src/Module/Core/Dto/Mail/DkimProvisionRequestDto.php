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
        $domain = is_string($payload['domain'] ?? null) ? strtolower(trim($payload['domain'])) : '';
        $selector = is_string($payload['selector'] ?? null) ? strtolower(trim($payload['selector'])) : '';
        $publicKeyPem = is_string($payload['public_key_pem'] ?? null) ? trim($payload['public_key_pem']) : '';
        $agentNodeId = is_string($payload['agent_node_id'] ?? null) ? trim($payload['agent_node_id']) : '';

        if ($domainId <= 0 || $domain === '' || $selector === '' || $publicKeyPem === '' || $agentNodeId === '') {
            throw new \InvalidArgumentException('Invalid DKIM provision payload.');
        }

        return new self($domainId, $domain, $selector, $publicKeyPem, $agentNodeId);
    }
}
