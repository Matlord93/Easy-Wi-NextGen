<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\PrivacyGdprBackgroundJobService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:gdpr:exports:cleanup', description: 'Delete expired GDPR exports and log GDPR deletion audits. Prefer the internal Privacy & GDPR scheduler in the panel for automation.')]
final class GdprExportCleanupCommand extends Command
{
    public function __construct(private readonly PrivacyGdprBackgroundJobService $backgroundJobService)
    {
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
        $deleted = $this->backgroundJobService->deleteExpiredExportsOnly($limit);

        if ($deleted === 0) {
            $io->success('No expired GDPR exports to delete.');
            return Command::SUCCESS;
        }

        $io->success(sprintf('Deleted %d expired GDPR export(s).', $deleted));

        return Command::SUCCESS;
    }
}
