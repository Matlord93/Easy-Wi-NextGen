<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\GdprExportService;
use App\Repository\GdprExportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:gdpr:exports:process', description: 'Process pending GDPR export requests in the background.')]
final class GdprExportProcessCommand extends Command
{
    public function __construct(
        private readonly GdprExportRepository $exportRepository,
        private readonly GdprExportService $exportService,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of exports to process', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));

        $pending = $this->exportRepository->claimPending($limit);
        if ($pending === []) {
            $io->success('No pending GDPR exports.');
            return Command::SUCCESS;
        }

        foreach ($pending as $export) {
            try {
                $data = $this->exportService->buildExportData($export->getCustomer());
                $export->markReady(
                    $data['fileName'],
                    $data['fileSize'],
                    $data['encryptedPayload'],
                    $data['expiresAt'],
                    $data['readyAt'],
                );
                $this->auditLogger->log(null, 'gdpr.export_ready', [
                    'export_id' => $export->getId(),
                    'user_id' => $export->getCustomer()->getId(),
                    'file_name' => $export->getFileName(),
                ]);
            } catch (\Throwable $exception) {
                $export->markFailed();
                $this->auditLogger->log(null, 'gdpr.export_failed', [
                    'export_id' => $export->getId(),
                    'user_id' => $export->getCustomer()->getId(),
                    'error' => $exception->getMessage(),
                ]);
            }

            $this->entityManager->persist($export);
        }

        $this->entityManager->flush();
        $io->success(sprintf('Processed %d GDPR export request(s).', count($pending)));

        return Command::SUCCESS;
    }
}
