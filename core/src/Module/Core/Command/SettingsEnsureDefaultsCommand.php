<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\AppSettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:settings:ensure-defaults',
    description: 'Ensure runtime application settings defaults exist in app_settings.',
)]
final class SettingsEnsureDefaultsCommand extends Command
{
    public function __construct(private readonly AppSettingsService $settingsService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->settingsService->ensureRuntimeDefaultsPersisted();

        $output->writeln('<info>Runtime defaults ensured in app_settings.</info>');

        return Command::SUCCESS;
    }
}
