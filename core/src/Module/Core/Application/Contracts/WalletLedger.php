<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\RentalAccount;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\WalletEntry;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WalletLedger
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** The caller holds the account write lock. */
    public function post(RentalAccount $account, string $kind, int $units, string $reference, string $description, ?User $actor = null, ?ShopContract $contract = null, ?Invoice $invoice = null): bool
    {
        if (!in_array($kind, ['opening', 'topup', 'manual_credit', 'usage', 'correction', 'refund'], true) || strlen($reference) < 3 || strlen($reference) > 160 || '' === trim($description) || mb_strlen($description) > 500) {
            throw new \InvalidArgumentException('Invalid wallet posting.');
        }
        $existing = $this->em->getRepository(WalletEntry::class)->findOneBy(['reference' => $reference]);
        if (null !== $existing) {
            if ($existing->getAccount()->getId() !== $account->getId() || $existing->getUnits() !== $units || $existing->getKind() !== $kind || ('topup' === $kind && $existing->getDescription() !== $description)) {
                throw new \DomainException('Wallet reference conflict.');
            }

            return false;
        }
        $this->em->persist(new WalletEntry($account, $kind, $units, $reference, $description, $actor, $contract, $invoice));

        return true;
    }
}
