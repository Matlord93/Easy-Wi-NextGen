<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ShopProductRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShopProductRepository::class)]
#[ORM\Table(name: 'shop_products')]
class ShopProduct
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $siteId;

    #[ORM\ManyToOne(targetEntity: ShopCategory::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ShopCategory $category;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column]
    private int $priceMonthlyCents;

    #[ORM\ManyToOne(targetEntity: Template::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Template $template;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Agent $node;

    #[ORM\Column]
    private int $cpuLimit;

    #[ORM\Column]
    private int $ramLimit;

    #[ORM\Column]
    private int $diskLimit;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        int $siteId,
        ShopCategory $category,
        string $name,
        string $description,
        int $priceMonthlyCents,
        Template $template,
        Agent $node,
        int $cpuLimit,
        int $ramLimit,
        int $diskLimit,
        ?string $imageUrl = null,
        bool $isActive = true,
    ) {
        $this->siteId = $siteId;
        $this->category = $category;
        $this->name = $name;
        $this->description = $description;
        $this->priceMonthlyCents = $priceMonthlyCents;
        $this->template = $template;
        $this->node = $node;
        $this->cpuLimit = $cpuLimit;
        $this->ramLimit = $ramLimit;
        $this->diskLimit = $diskLimit;
        $this->imageUrl = $imageUrl;
        $this->isActive = $isActive;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSiteId(): int
    {
        return $this->siteId;
    }

    public function getCategory(): ShopCategory
    {
        return $this->category;
    }

    public function setCategory(ShopCategory $category): void
    {
        $this->category = $category;
        $this->touch();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): void
    {
        $this->imageUrl = $imageUrl;
        $this->touch();
    }

    public function getPriceMonthlyCents(): int
    {
        return $this->priceMonthlyCents;
    }

    public function setPriceMonthlyCents(int $priceMonthlyCents): void
    {
        $this->priceMonthlyCents = $priceMonthlyCents;
        $this->touch();
    }

    public function getTemplate(): Template
    {
        return $this->template;
    }

    public function setTemplate(Template $template): void
    {
        $this->template = $template;
        $this->touch();
    }

    public function getNode(): Agent
    {
        return $this->node;
    }

    public function setNode(Agent $node): void
    {
        $this->node = $node;
        $this->touch();
    }

    public function getCpuLimit(): int
    {
        return $this->cpuLimit;
    }

    public function setCpuLimit(int $cpuLimit): void
    {
        $this->cpuLimit = $cpuLimit;
        $this->touch();
    }

    public function getRamLimit(): int
    {
        return $this->ramLimit;
    }

    public function setRamLimit(int $ramLimit): void
    {
        $this->ramLimit = $ramLimit;
        $this->touch();
    }

    public function getDiskLimit(): int
    {
        return $this->diskLimit;
    }

    public function setDiskLimit(int $diskLimit): void
    {
        $this->diskLimit = $diskLimit;
        $this->touch();
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
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
