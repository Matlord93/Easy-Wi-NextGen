<?php

declare(strict_types=1);

namespace App\Module\Setup\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:setup:manual-update',
    description: 'Emergency manual update flow for hosted installs without admin panel access.',
)]
final class ManualUpdateRecoveryCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('skip-cache', null, InputOption::VALUE_NONE, 'Skip cache:clear and cache:warmup.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Per-command timeout in seconds.', '600');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timeout = max(30, (int) $input->getOption('timeout'));
        $logDir = $this->projectDir . '/var/log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $logPath = $logDir . '/manual-update-' . date('Ymd-His') . '.log';
        $writeLog = static function (string $line) use ($logPath): void {
            @file_put_contents($logPath, '[' . date('c') . '] ' . $line . PHP_EOL, FILE_APPEND);
        };

        $output->writeln('<info>== app:setup:manual-update ==</info>');
        $output->writeln('Log: ' . $logPath);
        $writeLog('manual update started');

        if (!is_file($this->projectDir . '/vendor/autoload.php')) {
            $message = 'vendor/autoload.php missing. Run composer install first.';
            $output->writeln('<error>[FAIL] ' . $message . '</error>');
            $writeLog($message);
            return Command::FAILURE;
        }

        $commands = [
            ['php', 'bin/console', 'app:setup:env-bootstrap', '--no-interaction'],
            ['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'],
            ['php', 'bin/console', 'app:settings:ensure-defaults', '--no-interaction'],
        ];

        if (!$input->getOption('skip-cache')) {
            $commands[] = ['php', 'bin/console', 'cache:clear', '--no-interaction'];
            $commands[] = ['php', 'bin/console', 'cache:warmup', '--no-interaction'];
        }

        foreach ($commands as $command) {
            $name = implode(' ', $command);
            $output->writeln('→ ' . $name);
            $writeLog('running: ' . $name);

            $process = new Process($command, $this->projectDir);
            $process->setTimeout($timeout);
            $process->run();

            $stdout = trim($process->getOutput());
            $stderr = trim($process->getErrorOutput());
            if ($stdout !== '') {
                $writeLog('stdout: ' . $stdout);
            }
            if ($stderr !== '') {
                $writeLog('stderr: ' . $stderr);
            }

            if (!$process->isSuccessful()) {
                $output->writeln('<error>[FAIL] ' . $name . '</error>');
                $writeLog('failed: ' . $name . ' exit=' . (string) $process->getExitCode());
                return Command::FAILURE;
            }

            $output->writeln('<info>[PASS] ' . $name . '</info>');
        }

        $output->writeln('<info>SUMMARY: PASS</info>');
        $writeLog('manual update finished successfully');

        return Command::SUCCESS;
    }
}
