<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\AgentReleaseChecker;
use App\Module\Core\Application\ChangelogFetcher;
use App\Module\Core\Application\CoreReleaseChecker;
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
        private readonly CoreReleaseChecker $coreReleaseChecker,
        private readonly AgentReleaseChecker $agentReleaseChecker,
        private readonly ChangelogFetcher $changelogFetcher,
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

        $io->section('Panel permissions and Git');
        $projectDir = dirname(__DIR__, 4);
        $this->renderDirStatus($io, 'Panel', $projectDir);
        $io->text(sprintf('PHP/Composer user: %s', $this->effectiveUser()));
        $mountOptions = trim((string) shell_exec('findmnt -T ' . escapeshellarg($projectDir) . ' -n -o OPTIONS 2>/dev/null'));
        $io->text(sprintf('Mount options: %s', $mountOptions !== '' ? $mountOptions : 'unavailable'));
        if (in_array('noexec', explode(',', $mountOptions), true)) {
            $io->warning('Panel path is mounted noexec; updater/helper execution can be blocked by the image policy.');
        }
        $this->renderGitStatus($io, $projectDir);

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


        $io->section('GitHub update-check cache');
        $io->text(sprintf('APP_UPDATE_CHECK_CACHE_TTL: %s', (string) ($_SERVER['APP_UPDATE_CHECK_CACHE_TTL'] ?? $_ENV['APP_UPDATE_CHECK_CACHE_TTL'] ?? '3600')));
        $io->text(sprintf('Token vorhanden: %s', $this->tokenPresent() ? 'ja' : 'nein'));
        $this->renderCacheStatus($io, 'Core', $this->coreReleaseChecker->getCacheStatus());
        $this->renderCacheStatus($io, 'Agent', $this->agentReleaseChecker->getCacheStatus());
        $this->renderCacheStatus($io, 'Changelog', $this->changelogFetcher->getCacheStatus());

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

    /** @param array<string, mixed> $status */
    private function renderCacheStatus(SymfonyStyle $io, string $label, array $status): void
    {
        $io->text(sprintf('%s Cache vorhanden: %s', $label, ($status['has_cache'] ?? false) ? 'ja' : 'nein'));
        $io->listing([
            sprintf('Repository: %s', (string) ($status['repository'] ?? 'n/a')),
            sprintf('Channel: %s', (string) ($status['channel'] ?? 'n/a')),
            sprintf('Check type: %s', (string) ($status['check_type'] ?? 'n/a')),
            sprintf('Letzter erfolgreicher Fetch: %s', $this->formatTimestamp($status['last_success_at'] ?? $status['fetched_at'] ?? null)),
            sprintf('Cache gültig bis: %s', $this->formatTimestamp($status['expires_at'] ?? null)),
            sprintf('Nächster automatischer Check: %s', $this->formatTimestamp($status['next_check_at'] ?? null)),
            sprintf('Repository visibility: %s', (string) ($status['repository_visibility'] ?? 'unknown')),
            sprintf('Letzter GitHub HTTP Status: %s', (string) ($status['last_http_status'] ?? 'n/a')),
            sprintf('Rate-Limit remaining: %s', (string) ($status['rate_limit_remaining'] ?? 'n/a')),
            sprintf('Rate-Limit-Reset: %s', $this->formatTimestamp($status['rate_limit_reset'] ?? null)),
            sprintf('Letzter Fehler-Typ: %s', (string) ($status['last_error_type'] ?? 'keiner')),
            sprintf('Letzte GitHub-Fehlermeldung: %s', (string) ($status['last_error'] ?? 'keine')),
        ]);
    }

    private function formatTimestamp(mixed $timestamp): string
    {
        if (!is_int($timestamp) && !(is_string($timestamp) && ctype_digit($timestamp))) {
            return 'n/a';
        }

        $value = (int) $timestamp;
        return $value > 0 ? date(DATE_ATOM, $value) : 'n/a';
    }

    private function tokenPresent(): bool
    {
        return trim((string) ($_SERVER['APP_GITHUB_TOKEN'] ?? $_ENV['APP_GITHUB_TOKEN'] ?? $_SERVER['GITHUB_TOKEN'] ?? $_ENV['GITHUB_TOKEN'] ?? '')) !== '';
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

    private function effectiveUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $account = posix_getpwuid(posix_geteuid());
            if (is_array($account) && isset($account['name'])) {
                return (string) $account['name'];
            }
        }

        return get_current_user() ?: 'unknown';
    }

    private function renderGitStatus(SymfonyStyle $io, string $projectDir): void
    {
        $gitDir = $projectDir . '/.git';
        if (!is_dir($gitDir)) {
            $io->text('Git: release installation (no .git directory).');
            return;
        }

        $owner = function_exists('posix_getpwuid') ? posix_getpwuid((int) fileowner($projectDir)) : false;
        $io->text(sprintf('Repository owner: %s', is_array($owner) ? (string) ($owner['name'] ?? 'unknown') : (string) fileowner($projectDir)));
        $safeDirectories = trim((string) shell_exec('git config --global --get-all safe.directory 2>/dev/null'));
        $isSafe = in_array($projectDir, preg_split('/\R/', $safeDirectories) ?: [], true);
        $io->text(sprintf('safe.directory: %s', $isSafe ? 'configured' : 'not configured for this user'));
        $output = [];
        $exitCode = 0;
        exec('git -C ' . escapeshellarg($projectDir) . ' status --porcelain=v1 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            $io->warning('Git status failed: ' . implode(' ', $output) . '. Check repository owner and safe.directory.');
        } else {
            $io->text(sprintf('Git worktree: %s', $output === [] ? 'clean' : 'modified'));
        }
    }
}
