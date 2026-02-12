<?php

declare(strict_types=1);

namespace App\Module\Setup\Command;

use App\Module\Setup\Application\InstallEnvBootstrap;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'app:setup:install-or-update', description: 'Runs env bootstrap, migrations, defaults, and health checks.')]
final class SetupInstallOrUpdateCommand extends Command
{
    public function __construct(
        private readonly InstallEnvBootstrap $bootstrap,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $warnings = [];

        $output->writeln('== app:setup:install-or-update ==');

        $check = $this->bootstrap->checkStatus($this->projectDir);
        if (!(bool) $check['writable']) {
            $path = (string) $check['env_path'];
            $output->writeln('<error>[FAIL] .env bootstrap target is not writable: ' . $path . '</error>');
            $output->writeln('<comment>Remediation: chmod 600 ' . $path . ' || chmod 700 ' . dirname($path) . '</comment>');
            $output->writeln('<comment>Remediation: chown $(whoami):$(whoami) ' . dirname($path) . ' ' . $path . '</comment>');
            return 1;
        }

        $bootstrap = $this->bootstrap->ensure($this->projectDir);
        if (!($bootstrap['ok'] ?? false)) {
            $output->writeln('<error>[FAIL] Env bootstrap failed.</error>');
            return 1;
        }
        $output->writeln('<info>[PASS] Env bootstrap ready.</info>');

        if (!is_dir($this->projectDir . '/vendor') || !is_file($this->projectDir . '/vendor/autoload.php')) {
            $output->writeln('<error>[FAIL] vendor/autoload.php missing. Run composer install and retry.</error>');
            return 1;
        }
        $output->writeln('<info>[PASS] Composer autoload present.</info>');

        foreach ([
            ['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'],
            ['php', 'bin/console', 'app:settings:ensure-defaults', '--no-interaction'],
            ['php', 'bin/console', 'cache:clear', '--no-interaction'],
            ['php', 'bin/console', 'cache:warmup', '--no-interaction'],
        ] as $command) {
            $process = new Process($command, $this->projectDir);
            $process->setTimeout(300);
            $process->run();

            $name = implode(' ', $command);
            if (!$process->isSuccessful()) {
                if (str_contains($name, 'cache:clear')) {
                    $warnings[] = $name;
                    $output->writeln('<comment>[WARN] ' . $name . ' failed, continuing.</comment>');
                    continue;
                }

                $output->writeln('<error>[FAIL] ' . $name . ' failed.</error>');
                return 1;
            }

            $output->writeln('<info>[PASS] ' . $name . '</info>');
        }

        $output->writeln('');
        if ($warnings !== []) {
            $output->writeln('<comment>SUMMARY: PASS with warnings (' . count($warnings) . ')</comment>');
            return 2;
        }

        $output->writeln('<info>SUMMARY: PASS</info>');

        return 0;
    }
}
