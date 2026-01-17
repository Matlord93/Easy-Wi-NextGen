<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Ddos;

use App\Module\Core\Domain\Entity\DdosProviderCredential;
use App\Module\Core\Domain\Entity\User;
use App\Repository\DdosProviderCredentialRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;

final class DdosCredentialManager
{
    public function __construct(
        private readonly DdosProviderCredentialRepository $credentialRepository,
        private readonly EncryptionService $encryptionService,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function storeCredential(User $customer, string $provider, string $apiKey, ?User $actor = null): DdosProviderCredential
    {
        $encryptedApiKey = $this->encryptionService->encrypt($apiKey);
        $credential = $this->credentialRepository->findOneByCustomerAndProvider($customer, $provider);

        if ($credential === null) {
            $credential = new DdosProviderCredential($customer, $provider, $encryptedApiKey);
            $this->entityManager->persist($credential);
        } else {
            $credential->setEncryptedApiKey($encryptedApiKey);
            $this->entityManager->persist($credential);
        }

        $this->auditLogger->log($actor ?? $customer, 'ddos_provider.credential_upserted', [
            'customer_id' => $customer->getId(),
            'provider' => $provider,
        ]);

        return $credential;
    }
}
