<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvoicePreferencesRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoicePreferencesRepository::class)]
#[ORM\Table(name: 'invoice_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_preferences_customer', columns: ['customer_id'])]
class InvoicePreferences
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\Column(length: 20)]
    private string $locale;

    #[ORM\Column(length: 5)]
    private string $portalLanguage;

    #[ORM\Column]
    private bool $emailDelivery = true;

    #[ORM\Column]
    private bool $pdfDownloadHistory = true;

    #[ORM\Column(length: 60)]
    private string $defaultPaymentMethod;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $customer,
        string $locale,
        bool $emailDelivery,
        bool $pdfDownloadHistory,
        string $defaultPaymentMethod,
        string $portalLanguage = 'de',
    ) {
        $this->customer = $customer;
        $this->locale = $locale;
        $this->emailDelivery = $emailDelivery;
        $this->pdfDownloadHistory = $pdfDownloadHistory;
        $this->defaultPaymentMethod = $defaultPaymentMethod;
        $this->portalLanguage = $portalLanguage;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
        $this->touch();
    }

    public function getPortalLanguage(): string
    {
        return $this->portalLanguage;
    }

    public function setPortalLanguage(string $portalLanguage): void
    {
        $this->portalLanguage = $portalLanguage;
        $this->touch();
    }

    public function isEmailDelivery(): bool
    {
        return $this->emailDelivery;
    }

    public function setEmailDelivery(bool $emailDelivery): void
    {
        $this->emailDelivery = $emailDelivery;
        $this->touch();
    }

    public function isPdfDownloadHistory(): bool
    {
        return $this->pdfDownloadHistory;
    }

    public function setPdfDownloadHistory(bool $pdfDownloadHistory): void
    {
        $this->pdfDownloadHistory = $pdfDownloadHistory;
        $this->touch();
    }

    public function getDefaultPaymentMethod(): string
    {
        return $this->defaultPaymentMethod;
    }

    public function setDefaultPaymentMethod(string $defaultPaymentMethod): void
    {
        $this->defaultPaymentMethod = $defaultPaymentMethod;
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
