<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\ApiToken;
use App\Module\Core\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class ApiTokenManager
{
    public function __construct(
        private readonly EncryptionService $encryptionService,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param string[] $scopes
     * @return array{token: string, apiToken: ApiToken}
     */
    public function issueToken(
        User $customer,
        string $name,
        array $scopes,
        ?User $actor = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): array {
        $token = $this->generateToken();
        $tokenHash = $this->hashToken($token);
        $tokenPrefix = $this->tokenPrefix($token);
        $encryptedToken = $this->encryptionService->encrypt($token);

        $apiToken = new ApiToken($customer, $name, $scopes, $tokenPrefix, $tokenHash, $encryptedToken, $expiresAt);
        $this->entityManager->persist($apiToken);

        $this->auditLogger->log($actor ?? $customer, 'api_token.created', [
            'customer_id' => $customer->getId(),
            'token_prefix' => $tokenPrefix,
            'token_name' => $apiToken->getName(),
            'scopes' => $apiToken->getScopes(),
            'expires_at' => $expiresAt?->format(DATE_ATOM),
        ]);

        return ['token' => $token, 'apiToken' => $apiToken];
    }

    public function rotateToken(ApiToken $apiToken, ?User $actor = null): string
    {
        $token = $this->generateToken();
        $tokenHash = $this->hashToken($token);
        $tokenPrefix = $this->tokenPrefix($token);
        $encryptedToken = $this->encryptionService->encrypt($token);

        $apiToken->rotate($encryptedToken, $tokenHash, $tokenPrefix);
        $this->entityManager->persist($apiToken);

        $this->auditLogger->log($actor ?? $apiToken->getCustomer(), 'api_token.rotated', [
            'customer_id' => $apiToken->getCustomer()->getId(),
            'token_id' => $apiToken->getId(),
            'token_prefix' => $tokenPrefix,
        ]);

        return $token;
    }

    public function revokeToken(ApiToken $apiToken, ?User $actor = null): void
    {
        if ($apiToken->isRevoked()) {
            return;
        }

        $apiToken->revoke();
        $this->entityManager->persist($apiToken);

        $this->auditLogger->log($actor ?? $apiToken->getCustomer(), 'api_token.revoked', [
            'customer_id' => $apiToken->getCustomer()->getId(),
            'token_id' => $apiToken->getId(),
            'token_prefix' => $apiToken->getTokenPrefix(),
        ]);
    }

    private function generateToken(): string
    {
        return 'ewi_' . bin2hex(random_bytes(32));
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function tokenPrefix(string $token): string
    {
        return substr($token, 0, 12);
    }
}
