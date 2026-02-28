<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Application\Security;

use App\Module\HostingPanel\Domain\Entity\Secret;
use Doctrine\ORM\EntityManagerInterface;

class SecretVault
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $encryptionKey,
        private readonly string $keyVersion = 'v1',
    ) {
    }

    public function put(string $name, string $plaintext): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->binaryKey());

        $secret = new Secret($name, base64_encode($cipher), base64_encode($nonce), $this->keyVersion);
        $this->entityManager->persist($secret);
        $this->entityManager->flush();
    }

    public function get(string $name): ?string
    {
        $secret = $this->entityManager->getRepository(Secret::class)->findOneBy(['name' => $name]);
        if (!$secret instanceof Secret) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(base64_decode($secret->getCiphertext(), true) ?: '', base64_decode($secret->getNonce(), true) ?: '', $this->binaryKey());

        return is_string($plain) ? $plain : null;
    }

    private function binaryKey(): string
    {
        $decoded = base64_decode($this->encryptionKey, true);
        return is_string($decoded) ? $decoded : hash('sha256', $this->encryptionKey, true);
    }
}
