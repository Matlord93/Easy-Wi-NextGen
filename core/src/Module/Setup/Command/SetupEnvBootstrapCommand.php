<?php

declare(strict_types=1);

namespace App\Module\Setup\Command;

use App\Module\Setup\Application\InstallEnvBootstrap;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:setup:env-bootstrap', description: 'Checks or writes missing setup env secrets.')]
final class SetupEnvBootstrapCommand extends Command
{
    public function __construct(
        private readonly InstallEnvBootstrap $bootstrap,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('check-only', null, InputOption::VALUE_NONE, 'Check env bootstrap readiness without writing anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checkOnly = (bool) $input->getOption('check-only');
        $check = $this->bootstrap->checkStatus($this->projectDir);
        $path = (string) $check['env_path'];
        $missing = $check['missing_keys'];
        $writable = (bool) $check['writable'];

        if ($checkOnly) {
            if (!$writable) {
                $output->writeln('<error>FAIL: ' . $path . ' is not writable.</error>');
                return Command::FAILURE;
            }

            if ($missing !== []) {
                $output->writeln('<error>FAIL: missing env bootstrap keys: ' . implode(', ', $missing) . '</error>');
                return Command::FAILURE;
            }

            $output->writeln('<info>PASS: env bootstrap ready.</info>');
            return Command::SUCCESS;
        }

        $result = $this->bootstrap->ensure($this->projectDir);
        if (!($result['ok'] ?? false)) {
            $output->writeln('<error>FAIL: unable to write env bootstrap values to ' . $path . '.</error>');
            $output->writeln('<comment>Remediation:</comment>');
            $output->writeln('<comment>  chmod 600 ' . $path . ' || chmod 700 ' . dirname($path) . '</comment>');
            $output->writeln('<comment>  chown $(whoami):$(whoami) ' . dirname($path) . ' ' . $path . '</comment>');
            return Command::FAILURE;
        }

        $written = $result['written_keys'] ?? [];
        if ($written === []) {
            $output->writeln('<info>PASS: env bootstrap values already present.</info>');
        } else {
            $output->writeln('<info>PASS: wrote missing keys: ' . implode(', ', $written) . '</info>');
        }

        return Command::SUCCESS;
    }
}
