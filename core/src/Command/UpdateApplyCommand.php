<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WebinterfaceUpdateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:update:apply',
    description: 'Apply a web interface update from GitHub releases.',
)]
final class UpdateApplyCommand extends Command
{
    public function __construct(
        private readonly WebinterfaceUpdateService $updateService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Update ohne weitere Nachfrage installieren.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('yes')) {
            $output->writeln('<comment>Abgebrochen. Verwenden Sie --yes, um das Update anzuwenden.</comment>');
            return Command::INVALID;
        }

        $result = $this->updateService->applyUpdate();
        if ($result->logPath) {
            $output->writeln(sprintf('Log: %s', $result->logPath));
        }

        if ($result->success) {
            $output->writeln(sprintf('<info>%s</info>', $result->message));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<error>%s</error>', $result->message));
        if ($result->error) {
            $output->writeln(sprintf('<comment>%s</comment>', $result->error));
        }

        return Command::FAILURE;
    }
}
