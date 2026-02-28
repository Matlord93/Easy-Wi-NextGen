<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hp_secret')]
class Secret
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, unique: true)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $ciphertext;

    #[ORM\Column(length: 255)]
    private string $nonce;

    #[ORM\Column(length: 20)]
    private string $keyVersion;

    #[ORM\Column]
    private \DateTimeImmutable $rotatedAt;

    public function __construct(string $name, string $ciphertext, string $nonce, string $keyVersion)
    {
        $this->name = $name;
        $this->ciphertext = $ciphertext;
        $this->nonce = $nonce;
        $this->keyVersion = $keyVersion;
        $this->rotatedAt = new \DateTimeImmutable();
    }

    public function getCiphertext(): string { return $this->ciphertext; }
    public function getNonce(): string { return $this->nonce; }
}
