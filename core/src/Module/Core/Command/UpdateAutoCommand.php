<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\AgentUpdateQueueService;
use App\Module\Setup\Application\WebinterfaceUpdateService;
use App\Module\Setup\Application\WebinterfaceUpdateSettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'app:update:auto',
    description: 'Automatically apply web interface updates when auto mode is enabled.',
)]
final class UpdateAutoCommand extends Command
{
    public function __construct(
        private readonly WebinterfaceUpdateService $updateService,
        private readonly WebinterfaceUpdateSettingsService $settingsService,
        private readonly AgentUpdateQueueService $agentUpdateQueueService,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run even if auto-update is disabled in settings')
            ->addOption('no-migrate', null, InputOption::VALUE_NONE, 'Deprecated: migrations are part of the standard core update path')
            ->addOption('no-agents', null, InputOption::VALUE_NONE, 'Skip queuing available agent updates')
            ->addOption('agents-only', null, InputOption::VALUE_NONE, 'Only queue available agent updates and skip the core update');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->settingsService->getSettings();

        if (!$settings['autoEnabled'] && !$input->getOption('force')) {
            $output->writeln('Auto-Update ist deaktiviert. Nutze --force zum Erzwingen.');
            return Command::SUCCESS;
        }

        if (!$input->getOption('no-agents') || $input->getOption('agents-only')) {
            $agentChannel = $settings['agentChannel'];
            $output->writeln(sprintf('Prüfe Agent-Updates (Kanal: %s)…', $agentChannel));
            $agentResult = $this->agentUpdateQueueService->queueAvailableUpdates($agentChannel, (bool) $input->getOption('force'));
            if ($agentResult['requiresPanelProxy']) {
                $output->writeln('<comment>Agent-Updates benötigen einen Panel-Proxy und wurden nicht automatisch eingeplant.</comment>');
            } else {
                $output->writeln(sprintf('Agent-Update-Jobs eingeplant: %d/%d.', $agentResult['queued'], $agentResult['total']));
            }

            if ($input->getOption('agents-only')) {
                return Command::SUCCESS;
            }
        }

        $channel = $settings['coreChannel'];
        $output->writeln(sprintf('Prüfe auf Updates (Kanal: %s)…', $channel));

        $status = $this->updateService->checkForUpdate((bool) $input->getOption('force'));
        if ($status->updateAvailable === false) {
            $output->writeln('Kein Update verfügbar.');
            return Command::SUCCESS;
        }

        if ($status->updateAvailable === null) {
            $output->writeln('Update-Status unbekannt (Manifest nicht erreichbar?).');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Update verfügbar: %s → %s',
            $status->installedVersion ?? '?',
            $status->latestVersion ?? '?',
        ));

        $result = $this->updateService->applyUpdate();
        if (!$result->success) {
            $output->writeln('Update fehlgeschlagen: ' . ($result->error ?? $result->message));
            if ($result->logPath) {
                $output->writeln(sprintf('Log: %s', $result->logPath));
            }
            return Command::FAILURE;
        }

        $output->writeln($result->message);
        if ($input->getOption('no-migrate')) {
            $output->writeln('<comment>' . $this->translator->trans('admin_updates_no_migrate_deprecated', [], 'portal') . '</comment>');
        }
        if (!$settings['autoMigrate']) {
            $output->writeln('<comment>' . $this->translator->trans('admin_updates_auto_migrate_core_always_runs', [], 'portal') . '</comment>');
        }

        return Command::SUCCESS;
    }
}
