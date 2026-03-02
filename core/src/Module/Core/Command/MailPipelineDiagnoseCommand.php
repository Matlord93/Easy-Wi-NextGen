<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\Mailbox;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Repository\AgentRepository;
use App\Repository\JobRepository;
use App\Repository\MailboxRepository;
use App\Repository\MailDomainRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:diagnose:mail-pipeline',
    description: 'Diagnose mail provisioning flow between Panel queue and Go agent execution.',
)]
final class MailPipelineDiagnoseCommand extends Command
{
    private const MAIL_JOB_PREFIXES = ['mail.', 'mailbox.', 'roundcube.'];

    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly JobRepository $jobRepository,
        private readonly MailDomainRepository $mailDomainRepository,
        private readonly MailboxRepository $mailboxRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Mail pipeline diagnostics (Symfony Panel ↔ Go Agent)');

        try {
            return $this->runDiagnostics($io);
        } catch (\Throwable $exception) {
            $io->error(sprintf('Mail pipeline diagnostics failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }
    }

    private function runDiagnostics(SymfonyStyle $io): int
    {
        $hasAdvisories = false;
        $hasOperationalIssues = false;
        $hasBlockingProvisioningIssues = false;

        $mailDomainCount = $this->mailDomainRepository->count([]);
        $mailboxCount = $this->mailboxRepository->count([]);
        $mailboxDomainCount = $this->countDistinctMailboxDomains();
        $derivedMailboxDomains = $this->listDerivedMailboxDomains(5);
        $unboundMailboxDomains = $this->listUnboundMailboxDomains(10);

        $io->section('Mail inventory');
        $io->text(sprintf('Mail domains (bindings): %d', $mailDomainCount));
        $io->text(sprintf('Mailbox domains (derived): %d', $mailboxDomainCount));
        $io->text(sprintf('Mailboxes: %d', $mailboxCount));

        if ($mailDomainCount === 0 && $mailboxCount === 0) {
            $io->warning('No mail-enabled domains or mailboxes found. Provisioning jobs cannot be dispatched yet.');
        }

        if ($unboundMailboxDomains !== []) {
            $io->warning('Mailbox domains exist without mail-domain bindings. This is a blocking provisioning inconsistency for full mail platform setup.');
            $io->text(sprintf('Affected mailbox domain(s): %s', implode(', ', $unboundMailboxDomains)));
            foreach ($this->buildDomainBindingHints($unboundMailboxDomains) as $hint) {
                $io->text($hint);
            }
            $io->text('Action: bind affected domain(s) to mail platform in panel, then re-save mailbox/domain to trigger fresh mailbox.* and mail.* apply jobs.');
            $hasBlockingProvisioningIssues = true;
        } elseif ($mailboxCount > 0 && $derivedMailboxDomains !== []) {
            $io->success('All derived mailbox domains have a mail-domain binding.');
        }

        if ($mailboxCount === 0) {
            $io->warning('No mailboxes found. If you just created one in the panel, check form submission and validation logs.');
        }

        $io->section('Agent connectivity');
        $agents = $this->agentRepository->findAll();
        if ($agents === []) {
            $io->error('No agents registered. Mail jobs cannot be executed on nodes.');
            $hasOperationalIssues = true;
        } else {
            $now = new \DateTimeImmutable();
            $offlineAgents = 0;
            foreach ($agents as $agent) {
                if (!$agent instanceof Agent) {
                    continue;
                }

                $heartbeat = $agent->getLastHeartbeatAt();
                $secondsSinceHeartbeat = $heartbeat === null ? null : $now->getTimestamp() - $heartbeat->getTimestamp();
                if ($secondsSinceHeartbeat === null || $secondsSinceHeartbeat > 600) {
                    $offlineAgents++;
                }
            }

            if ($offlineAgents > 0) {
                $io->warning(sprintf('%d/%d agent(s) have missing or stale heartbeat (> 10 min).', $offlineAgents, count($agents)));
                $hasOperationalIssues = true;
            } else {
                $io->success(sprintf('All %d agent(s) report recent heartbeat.', count($agents)));
            }
        }

        $io->section('Mail job queue status');
        $mailJobsByStatus = $this->countMailJobsByStatus();
        foreach ($mailJobsByStatus as $status => $count) {
            $io->text(sprintf('%s: %d', $status, $count));
        }

        $mailApplyHistoryCount = $this->countMailApplyHistory();
        $io->text(sprintf('mail.* apply/history jobs: %d', $mailApplyHistoryCount));
        if ($mailboxCount > 0 && $mailApplyHistoryCount === 0) {
            $io->warning('Mailbox jobs exist but no mail.* apply/history jobs were found. Domain binding/apply flow is likely incomplete.');
            $hasBlockingProvisioningIssues = true;
        }

        if (($mailJobsByStatus[JobStatus::Failed->value] ?? 0) > 0) {
            $io->warning('There are failed mail jobs. Review latest failed jobs and re-trigger from panel after fixing root cause.');
            $hasOperationalIssues = true;
        }

        $oldestQueuedAge = $this->oldestQueuedMailJobAgeSeconds();
        if ($oldestQueuedAge !== null && $oldestQueuedAge > 300) {
            $io->warning(sprintf('Oldest queued mail job is %d seconds old. Worker/agent processing may be stalled.', $oldestQueuedAge));
            $hasOperationalIssues = true;
        } else {
            $io->success('No stale queued mail jobs detected.');
        }

        $latestMailJobs = $this->latestMailJobs(5);
        if ($latestMailJobs !== []) {
            $io->section('Latest mail-related jobs');
            $rows = [];
            foreach ($latestMailJobs as $job) {
                $rows[] = [
                    $job->getId(),
                    $job->getType(),
                    $job->getStatus()->value,
                    (string) $job->getAttempts(),
                    $job->getUpdatedAt()->format(DATE_ATOM),
                    $job->getLastErrorCode() ?? '',
                ];
            }
            $io->table(['ID', 'Type', 'Status', 'Attempts', 'Updated', 'ErrorCode'], $rows);
        }

        $io->section('Recommended re-trigger sequence');
        $io->listing([
            '1) Ensure worker is running: php bin/console messenger:consume async --time-limit=3600',
            '2) Ensure easywi-agent service is online and heartbeating.',
            '3) Re-save affected mailbox/domain in panel to enqueue a fresh mail.* / mailbox.* job.',
            '4) Check latest failed jobs and logs (panel + dovecot + postfix) before retry.',
        ]);

        $io->section('Diagnosis result');
        if ($hasBlockingProvisioningIssues) {
            $io->error('Mail pipeline has blocking provisioning inconsistencies. Bind affected domains and re-trigger apply jobs.');
        } elseif ($hasOperationalIssues) {
            $io->warning('Mail pipeline has operational issues. Resolve warnings above and re-run diagnostics.');
        } elseif ($hasAdvisories) {
            $io->note('Mail pipeline is operational, but advisory inconsistencies were detected.');
        } else {
            $io->success('Mail pipeline looks consistent.');
        }

        return ($hasBlockingProvisioningIssues || $hasOperationalIssues) ? Command::FAILURE : Command::SUCCESS;
    }

    private function countDistinctMailboxDomains(): int
    {
        $value = $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT IDENTITY(m.domain))')
            ->from(Mailbox::class, 'm')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    private function listDerivedMailboxDomains(int $limit): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('d.name AS domain_name')
            ->from(Mailbox::class, 'm')
            ->join(Domain::class, 'd', 'WITH', 'd = m.domain')
            ->groupBy('d.id, d.name')
            ->orderBy('d.name', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getArrayResult();

        $names = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['domain_name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function listUnboundMailboxDomains(int $limit): array
    {
        $derived = $this->listDerivedMailboxDomains(max(1, $limit * 3));
        if ($derived === []) {
            return [];
        }

        $bound = [];
        foreach ($this->mailDomainRepository->findAll() as $mailDomain) {
            $bound[] = strtolower($mailDomain->getDomain()->getName());
        }
        $bound = array_values(array_unique($bound));

        $unbound = [];
        foreach ($derived as $domain) {
            if (!in_array(strtolower($domain), $bound, true)) {
                $unbound[] = $domain;
            }
        }

        return array_slice($unbound, 0, max(1, $limit));
    }

    /**
     * @param list<string> $domains
     * @return list<string>
     */
    private function buildDomainBindingHints(array $domains): array
    {
        $hints = [];
        foreach ($domains as $domainName) {
            $domain = $this->entityManager->createQueryBuilder()
                ->select('d')
                ->from(Domain::class, 'd')
                ->where('LOWER(d.name) = :name')
                ->setParameter('name', strtolower($domainName))
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if (!$domain instanceof Domain) {
                $hints[] = sprintf('Hint: Domain "%s" is unknown in panel domain table; create/import the web domain first.', $domainName);
                continue;
            }

            $hints[] = sprintf('Hint: Bind domain "%s" in panel Mail Platform (domain_id=%d).', $domainName, $domain->getId());
        }

        return $hints;
    }

    private function countMailApplyHistory(): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb
            ->select('COUNT(job.id)')
            ->from(Job::class, 'job')
            ->where('job.type LIKE :mailApplyPrefix')
            ->setParameter('mailApplyPrefix', 'mail.%');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<string, int>
     */
    private function countMailJobsByStatus(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb
            ->select('job.status AS status', 'COUNT(job.id) AS total')
            ->from(Job::class, 'job')
            ->where($qb->expr()->orX(...$this->mailTypePredicates($qb)))
            ->groupBy('job.status');

        $rows = $qb->getQuery()->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $status = $this->normalizeStatusKey($row['status'] ?? null);
            $counts[$status] = (int) ($row['total'] ?? 0);
        }

        foreach (JobStatus::cases() as $status) {
            $counts[$status->value] = $counts[$status->value] ?? 0;
        }

        ksort($counts);

        return $counts;
    }


    private function normalizeStatusKey(mixed $status): string
    {
        if ($status instanceof JobStatus) {
            return $status->value;
        }

        if (is_string($status) && $status !== '') {
            return $status;
        }

        if (is_scalar($status)) {
            return (string) $status;
        }

        return 'unknown';
    }

    private function oldestQueuedMailJobAgeSeconds(): ?int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb
            ->select('job')
            ->from(Job::class, 'job')
            ->where('job.status = :status')
            ->andWhere($qb->expr()->orX(...$this->mailTypePredicates($qb)))
            ->setParameter('status', JobStatus::Queued)
            ->orderBy('job.createdAt', 'ASC')
            ->setMaxResults(1);

        $job = $qb->getQuery()->getOneOrNullResult();
        if (!$job instanceof Job) {
            return null;
        }

        return max(0, (new \DateTimeImmutable())->getTimestamp() - $job->getCreatedAt()->getTimestamp());
    }

    /**
     * @return Job[]
     */
    private function latestMailJobs(int $limit): array
    {
        $jobs = $this->jobRepository->findLatest(max(1, $limit * 8));
        $mailJobs = [];
        foreach ($jobs as $job) {
            foreach (self::MAIL_JOB_PREFIXES as $prefix) {
                if (str_starts_with($job->getType(), $prefix)) {
                    $mailJobs[] = $job;
                    break;
                }
            }

            if (count($mailJobs) >= $limit) {
                break;
            }
        }

        return $mailJobs;
    }

    /**
     * @return list<string>
     */
    private function mailTypePredicates($qb): array
    {
        $predicates = [];
        foreach (self::MAIL_JOB_PREFIXES as $index => $prefix) {
            $param = sprintf('mailPrefix%d', $index);
            $predicates[] = sprintf('job.type LIKE :%s', $param);
            $qb->setParameter($param, sprintf('%s%%', $prefix));
        }

        return $predicates;
    }
}
