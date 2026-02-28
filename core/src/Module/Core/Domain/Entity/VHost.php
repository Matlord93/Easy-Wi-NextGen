<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'webspace_vhosts')]
class VHost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Webspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Webspace $webspace;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Domain $domain;

    #[ORM\Column(length: 20, options: ['default' => 'nginx'])]
    private string $runtime = 'nginx';

    #[ORM\Column(length: 255)]
    private string $configPath;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $deployStatus = 'pending';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Webspace $webspace, Domain $domain, string $configPath, string $runtime = 'nginx')
    {
        $this->webspace = $webspace;
        $this->domain = $domain;
        $this->configPath = $configPath;
        $this->runtime = $runtime;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }
}
