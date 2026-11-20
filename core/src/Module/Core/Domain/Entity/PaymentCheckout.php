<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/** @psalm-api */
#[ORM\Entity]
#[ORM\Table(name: 'payment_checkouts')]
#[ORM\UniqueConstraint(name: 'uniq_checkout_provider_reference', columns: ['provider', 'reference'])]
#[ORM\Index(name: 'idx_checkout_pending', columns: ['provider', 'completed', 'created_at'])]
class PaymentCheckout
{
    #[ORM\Id, ORM\Column(length: 48)] private string $id;
    #[ORM\Column] private bool $completed = false;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\ManyToOne(targetEntity: Invoice::class), ORM\JoinColumn(nullable: true)] private ?Invoice $invoice = null;
    #[ORM\ManyToOne(targetEntity: WalletTopup::class), ORM\JoinColumn(nullable: true)] private ?WalletTopup $topup = null;
    public function __construct(
        Invoice|WalletTopup $target,
        #[ORM\Column(length: 20)] private string $provider,
        #[ORM\Column(length: 120)] private string $reference,
        #[ORM\Column] private int $amountCents,
        #[ORM\Column(length: 3)] private string $currency,
    ) {
        if ($target instanceof Invoice) {
            $this->invoice = $target;
        } else {
            $this->topup = $target;
        }
        $this->id = bin2hex(random_bytes(24));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice ?? throw new \LogicException('This checkout funds a wallet.');
    }

    public function getTopup(): ?WalletTopup
    {
        return $this->topup;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): void
    {
        $this->reference = $reference;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function complete(): void
    {
        $this->completed = true;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
