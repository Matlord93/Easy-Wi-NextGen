<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Monitoring;

use App\Module\Nodes\Application\Monitoring\InfrastructureAlertEvaluator;
use PHPUnit\Framework\TestCase;

final class InfrastructureAlertEvaluatorTest extends TestCase
{
    private const LIMITS = ['cpu_percent' => 90, 'memory_percent' => 90, 'disk_percent' => 90, 'hold_seconds' => 180, 'offline_seconds' => 180];

    public function testHoldDuplicateObservationAndHysteresisAvoidNotificationStorms(): void
    {
        $state = [];
        foreach ([1000, 1060, 1120] as $time) {
            $result = InfrastructureAlertEvaluator::evaluate($state, ['cpu_percent' => 95], self::LIMITS, $time, true);
            self::assertSame([], $result['events']);
            $state = $result['state'];
        }
        $result = InfrastructureAlertEvaluator::evaluate($state, ['cpu_percent' => 95], self::LIMITS, 1180, true);
        self::assertSame([['metric' => 'cpu_percent', 'active' => true, 'episode' => 1000]], $result['events']);
        self::assertSame([], InfrastructureAlertEvaluator::evaluate($result['state'], ['cpu_percent' => 95], self::LIMITS, 1180, true)['events']);
        $result = InfrastructureAlertEvaluator::evaluate($result['state'], ['cpu_percent' => 87], self::LIMITS, 1240, true);
        self::assertSame([], $result['events']);
        $result = InfrastructureAlertEvaluator::evaluate($result['state'], ['cpu_percent' => 84], self::LIMITS, 1300, true);
        self::assertSame([['metric' => 'cpu_percent', 'active' => false, 'episode' => 1000]], $result['events']);
    }

    public function testUnknownValuesAreNotRecoveryOrProofOfSustainedHighLoad(): void
    {
        $state = InfrastructureAlertEvaluator::evaluate([], ['memory_percent' => 95], self::LIMITS, 1000, true)['state'];
        $state = InfrastructureAlertEvaluator::evaluate($state, [], self::LIMITS, 1060, true)['state'];
        $result = InfrastructureAlertEvaluator::evaluate($state, ['memory_percent' => 95], self::LIMITS, 1300, true);
        self::assertSame([], $result['events']);
        self::assertSame(1300, $result['state']['memory_percent']['since']);
    }

    public function testMissingProviderDataProducesOnlyAnOfflineAlertAfterHold(): void
    {
        $state = InfrastructureAlertEvaluator::evaluate([], [], self::LIMITS, 1000, false)['state'];
        $result = InfrastructureAlertEvaluator::evaluate($state, [], self::LIMITS, 1180, false);
        self::assertSame([['metric' => 'offline', 'active' => true, 'episode' => 1000]], $result['events']);
        $result = InfrastructureAlertEvaluator::evaluate($result['state'], [], self::LIMITS, 1240, true);
        self::assertSame([['metric' => 'offline', 'active' => false, 'episode' => 1000]], $result['events']);
    }
}
