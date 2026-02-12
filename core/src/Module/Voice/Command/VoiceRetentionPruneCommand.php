<?php

declare(strict_types=1);

namespace App\Module\Voice\Command;

use App\Module\Core\Domain\Enum\JobStatus;
use App\Repository\VoiceRateLimitStateRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:voice:prune-retention', description: 'Prune old voice rate-limit states and old terminal voice jobs/results.')]
final class VoiceRetentionPruneCommand extends Command
{
    public function __construct(
        private readonly VoiceRateLimitStateRepository $stateRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention in days.', '14');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getOption('days'));
        $threshold = new \DateTimeImmutable(sprintf('-%d days', $days));

        $prunedStates = $this->stateRepository->pruneOlderThan($threshold);

        $conn = $this->entityManager->getConnection();
        $terminal = [JobStatus::Succeeded->value, JobStatus::Failed->value, JobStatus::Cancelled->value];

        $jobIds = $conn->fetchFirstColumn(
            'SELECT id FROM jobs WHERE type LIKE :typePrefix AND status IN (:statuses) AND created_at < :threshold LIMIT 1000',
            [
                'typePrefix' => 'voice.%',
                'statuses' => $terminal,
                'threshold' => $threshold,
            ],
            [
                'statuses' => ArrayParameterType::STRING,
                'threshold' => Types::DATETIME_IMMUTABLE,
            ],
        );

        $prunedJobResults = 0;
        $prunedJobs = 0;
        if ($jobIds !== []) {
            $prunedJobResults = $conn->executeStatement(
                'DELETE FROM job_results WHERE job_id IN (:jobIds)',
                ['jobIds' => $jobIds],
                ['jobIds' => ArrayParameterType::STRING],
            );
            $prunedJobs = $conn->executeStatement(
                'DELETE FROM jobs WHERE id IN (:jobIds)',
                ['jobIds' => $jobIds],
                ['jobIds' => ArrayParameterType::STRING],
            );
        }

        $io->success(sprintf('Pruned voice retention: states=%d, job_results=%d, jobs=%d (older than %d days).', $prunedStates, $prunedJobResults, $prunedJobs, $days));

        return Command::SUCCESS;
    }
}
