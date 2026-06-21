<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\AgentOrchestrator\Application\AgentJobValidator;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotScheduleDispatcherInterface;
use App\Module\Musicbot\Application\MusicbotScheduleService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotSchedule;
use App\Module\Musicbot\Domain\Enum\MusicbotScheduleAction;
use App\Repository\MusicbotScheduleRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MusicbotScheduleServiceTest extends TestCase
{
    private User $customer;
    private MusicbotInstance $instance;

    protected function setUp(): void
    {
        $this->customer = new User('customer@example.test', UserType::Customer);
        $this->setId($this->customer, 1);
        $agent = new Agent('agent-1', ['token' => 'hash'], 'Test Agent');
        $this->instance = new MusicbotInstance($this->customer, $agent, 'TestBot', 'testbot-abc123', '/srv/musicbot/testbot');
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }

    // ── Pure computation tests (no injected deps called) ─────────────────────

    public function testValidCronExpressionIsAccepted(): void
    {
        $service = $this->makeServiceViaReflection();
        self::assertInstanceOf(\DateTimeImmutable::class, $service->calcNextRunAt('0 * * * *', 'UTC'));
    }

    public function testCalcNextRunAtReturnsNullForInvalidCron(): void
    {
        $service = $this->makeServiceViaReflection();
        self::assertNull($service->calcNextRunAt('not-valid-cron', 'UTC'));
    }

    public function testCalcNextRunAtReturnsUtcResult(): void
    {
        $service = $this->makeServiceViaReflection();
        $next = $service->calcNextRunAt('0 12 * * *', 'Europe/Berlin');

        self::assertInstanceOf(\DateTimeImmutable::class, $next);
        self::assertSame('UTC', $next->getTimezone()->getName());
    }

    public function testNormalizeReturnsAllRequiredFields(): void
    {
        $schedule = $this->makeSchedule();
        $service = $this->makeServiceViaReflection();

        $normalized = $service->normalize($schedule);

        foreach (['id', 'name', 'cron_expression', 'timezone', 'enabled', 'action', 'payload', 'last_run_at', 'next_run_at', 'last_error', 'created_at', 'updated_at', 'instance_id'] as $key) {
            self::assertArrayHasKey($key, $normalized, "Missing key: $key");
        }
        self::assertSame('status_check', $normalized['action']);
        self::assertSame([], $normalized['payload']);
    }

    // ── Entity state tests ────────────────────────────────────────────────────

    public function testMarkExecutedUpdatesTimestampAndClearsError(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->markFailed(new \DateTimeImmutable('2025-01-01'), 'previous error', null);

        $now = new \DateTimeImmutable('2025-06-01 12:00:00');
        $schedule->markExecuted($now, new \DateTimeImmutable('2025-06-01 13:00:00'));

        self::assertSame($now, $schedule->getLastRunAt());
        self::assertNull($schedule->getLastError());
        self::assertNotNull($schedule->getNextRunAt());
    }

    public function testMarkFailedRecordsErrorAndTimestamp(): void
    {
        $schedule = $this->makeSchedule();
        $now = new \DateTimeImmutable('2025-06-01 12:00:00');
        $schedule->markFailed($now, 'Connection refused.', null);

        self::assertSame($now, $schedule->getLastRunAt());
        self::assertSame('Connection refused.', $schedule->getLastError());
        self::assertNull($schedule->getNextRunAt());
    }

    public function testSetEnabledUpdatesFlag(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->setEnabled(false);
        self::assertFalse($schedule->isEnabled());
        $schedule->setEnabled(true);
        self::assertTrue($schedule->isEnabled());
    }

    public function testScheduleUpdateChangesFields(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->update('New Name', '30 8 * * 1', 'Europe/Berlin', false, MusicbotScheduleAction::ClearQueue, ['playlist_id' => '7']);

        self::assertSame('New Name', $schedule->getName());
        self::assertSame('30 8 * * 1', $schedule->getCronExpression());
        self::assertSame('Europe/Berlin', $schedule->getTimezone());
        self::assertFalse($schedule->isEnabled());
        self::assertSame(MusicbotScheduleAction::ClearQueue, $schedule->getAction());
        self::assertSame(['playlist_id' => '7'], $schedule->getPayload());
    }

    // ── Validation tests ─────────────────────────────────────────────────────

    public function testInvalidCronExpressionRejectsCreate(): void
    {
        $service = $this->makeServiceWithMocks();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invalid cron/i');

        $service->create($this->customer, $this->instance, 'Bad Schedule', 'not-a-cron', 'UTC', true, MusicbotScheduleAction::StatusCheck, []);
    }

    public function testInvalidTimezoneRejectsCreate(): void
    {
        $service = $this->makeServiceWithMocks();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invalid timezone/i');

        $service->create($this->customer, $this->instance, 'Bad TZ', '0 * * * *', 'Mars/Olympus', true, MusicbotScheduleAction::StatusCheck, []);
    }

    // ── Ownership tests ───────────────────────────────────────────────────────

    public function testOwnershipEnforcedOnDelete(): void
    {
        $otherCustomer = new User('other@example.test', UserType::Customer);
        $this->setId($otherCustomer, 99);
        $schedule = $this->makeSchedule();
        $service = $this->makeServiceViaReflection();

        $this->expectException(\RuntimeException::class);
        $service->delete($otherCustomer, $schedule);
    }

    public function testOwnershipEnforcedOnToggle(): void
    {
        $otherCustomer = new User('other@example.test', UserType::Customer);
        $this->setId($otherCustomer, 99);
        $schedule = $this->makeSchedule();
        $service = $this->makeServiceViaReflection();

        $this->expectException(\RuntimeException::class);
        $service->toggle($otherCustomer, $schedule, false);
    }

    public function testOwnershipEnforcedOnUpdate(): void
    {
        $otherCustomer = new User('other@example.test', UserType::Customer);
        $this->setId($otherCustomer, 99);
        $schedule = $this->makeSchedule();
        $service = $this->makeServiceViaReflection();

        $this->expectException(\RuntimeException::class);
        $service->update($otherCustomer, $schedule, ['name' => 'Hacked']);
    }

    // ── runDue tests (using MusicbotScheduleDispatcherInterface which is mockable) ──

    public function testRunDueDispatchesJobAndUpdatesSchedule(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->setNextRunAt(new \DateTimeImmutable('2025-01-01 00:00:00'));

        $jobMock = $this->createMock(AgentJob::class);
        $jobMock->method('getId')->willReturn('job-abc');

        $scheduleDispatcher = $this->createMock(MusicbotScheduleDispatcherInterface::class);
        $scheduleDispatcher->expects($this->once())->method('dispatch')->willReturn($jobMock);

        $scheduleRepository = $this->createMock(MusicbotScheduleRepository::class);
        $scheduleRepository->method('findDue')->willReturn([$schedule]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = $this->makeServiceViaReflection($scheduleDispatcher, $scheduleRepository, $em, true);
        $jobIds = $service->runDue(new \DateTimeImmutable());

        self::assertSame(['job-abc'], $jobIds);
        self::assertNull($schedule->getLastError());
        self::assertNotNull($schedule->getLastRunAt());
    }

    public function testRunDueMarksScheduleFailedOnDispatchError(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->setNextRunAt(new \DateTimeImmutable('2025-01-01 00:00:00'));

        $scheduleDispatcher = $this->createMock(MusicbotScheduleDispatcherInterface::class);
        $scheduleDispatcher->method('dispatch')->willThrowException(new \RuntimeException('Agent unreachable.'));

        $scheduleRepository = $this->createMock(MusicbotScheduleRepository::class);
        $scheduleRepository->method('findDue')->willReturn([$schedule]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = $this->makeServiceViaReflection($scheduleDispatcher, $scheduleRepository, $em, true);
        $jobIds = $service->runDue(new \DateTimeImmutable());

        self::assertSame([], $jobIds);
        self::assertStringContainsString('Agent unreachable.', (string) $schedule->getLastError());
    }

    public function testRunDueReturnsEmptyWhenNoSchedulesDue(): void
    {
        $scheduleRepository = $this->createMock(MusicbotScheduleRepository::class);
        $scheduleRepository->method('findDue')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $service = $this->makeServiceViaReflection(null, $scheduleRepository, $em);
        $jobIds = $service->runDue(new \DateTimeImmutable());

        self::assertSame([], $jobIds);
    }

    // ── AgentJobValidator test ────────────────────────────────────────────────

    public function testScheduleActionJobTypeRequiresScheduleId(): void
    {
        $validator = new AgentJobValidator();

        self::assertSame([], $validator->validate('musicbot.schedule.action', [
            'instance_id' => '42',
            'schedule_id' => '7',
            'action' => 'status_check',
            'service_name' => 'musicbot-demo',
        ]));

        self::assertContains(
            'Missing required field: schedule_id',
            $validator->validate('musicbot.schedule.action', [
                'instance_id' => '42',
                'action' => 'status_check',
                'service_name' => 'musicbot-demo',
            ]),
        );
    }

    public function testAllScheduleActionsAreValidEnumValues(): void
    {
        $expected = ['start_instance', 'stop_instance', 'restart_instance', 'play_playlist', 'clear_queue', 'set_volume', 'enable_shuffle', 'set_repeat_mode', 'status_check', 'enable_autodj', 'disable_autodj'];

        foreach ($expected as $value) {
            self::assertNotNull(MusicbotScheduleAction::tryFrom($value), "Action '$value' should be a valid enum case.");
        }

        self::assertCount(count($expected), MusicbotScheduleAction::cases());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeSchedule(): MusicbotSchedule
    {
        return new MusicbotSchedule(
            $this->customer,
            $this->instance,
            'Test Schedule',
            '0 * * * *',
            'UTC',
            true,
            MusicbotScheduleAction::StatusCheck,
        );
    }

    /**
     * Build MusicbotScheduleService without calling the constructor (avoids instantiating final deps).
     * Only the provided deps are injected; others remain uninitialized (valid as long as tests don't invoke them).
     */
    private function makeServiceViaReflection(
        ?MusicbotScheduleDispatcherInterface $dispatcher = null,
        ?MusicbotScheduleRepository $scheduleRepository = null,
        ?EntityManagerInterface $em = null,
        bool $withRuntimeEventService = false,
    ): MusicbotScheduleService {
        $ref = new \ReflectionClass(MusicbotScheduleService::class);
        /** @var MusicbotScheduleService $service */
        $service = $ref->newInstanceWithoutConstructor();

        if ($dispatcher !== null) {
            $ref->getProperty('scheduleDispatcher')->setValue($service, $dispatcher);
        }
        if ($scheduleRepository !== null) {
            $ref->getProperty('scheduleRepository')->setValue($service, $scheduleRepository);
        }
        if ($em !== null) {
            $ref->getProperty('entityManager')->setValue($service, $em);
        }
        if ($withRuntimeEventService) {
            // Build MusicbotRuntimeEventService without its constructor (final class).
            $reRef = new \ReflectionClass(\App\Module\Musicbot\Application\MusicbotRuntimeEventService::class);
            $runtimeService = $reRef->newInstanceWithoutConstructor();
            $reRef->getProperty('entityManager')->setValue($runtimeService, $this->createMock(EntityManagerInterface::class));
            $ref->getProperty('runtimeEventService')->setValue($service, $runtimeService);

            $ref->getProperty('auditLogger')->setValue($service, $this->createMock(AuditLogger::class));
        }

        return $service;
    }

    /**
     * Build service for tests that involve create() which calls quota + persist but NOT runtime events.
     * Uses real quota service with scheduler allowed=true, stubs everything else.
     */
    private function makeServiceWithMocks(): MusicbotScheduleService
    {
        $ref = new \ReflectionClass(MusicbotScheduleService::class);
        /** @var MusicbotScheduleService $service */
        $service = $ref->newInstanceWithoutConstructor();

        $emProp = $ref->getProperty('entityManager');
        $emProp->setValue($service, $this->createMock(EntityManagerInterface::class));

        $repoProp = $ref->getProperty('scheduleRepository');
        $repoProp->setValue($service, $this->createMock(MusicbotScheduleRepository::class));

        $dispProp = $ref->getProperty('scheduleDispatcher');
        $dispProp->setValue($service, $this->createMock(MusicbotScheduleDispatcherInterface::class));

        return $service;
    }
}
