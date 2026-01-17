<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Setup\Application\WebinterfaceUpdateService;
use App\Module\Setup\Application\WebinterfaceUpdateSettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->settingsService->getSettings();
        if (!$settings['autoEnabled']) {
            $output->writeln('Auto update is disabled.');
            return Command::SUCCESS;
        }

        $status = $this->updateService->checkForUpdate();
        if ($status->updateAvailable === false) {
            $output->writeln('No update available.');
            return Command::SUCCESS;
        }

        if ($status->updateAvailable === null) {
            $output->writeln('Update availability unknown.');
            return Command::FAILURE;
        }

        $result = $this->updateService->applyUpdate();
        if ($result->success) {
            $output->writeln($result->message);
            return Command::SUCCESS;
        }

        $output->writeln($result->error ?? $result->message);
        return Command::FAILURE;
    }
}
