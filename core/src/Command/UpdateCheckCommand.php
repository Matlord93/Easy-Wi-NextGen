<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WebinterfaceUpdateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:update:check',
    description: 'Check for available web interface updates.',
)]
final class UpdateCheckCommand extends Command
{
    public function __construct(
        private readonly WebinterfaceUpdateService $updateService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->updateService->checkForUpdate();

        if ($status->error !== null) {
            $output->writeln('<error>Update-Manifest konnte nicht geladen werden.</error>');
            $output->writeln(sprintf('<comment>%s</comment>', $status->error));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Installed version: %s', $status->installedVersion ?? 'unknown'));
        $output->writeln(sprintf('Latest version: %s', $status->latestVersion ?? 'unknown'));

        if ($status->notes) {
            $output->writeln('Release notes:');
            $output->writeln($status->notes);
        }

        if ($status->updateAvailable === true) {
            $output->writeln('<comment>Update verfügbar.</comment>');
        } elseif ($status->updateAvailable === false) {
            $output->writeln('<info>Aktuell.</info>');
        } else {
            $output->writeln('<comment>Status unbekannt.</comment>');
        }

        return Command::SUCCESS;
    }
}
