<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Job;
use App\Enum\InstanceScheduleAction;
use App\Enum\InstanceUpdatePolicy;
use App\Repository\InstanceScheduleRepository;
use App\Service\AuditLogger;
use App\Service\InstanceJobPayloadBuilder;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'instance:update:queue',
    description: 'Queue scheduled instance updates for auto-update policies.',
)]
final class InstanceUpdateQueueCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private readonly InstanceScheduleRepository $instanceScheduleRepository,
        private readonly InstanceJobPayloadBuilder $instanceJobPayloadBuilder,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $schedules = $this->instanceScheduleRepository->findBy(['action' => InstanceScheduleAction::Update], ['id' => 'ASC'], self::DEFAULT_BATCH_SIZE);
        $queued = 0;

        foreach ($schedules as $schedule) {
            if (!$schedule->isEnabled()) {
                continue;
            }

            $instance = $schedule->getInstance();
            if ($instance->getUpdatePolicy() !== InstanceUpdatePolicy::Auto) {
                continue;
            }

            $cronExpression = $schedule->getCronExpression();
            if (!CronExpression::isValidExpression($cronExpression)) {
                continue;
            }

            $timeZone = $schedule->getTimeZone() ?? 'UTC';
            $timeZoneObj = new \DateTimeZone($timeZone);
            $nowLocal = $now->setTimezone($timeZoneObj);
            $cron = CronExpression::factory($cronExpression);
            $previousRun = $cron->getPreviousRunDate($nowLocal, 0, true);

            $lastQueuedAt = $instance->getLastUpdateQueuedAt();
            if ($lastQueuedAt !== null && $lastQueuedAt->setTimezone($timeZoneObj) >= $previousRun) {
                continue;
            }

            $job = new Job('sniper.update', $this->instanceJobPayloadBuilder->buildSniperUpdatePayload(
                $instance,
                $instance->getLockedBuildId(),
                $instance->getLockedVersion(),
            ));
            $this->entityManager->persist($job);
            $instance->setLastUpdateQueuedAt($now);
            $this->entityManager->persist($instance);

            $this->auditLogger->log(null, 'instance.update.queued', [
                'instance_id' => $instance->getId(),
                'job_id' => $job->getId(),
                'policy' => $instance->getUpdatePolicy()->value,
                'cron_expression' => $cronExpression,
                'time_zone' => $timeZone,
                'source' => 'auto.schedule',
            ]);

            $queued++;
        }

        $this->entityManager->flush();

        $output->writeln(sprintf('Queued %d instance update(s).', $queued));

        return Command::SUCCESS;
    }
}
