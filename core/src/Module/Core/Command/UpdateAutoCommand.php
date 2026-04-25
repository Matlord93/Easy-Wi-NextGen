<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Setup\Application\WebinterfaceUpdateService;
use App\Module\Setup\Application\WebinterfaceUpdateSettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:update:auto',
    description: 'Automatically apply web interface updates when auto mode is enabled.',
)]
final class UpdateAutoCommand extends Command
{
    public function __construct(
        private readonly WebinterfaceUpdateService $updateService,
        private readonly WebinterfaceUpdateSettingsService $settingsService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run even if auto-update is disabled in settings')
            ->addOption('no-migrate', null, InputOption::VALUE_NONE, 'Skip running DB migrations after update');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->settingsService->getSettings();

        if (!$settings['autoEnabled'] && !$input->getOption('force')) {
            $output->writeln('Auto-Update ist deaktiviert. Nutze --force zum Erzwingen.');
            return Command::SUCCESS;
        }

        $channel = $settings['coreChannel'];
        $output->writeln(sprintf('Prüfe auf Updates (Kanal: %s)…', $channel));

        $status = $this->updateService->checkForUpdate();
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
            return Command::FAILURE;
        }

        $output->writeln($result->message);

        $runMigrate = $settings['autoMigrate'] && !$input->getOption('no-migrate');
        if (!$runMigrate) {
            return Command::SUCCESS;
        }

        $output->writeln('Führe DB-Migrationen aus…');
        $migrateResult = $this->updateService->applyMigrations();
        if (!$migrateResult->success) {
            $output->writeln('Migrationen fehlgeschlagen: ' . ($migrateResult->error ?? $migrateResult->message));
            return Command::FAILURE;
        }

        $output->writeln($migrateResult->message);
        return Command::SUCCESS;
    }
}
