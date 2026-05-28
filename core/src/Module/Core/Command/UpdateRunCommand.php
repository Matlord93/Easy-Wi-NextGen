<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\UpdateJobServiceInterface;
use App\Module\Setup\Application\WebinterfaceUpdateServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:update:run',
    description: 'Runs a queued webinterface update job immediately.',
)]
final class UpdateRunCommand extends Command
{
    public function __construct(
        private readonly UpdateJobServiceInterface $updateJobService,
        private readonly WebinterfaceUpdateServiceInterface $updateService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('job-id', InputArgument::REQUIRED, 'Update job id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobId = (string) $input->getArgument('job-id');
        $job = $this->updateJobService->getJob($jobId);
        if ($job === null) {
            $output->writeln('Job not found.');
            return Command::FAILURE;
        }

        if (($job['status'] ?? 'pending') !== 'pending') {
            $output->writeln('Job already processed.');
            return Command::SUCCESS;
        }

        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $job['status'] = 'running';
        $job['startedAt'] = $now;
        $jobPath = rtrim($this->updateJobService->getJobsDir(), '/') . '/' . $jobId . '.json';
        file_put_contents($jobPath, (string) json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $type = (string) ($job['type'] ?? 'update');
        $job['lastStep'] = 'dispatch';
        file_put_contents($jobPath, (string) json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $result = $this->runJobByType($type, $job, $jobPath);

        $job = $this->updateJobService->getJob($jobId) ?? $job;
        $job['status'] = $result->success ? 'success' : 'failed';
        $job['finishedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $job['exitCode'] = $result->success ? 0 : 1;
        $job['logPath'] = $result->logPath ?? ($job['logPath'] ?? null);
        if (!$result->success && $result->error !== null) {
            $job['error'] = $result->error;
        }
        file_put_contents($jobPath, (string) json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return $result->success ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array<string,mixed> $job
     */
    private function runJobByType(string $type, array $job, string $jobPath): \App\Module\Core\Update\UpdateResult
    {
        return match ($type) {
            'update' => $this->runStep($job, $jobPath, 'update', fn () => $this->updateService->applyUpdate()),
            'migrate' => $this->runStep($job, $jobPath, 'migrate', fn () => $this->updateService->applyMigrations()),
            'both' => $this->runBoth($job, $jobPath),
            'rollback' => new \App\Module\Core\Update\UpdateResult(false, 'Rollback ist aktuell nicht implementiert.', 'rollback_not_implemented', $job['logPath'] ?? null, null, null),
            default => new \App\Module\Core\Update\UpdateResult(false, 'Unbekannter Job-Typ.', 'unknown_job_type: ' . $type, $job['logPath'] ?? null, null, null),
        };
    }

    /** @param array<string,mixed> $job */
    private function runBoth(array $job, string $jobPath): \App\Module\Core\Update\UpdateResult
    {
        $updateResult = $this->runStep($job, $jobPath, 'update', fn () => $this->updateService->applyUpdate());
        if (!$updateResult->success) {
            return new \App\Module\Core\Update\UpdateResult(false, 'Update fehlgeschlagen, Migration wurde nicht gestartet.', $updateResult->error ?? 'update_failed', $updateResult->logPath, $updateResult->currentVersion, $updateResult->latestVersion);
        }

        return $this->runStep($job, $jobPath, 'migrate', fn () => $this->updateService->applyMigrations());
    }

    /**
     * @param array<string,mixed> $job
     * @param callable():\App\Module\Core\Update\UpdateResult $callback
     */
    private function runStep(array $job, string $jobPath, string $step, callable $callback): \App\Module\Core\Update\UpdateResult
    {
        $job['lastStep'] = $step;
        file_put_contents($jobPath, (string) json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $callback();
    }
}
