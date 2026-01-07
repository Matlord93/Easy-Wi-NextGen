<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\InvoiceRepository;
use App\Service\Billing\DunningWorkflow;
use App\Service\Billing\InvoiceStatusUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:billing:automation',
    description: 'Sync invoice statuses and apply dunning reminders.',
)]
final class BillingAutomationCommand extends Command
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly InvoiceStatusUpdater $invoiceStatusUpdater,
        private readonly DunningWorkflow $dunningWorkflow,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $invoices = $this->invoiceRepository->findAll();

        foreach ($invoices as $invoice) {
            $this->invoiceStatusUpdater->syncStatus($invoice, null, $now);
        }

        $dunnable = $this->invoiceRepository->findDunnable($now);
        foreach ($dunnable as $invoice) {
            $this->dunningWorkflow->apply($invoice, null, $now);
        }

        $this->entityManager->flush();

        $output->writeln(sprintf('Processed %d invoices (%d dunning candidates).', count($invoices), count($dunnable)));

        return Command::SUCCESS;
    }
}
