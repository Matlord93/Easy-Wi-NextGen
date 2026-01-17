<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\TemplateAuditService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'templates:audit',
    description: 'Audit game templates for duplicates, invalid fields, and usage.',
)]
final class TemplatesAuditCommand extends Command
{
    public function __construct(
        private readonly TemplateAuditService $auditService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $templates = $this->auditService->getTemplates();

        $io->title('Template Audit');
        $io->text(sprintf('Scanned %d templates.', count($templates)));

        $io->section('Duplicate candidates');
        $osGroups = $this->auditService->findOsDuplicateGroups($templates);
        if ($osGroups === []) {
            $io->text('No OS-specific duplicates found.');
        } else {
            $io->text('OS-specific groups:');
            foreach ($osGroups as $group) {
                $lines = [];
                foreach ($group['templates'] as $template) {
                    $os = $this->auditService->resolveTemplateOs($template) ?? 'unknown';
                    $lines[] = sprintf('#%d %s (%s)', $template->getId(), $template->getGameKey(), $os);
                }
                $io->writeln(sprintf(
                    '- %s (steam_app_id: %s): %s',
                    $group['base_key'],
                    $group['steam_app_id'] ?? 'null',
                    implode(', ', $lines),
                ));
            }
        }

        $commandGroups = $this->auditService->findCommandDuplicateGroups($templates);
        if ($commandGroups === []) {
            $io->text('No duplicate command signatures found.');
        } else {
            $io->text('Start/install/update command overlaps:');
            foreach ($commandGroups as $group) {
                $lines = [];
                foreach ($group['templates'] as $template) {
                    $lines[] = sprintf('#%d %s', $template->getId(), $template->getGameKey());
                }
                $io->writeln(sprintf('- %s', implode(', ', $lines)));
            }
        }

        $io->section('Missing/invalid fields');
        $invalid = $this->auditService->findInvalidTemplates($templates);
        if ($invalid === []) {
            $io->text('No invalid templates found.');
        } else {
            foreach ($invalid as $entry) {
                $template = $entry['template'];
                $issues = $entry['issues'];
                $io->writeln(sprintf('- #%d %s: %s', $template->getId(), $template->getGameKey(), implode(' ', $issues)));
            }
        }

        $io->section('Unused templates');
        $usageTable = $this->auditService->resolveUsageTable();
        if ($usageTable === null) {
            $io->warning('No instances table found (server_instances or instances). Skipping unused check.');
        } else {
            $unused = $this->auditService->findUnusedTemplates($templates);
            if ($unused === []) {
                $io->text(sprintf('All templates are referenced in %s.', $usageTable));
            } else {
                $io->text(sprintf('Templates unused in %s:', $usageTable));
                foreach ($unused as $template) {
                    $io->writeln(sprintf('- #%d %s', $template->getId(), $template->getGameKey()));
                }
            }
        }

        return Command::SUCCESS;
    }
}
