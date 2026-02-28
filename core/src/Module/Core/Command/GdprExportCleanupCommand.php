<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\AuditLogger;
use App\Repository\GdprExportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:gdpr:exports:cleanup', description: 'Delete expired GDPR exports and log GDPR deletion audits.')]
final class GdprExportCleanupCommand extends Command
{
    public function __construct(
        private readonly GdprExportRepository $exportRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of exports to delete', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $now = new \DateTimeImmutable();

        $expired = $this->exportRepository->findExpired($now, $limit);
        if ($expired === []) {
            $io->success('No expired GDPR exports to delete.');
            return Command::SUCCESS;
        }

        foreach ($expired as $export) {
            $this->auditLogger->log(null, 'gdpr.export_deleted', [
                'export_id' => $export->getId(),
                'user_id' => $export->getCustomer()->getId(),
                'expired_at' => $export->getExpiresAt()->format(DATE_RFC3339),
                'deleted_at' => $now->format(DATE_RFC3339),
            ]);
            $this->entityManager->remove($export);
        }

        $this->entityManager->flush();
        $io->success(sprintf('Deleted %d expired GDPR export(s).', count($expired)));

        return Command::SUCCESS;
    }
}
