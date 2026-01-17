<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\TemplateAuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'templates:dedupe',
    description: 'Identify and optionally merge duplicate templates.',
)]
final class TemplatesDedupeCommand extends Command
{
    public function __construct(
        private readonly TemplateAuditService $auditService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Apply dedupe changes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $templates = $this->auditService->getTemplates();

        $io->title('Template Dedupe');

        $osGroups = $this->auditService->findOsDuplicateGroups($templates);
        $mergedUpdates = 0;
        if ($osGroups === []) {
            $io->text('No OS-specific groups found for merged_group tagging.');
        } else {
            $io->text('OS-specific groups that will get merged_group markers:');
            foreach ($osGroups as $group) {
                $baseKey = $group['base_key'];
                $io->writeln(sprintf('- %s (steam_app_id: %s)', $baseKey, $group['steam_app_id'] ?? 'null'));
                foreach ($group['templates'] as $template) {
                    $requirements = $template->getRequirements();
                    $current = $requirements['merged_group'] ?? null;
                    $io->writeln(sprintf('  - #%d %s (merged_group=%s)', $template->getId(), $template->getGameKey(), $current ?? 'null'));
                    if ($current !== $baseKey) {
                        $requirements['merged_group'] = $baseKey;
                        if ($apply) {
                            $template->setRequirements($requirements);
                            $this->entityManager->persist($template);
                        }
                        $mergedUpdates++;
                    }
                }
            }
        }

        $usageTable = $this->auditService->resolveUsageTable();
        $usageCounts = $usageTable !== null ? $this->auditService->getUsageCounts($usageTable) : [];
        $duplicateGroups = [];
        foreach ($templates as $template) {
            $signature = $this->auditService->buildExactSignature($template);
            $duplicateGroups[$signature][] = $template;
        }

        $deleted = 0;
        $plannedDeletes = 0;
        $skippedUsed = 0;
        foreach ($duplicateGroups as $templatesInGroup) {
            if (count($templatesInGroup) < 2) {
                continue;
            }
            usort($templatesInGroup, static fn ($a, $b) => ($a->getId() ?? 0) <=> ($b->getId() ?? 0));
            $keep = array_shift($templatesInGroup);
            $io->writeln(sprintf('Duplicate group (keep #%d %s):', $keep->getId(), $keep->getGameKey()));
            foreach ($templatesInGroup as $template) {
                $usage = $usageCounts[$template->getId() ?? 0] ?? 0;
                if ($usage > 0) {
                    $io->writeln(sprintf('  - #%d %s (skipped, in use)', $template->getId(), $template->getGameKey()));
                    $skippedUsed++;
                    continue;
                }
                $io->writeln(sprintf('  - #%d %s (remove)', $template->getId(), $template->getGameKey()));
                $plannedDeletes++;
                if ($apply) {
                    if ($usageTable === null) {
                        continue;
                    }
                    $this->entityManager->remove($template);
                    $deleted++;
                }
            }
        }

        if ($apply) {
            $this->entityManager->flush();
            $io->success(sprintf('Applied %d merged_group updates, removed %d duplicates.', $mergedUpdates, $deleted));
            if ($usageTable === null) {
                $io->warning('No instances table found; duplicates were not removed.');
            }
        } else {
            $io->note(sprintf('Dry run: %d merged_group updates, %d duplicates flagged.', $mergedUpdates, $plannedDeletes));
        }

        if ($skippedUsed > 0) {
            $io->warning(sprintf('Skipped %d duplicates because they are in use.', $skippedUsed));
        }

        return Command::SUCCESS;
    }
}
