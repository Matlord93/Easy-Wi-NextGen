<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\UpdateJobService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:diagnose:update',
    description: 'Diagnose update runner, directories, and migration status.',
)]
final class UpdateDiagnoseCommand extends Command
{
    public function __construct(
        private readonly UpdateJobService $updateJobService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Update diagnostics');

        $io->section('Directories');
        $this->renderDirStatus($io, 'Jobs', $this->updateJobService->getJobsDir());
        $this->renderDirStatus($io, 'Logs', $this->updateJobService->getLogsDir());
        $this->renderDirStatus($io, 'Backups', $this->updateJobService->getBackupsDir());

        $io->section('Runner');
        $runnerCommand = trim((string) ($_SERVER['APP_CORE_UPDATE_RUNNER'] ?? $_ENV['APP_CORE_UPDATE_RUNNER'] ?? ''));
        if ($runnerCommand === '') {
            $runnerCommand = '/usr/local/bin/easywi-core-runner';
        }
        $runnerPath = $this->resolveRunnerPath($runnerCommand);
        if ($runnerPath === null) {
            $io->warning(sprintf('Runner not found: %s', $runnerCommand));
        } else {
            $io->success(sprintf('Runner available: %s', $runnerPath));
        }

        $io->section('Latest job');
        $latestJob = $this->updateJobService->getLatestJob();
        if ($latestJob === null) {
            $io->text('No jobs found.');
        } else {
            $io->listing([
                sprintf('ID: %s', $latestJob['id'] ?? 'n/a'),
                sprintf('Type: %s', $latestJob['type'] ?? 'n/a'),
                sprintf('Status: %s', $latestJob['status'] ?? 'n/a'),
                sprintf('Started: %s', $latestJob['startedAt'] ?? 'n/a'),
                sprintf('Finished: %s', $latestJob['finishedAt'] ?? 'n/a'),
                sprintf('Exit: %s', $latestJob['exitCode'] ?? 'n/a'),
            ]);
        }

        $io->section('Migrations');
        $migrationStatus = $this->updateJobService->getMigrationStatus();
        if ($migrationStatus['error'] !== null) {
            $io->warning(sprintf('Migration status error: %s', $migrationStatus['error']));
        } else {
            $io->text(sprintf('Pending: %d', (int) $migrationStatus['pending']));
            $io->text(sprintf('Executed unavailable: %d', (int) $migrationStatus['executedUnavailable']));
        }

        return Command::SUCCESS;
    }

    private function renderDirStatus(SymfonyStyle $io, string $label, string $path): void
    {
        $exists = is_dir($path);
        $writable = $exists && is_writable($path);
        $io->text(sprintf('%s: %s (%s)', $label, $path, $writable ? 'writable' : 'not writable'));
    }

    private function resolveRunnerPath(string $runnerCommand): ?string
    {
        if ($runnerCommand === '') {
            return null;
        }

        if (str_starts_with($runnerCommand, '/')) {
            return is_file($runnerCommand) && is_executable($runnerCommand) ? $runnerCommand : null;
        }

        $resolved = trim((string) shell_exec('command -v ' . escapeshellarg($runnerCommand) . ' 2>/dev/null'));
        return $resolved !== '' ? $resolved : null;
    }
}
