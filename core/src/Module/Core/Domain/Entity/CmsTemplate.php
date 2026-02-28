<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\CmsTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CmsTemplateRepository::class)]
#[ORM\Table(name: 'cms_templates')]
#[ORM\UniqueConstraint(name: 'uniq_cms_templates_template_key', columns: ['template_key'])]
class CmsTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'template_key', length: 64)]
    private string $templateKey;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column]
    private bool $active = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $previewPath = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, CmsTemplateVersion> */
    #[ORM\OneToMany(mappedBy: 'template', targetEntity: CmsTemplateVersion::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['versionNumber' => 'DESC'])]
    private Collection $versions;

    public function __construct(string $templateKey, string $name)
    {
        $this->templateKey = strtolower(trim($templateKey));
        $this->name = trim($name);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->versions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getTemplateKey(): string
    {
        return $this->templateKey;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function isActive(): bool
    {
        return $this->active;
    }
    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }
    public function getPreviewPath(): ?string
    {
        return $this->previewPath;
    }
    public function setPreviewPath(?string $previewPath): void
    {
        $this->previewPath = $previewPath;
        $this->touch();
    }

    /** @return Collection<int, CmsTemplateVersion> */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function addVersion(CmsTemplateVersion $version): void
    {
        if ($this->versions->contains($version)) {
            return;
        }
        $this->versions->add($version);
        $version->setTemplate($this);
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
