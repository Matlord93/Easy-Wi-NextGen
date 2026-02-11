<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\CmsSiteSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CmsSiteSettingsRepository::class)]
#[ORM\Table(name: 'cms_site_settings')]
#[ORM\UniqueConstraint(name: 'uniq_cms_site_settings_site_id', columns: ['site_id'])]
class CmsSiteSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $activeTheme = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $brandingJson = null;

    /** @var array<string, bool>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $moduleTogglesJson = null;

    /** @var list<array{label: string, url: string, external: bool}>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $headerLinksJson = null;

    /** @var list<array{label: string, url: string, external: bool}>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $footerLinksJson = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Site $site)
    {
        $this->site = $site;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getSite(): Site
    {
        return $this->site;
    }
    public function getActiveTheme(): ?string
    {
        return $this->activeTheme;
    }

    public function setActiveTheme(?string $activeTheme): void
    {
        $activeTheme = $activeTheme === null ? null : trim($activeTheme);
        $this->activeTheme = $activeTheme === '' ? null : $activeTheme;
        $this->touch();
    }

    /** @return array<string, mixed>|null */
    public function getBrandingJson(): ?array
    {
        return $this->brandingJson;
    }
    /** @param array<string, mixed>|null $brandingJson */
    public function setBrandingJson(?array $brandingJson): void
    {
        $this->brandingJson = $brandingJson;
        $this->touch();
    }

    /** @return array<string, bool>|null */
    public function getModuleTogglesJson(): ?array
    {
        return $this->moduleTogglesJson;
    }
    /** @param array<string, bool>|null $moduleTogglesJson */
    public function setModuleTogglesJson(?array $moduleTogglesJson): void
    {
        $this->moduleTogglesJson = $moduleTogglesJson;
        $this->touch();
    }

    /** @return list<array{label: string, url: string, external: bool}>|null */
    public function getHeaderLinksJson(): ?array
    {
        return $this->headerLinksJson;
    }
    /** @param list<array{label: string, url: string, external: bool}>|null $headerLinksJson */
    public function setHeaderLinksJson(?array $headerLinksJson): void
    {
        $this->headerLinksJson = $headerLinksJson;
        $this->touch();
    }

    /** @return list<array{label: string, url: string, external: bool}>|null */
    public function getFooterLinksJson(): ?array
    {
        return $this->footerLinksJson;
    }
    /** @param list<array{label: string, url: string, external: bool}>|null $footerLinksJson */
    public function setFooterLinksJson(?array $footerLinksJson): void
    {
        $this->footerLinksJson = $footerLinksJson;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
