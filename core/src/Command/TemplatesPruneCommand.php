<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TemplateAuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'templates:prune',
    description: 'Remove broken unused templates.',
)]
final class TemplatesPruneCommand extends Command
{
    public function __construct(
        private readonly TemplateAuditService $auditService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Apply prune changes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $templates = $this->auditService->getTemplates();
        $usageTable = $this->auditService->resolveUsageTable();
        $usageCounts = $usageTable !== null ? $this->auditService->getUsageCounts($usageTable) : [];

        $io->title('Template Prune');

        if ($usageTable === null) {
            $io->warning('No instances table found; prune will only report candidates.');
        }

        $candidates = [];
        foreach ($templates as $template) {
            $isBroken = trim($template->getInstallCommand()) === '' || trim($template->getStartParams()) === '';
            if (!$isBroken) {
                continue;
            }
            $usage = $usageCounts[$template->getId() ?? 0] ?? 0;
            if ($usage > 0) {
                continue;
            }
            $candidates[] = $template;
        }

        if ($candidates === []) {
            $io->text('No prune candidates found.');
            return Command::SUCCESS;
        }

        $io->text('Prune candidates (broken + unused):');
        foreach ($candidates as $template) {
            $io->writeln(sprintf('- #%d %s', $template->getId(), $template->getGameKey()));
            if ($apply && $usageTable !== null) {
                $this->entityManager->remove($template);
            }
        }

        if ($apply) {
            if ($usageTable !== null) {
                $this->entityManager->flush();
                $io->success(sprintf('Removed %d templates.', count($candidates)));
            } else {
                $io->warning('No instances table found; no templates were removed.');
            }
        } else {
            $io->note(sprintf('Dry run: %d templates would be removed.', count($candidates)));
        }

        return Command::SUCCESS;
    }
}
