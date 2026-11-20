<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Application\Billing\BillingSettings;
use App\Module\Core\Application\Billing\InvoiceArchiveManager;
use App\Module\Core\Application\Billing\InvoiceDocumentService;
use App\Module\Core\Application\Billing\InvoiceItems;
use App\Module\Core\Application\Billing\InvoiceLayoutRenderer;
use App\Module\Core\Application\Billing\PaymentRecorder;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\RentalAccount;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Enum\PaymentStatus;
use App\Module\Core\Domain\ValueObject\MinuteTariff;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class ContractInvoices
{
    public function __construct(private EntityManagerInterface $em, private BillingSettings $settings, private InvoiceLayoutRenderer $layouts, private InvoiceDocumentService $documents, private InvoiceArchiveManager $archives, private PaymentRecorder $payments, private WalletLedger $ledger)
    {
    }

    /** Caller serializes this account, including metering and cancellation. */
    public function issue(RentalAccount $account, string $mode, \DateTimeImmutable $now, Request $request): void
    {
        /** @var list<ShopContract> $contracts */
        $contracts = $this->em->getRepository(ShopContract::class)->createQueryBuilder('c')->where('c.account = :account AND c.billingMode = :mode AND c.pendingUnits > 0')->setParameter('account', $account->getId(), \Doctrine\DBAL\Types\Types::INTEGER)->setParameter('mode', $mode)->orderBy('c.id')->getQuery()->getResult();
        $units = $account->getCarry($mode);
        foreach ($contracts as $contract) {
            $units += $contract->getPendingUnits();
        }
        $cents = intdiv($units, MinuteTariff::UNITS_PER_CENT);
        if (0 === $cents) {
            return;
        }
        if ($cents > 2_147_483_647) {
            throw new \DomainException('Invoice amount exceeds the supported maximum.');
        }
        $settings = $this->settings->get();
        $rows = [];
        $remaining = $cents;
        $groupedCents = 0;
        $groupedCount = 0;
        $groupedMinutes = 0;
        $descriptionBytes = 0;
        foreach ($contracts as $contract) {
            $amount = min($remaining, intdiv($contract->getPendingUnits(), MinuteTariff::UNITS_PER_CENT));
            $remaining -= $amount;
            $minutes = $contract->getChargedMinutes() - $contract->getInvoicedMinutes();
            $description = '#'.($contract->getId() ?? 0).' '.(string) ($contract->getConfiguration()['name'] ?? $contract->getKind()->label()).(\App\Module\Core\Domain\Enum\ShopProductKind::Domain === $contract->getKind() ? ' · feste Domainlaufzeit' : ' · '.$minutes.' angefangene Minuten');
            if (count($rows) < 98 && $descriptionBytes + strlen($description) <= 23000) {
                $descriptionBytes += strlen($description);
                $rows[] = ['description' => $description, 'quantity' => '1', 'unit_price' => self::decimal($amount), 'tax_rate' => (string) $settings['tax_rate']];
            } else {
                $groupedCents += $amount;
                ++$groupedCount;
                $groupedMinutes += $minutes;
            }
        }
        if ($groupedCount > 0) {
            $rows[] = ['description' => $groupedCount.' weitere Mietverträge · '.$groupedMinutes.' angefangene Minuten', 'quantity' => '1', 'unit_price' => self::decimal($groupedCents), 'tax_rate' => (string) $settings['tax_rate']];
        }
        if ($remaining > 0) {
            $rows[] = ['description' => 'Zusammengefasste Cent-Bruchteile aus der minutengenauen Abrechnung', 'quantity' => '1', 'unit_price' => self::decimal($remaining), 'tax_rate' => (string) $settings['tax_rate']];
        }
        $tax = InvoiceItems::calculate($rows, (string) $settings['tax_mode']);
        $invoice = new Invoice($account->getCustomer(), 'RENT-'.$now->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(10))), $cents, $account->getCurrency(), $now->modify('+14 days'));
        $this->layouts->snapshot($invoice, $request, $tax, 'Mietverträge · Nutzungsabrechnung');
        $this->em->persist($invoice);
        $this->em->flush();
        $this->archives->archiveInvoiceContent($invoice, $this->documents->generate($invoice, $request), $invoice->getNumber().'.pdf', 'application/pdf', $account->getCustomer());
        if ('wallet' === $mode) {
            $this->payments->record($invoice, 'wallet_usage', 'RENT-'.$invoice->getNumber(), $cents, $account->getCurrency(), PaymentStatus::Succeeded);
        }
        foreach ($contracts as $contract) {
            if ('wallet' === $mode) {
                $this->ledger->post($account, 'usage', -$contract->getPendingUnits(), 'usage:'.$invoice->getNumber().':'.($contract->getId() ?? 0), 'Nutzung Mietvertrag #'.($contract->getId() ?? 0), null, $contract, $invoice);
            }
            $contract->invoiced();
        }
        $account->setCarry($mode, $units % MinuteTariff::UNITS_PER_CENT);
    }

    private static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
