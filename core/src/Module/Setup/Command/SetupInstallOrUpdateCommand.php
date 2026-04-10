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

        $cronResult = $this->ensureAutomationCronJobs();
        if ($cronResult['ok']) {
            $output->writeln('<info>[PASS] automation cron jobs ensured.</info>');
        } else {
            $warnings[] = 'automation cron jobs';
            $output->writeln('<comment>[WARN] automation cron jobs not installed: ' . $cronResult['message'] . '</comment>');
        }

        $output->writeln('');
        if ($warnings !== []) {
            $output->writeln('<comment>SUMMARY: PASS with warnings (' . count($warnings) . ')</comment>');
            return 2;
        }

        $output->writeln('<info>SUMMARY: PASS</info>');

        return 0;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function ensureAutomationCronJobs(): array
    {
        $logDir = $this->projectDir . '/var/log';
        if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            return ['ok' => false, 'message' => 'cannot create var/log directory'];
        }

        $cronDir = $this->projectDir . '/srv/setup/cron';
        if (!is_dir($cronDir) && !mkdir($cronDir, 0775, true) && !is_dir($cronDir)) {
            return ['ok' => false, 'message' => 'cannot create srv/setup/cron directory'];
        }

        $escapedProjectDir = str_replace("'", "'\"'\"'", $this->projectDir);
        $cronBlock = implode("\n", [
            '# BEGIN EASYWI_AUTOMATION',
            'SHELL=/bin/sh',
            'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            sprintf('*/5 * * * * cd \'%s\' && php bin/console app:update:auto --no-interaction >> var/log/cron-update-auto.log 2>&1', $escapedProjectDir),
            sprintf('*/5 * * * * cd \'%s\' && php bin/console app:run-schedules --no-interaction >> var/log/cron-run-schedules.log 2>&1', $escapedProjectDir),
            '# END EASYWI_AUTOMATION',
            '',
        ]);

        $snapshotPath = $cronDir . '/easywi-automation.cron';
        if (file_put_contents($snapshotPath, $cronBlock) === false) {
            return ['ok' => false, 'message' => 'cannot write cron snapshot file'];
        }

        $whichCrontab = new Process(['sh', '-lc', 'command -v crontab']);
        $whichCrontab->setTimeout(10);
        $whichCrontab->run();
        if (!$whichCrontab->isSuccessful()) {
            return ['ok' => false, 'message' => 'crontab command not available'];
        }

        $existing = '';
        $read = new Process(['crontab', '-l']);
        $read->setTimeout(10);
        $read->run();
        if ($read->isSuccessful()) {
            $existing = $read->getOutput();
        }

        $existing = preg_replace('/\n?# BEGIN EASYWI_AUTOMATION.*?# END EASYWI_AUTOMATION\n?/s', "\n", $existing) ?? $existing;
        $existing = rtrim($existing) . "\n\n" . $cronBlock;

        $tmp = tempnam(sys_get_temp_dir(), 'easywi-cron-');
        if (!is_string($tmp)) {
            return ['ok' => false, 'message' => 'cannot allocate temp file for crontab'];
        }

        if (file_put_contents($tmp, $existing) === false) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'cannot write temp crontab'];
        }

        $install = new Process(['crontab', $tmp]);
        $install->setTimeout(10);
        $install->run();
        @unlink($tmp);
        if (!$install->isSuccessful()) {
            return ['ok' => false, 'message' => trim($install->getErrorOutput()) !== '' ? trim($install->getErrorOutput()) : 'crontab install failed'];
        }

        return ['ok' => true, 'message' => 'installed'];
    }
}
