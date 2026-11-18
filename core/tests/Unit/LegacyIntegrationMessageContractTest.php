<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Message\MailControlPlaneJobMessage;
use App\Message\UnifiSyncInstancePortsMessage;
use App\Module\Core\Domain\Enum\MailJobType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class LegacyIntegrationMessageContractTest extends TestCase
{
    public function testQueuedMailMessageRetainsItsWireContract(): void
    {
        $serializer = new PhpSerializer();
        $message = new MailControlPlaneJobMessage('node-42', MailJobType::ApplyDomain, 'correlation', 'idempotency', ['domain' => 'example.test'], 7);
        $decoded = $serializer->decode($serializer->encode(new Envelope($message)))->getMessage();

        self::assertInstanceOf(MailControlPlaneJobMessage::class, $decoded);
        self::assertSame('node-42', $decoded->nodeId);
        self::assertSame(MailJobType::ApplyDomain, $decoded->type);
        self::assertSame('correlation', $decoded->correlationId);
        self::assertSame('idempotency', $decoded->idempotencyKey);
        self::assertSame(['domain' => 'example.test'], $decoded->payload);
        self::assertSame(7, $decoded->maxAttempts);
    }

    public function testExternalMailProducerCanOmitTheOptionalMaxAttemptsField(): void
    {
        $message = new MailControlPlaneJobMessage('node-42', MailJobType::CheckDns, 'correlation', 'idempotency', []);

        self::assertSame(5, $message->maxAttempts);
    }

    public function testQueuedUnifiMessageRetainsItsInstanceIdentifier(): void
    {
        $serializer = new PhpSerializer();
        $message = new UnifiSyncInstancePortsMessage(42);
        $decoded = $serializer->decode($serializer->encode(new Envelope($message)))->getMessage();

        self::assertInstanceOf(UnifiSyncInstancePortsMessage::class, $decoded);
        self::assertSame(42, $decoded->getInstanceId());
    }
}
