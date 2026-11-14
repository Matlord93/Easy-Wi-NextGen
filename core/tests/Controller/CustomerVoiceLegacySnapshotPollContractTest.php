<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class CustomerVoiceLegacySnapshotPollContractTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = (string) file_get_contents(__DIR__.'/../../src/Module/Voice/UI/Controller/Customer/CustomerVoiceLegacyApiController.php');
    }

    public function testSnapshotExtractionSupportsMultipleShapes(): void
    {
        $reflection = new \ReflectionClass(\App\Module\Voice\UI\Controller\Customer\CustomerVoiceLegacyApiController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('extractSnapshotContent');
        foreach ([
            ['payload' => ['snapshot' => 'snapshot']],
            ['snapshot' => 'snapshot'],
            ['resultPayload' => ['snapshot' => 'snapshot']],
            ['result_payload' => ['snapshot' => 'snapshot']],
            ['payload' => ['resultPayload' => ['snapshot' => 'snapshot']]],
        ] as $payload) {
            self::assertSame('snapshot', $method->invoke($controller, $payload));
        }
        self::assertNull($method->invoke($controller, ['payload' => 'malformed', 'result' => ['snapshot' => []]]));
    }

    public function testSnapshotPollHandlesStatusAndErrors(): void
    {
        self::assertStringContainsString("in_array(\$status, ['ok', 'success', 'completed', 'done'], true)", $this->source);
        self::assertStringContainsString("'voice_snapshot_agent_failed'", $this->source);
        self::assertStringContainsString("'voice_snapshot_empty'", $this->source);
    }
}

