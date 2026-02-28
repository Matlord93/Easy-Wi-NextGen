<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\CmsTemplateVersionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CmsTemplateVersionRepository::class)]
#[ORM\Table(name: 'cms_template_versions')]
#[ORM\UniqueConstraint(name: 'uniq_template_version', columns: ['template_id', 'version_number'])]
class CmsTemplateVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CmsTemplate::class, inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CmsTemplate $template;

    #[ORM\Column(name: 'version_number')]
    private int $versionNumber;

    #[ORM\Column(length: 255)]
    private string $storagePath;

    #[ORM\Column(length: 64)]
    private string $checksum;

    #[ORM\Column(type: 'json')]
    private array $manifest;

    #[ORM\Column]
    private bool $active = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deployedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $versionNumber, string $storagePath, string $checksum, array $manifest = [])
    {
        $this->versionNumber = $versionNumber;
        $this->storagePath = $storagePath;
        $this->checksum = $checksum;
        $this->manifest = $manifest;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function setTemplate(CmsTemplate $template): void { $this->template = $template; }
    public function getTemplate(): CmsTemplate { return $this->template; }
    public function getVersionNumber(): int { return $this->versionNumber; }
    public function getStoragePath(): string { return $this->storagePath; }
    public function getManifest(): array { return $this->manifest; }
    public function isActive(): bool { return $this->active; }
    public function markActive(bool $active): void { $this->active = $active; if ($active) { $this->deployedAt = new \DateTimeImmutable(); } }
}
