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
        self::assertStringContainsString("\$payload['payload']['snapshot'] ?? null", $this->source);
        self::assertStringContainsString("\$payload['snapshot'] ?? null", $this->source);
        self::assertStringContainsString("\$payload['resultPayload']['snapshot'] ?? null", $this->source);
        self::assertStringContainsString("\$payload['result_payload']['snapshot'] ?? null", $this->source);
        self::assertStringContainsString("\$payload['payload']['resultPayload']['snapshot'] ?? null", $this->source);
    }

    public function testSnapshotPollHandlesStatusAndErrors(): void
    {
        self::assertStringContainsString("in_array(\$status, ['ok', 'success', 'completed', 'done'], true)", $this->source);
        self::assertStringContainsString("'voice_snapshot_agent_failed'", $this->source);
        self::assertStringContainsString("'voice_snapshot_empty'", $this->source);
    }
}

