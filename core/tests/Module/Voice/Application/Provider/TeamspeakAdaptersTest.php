<?php

declare(strict_types=1);

namespace App\Tests\Module\Voice\Application\Provider;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\VoiceInstance;
use App\Module\Core\Domain\Entity\VoiceNode;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Voice\Application\Provider\SinusbotAdapter;
use App\Module\Voice\Application\Provider\Teamspeak3Adapter;
use App\Module\Voice\Application\Provider\Teamspeak6Adapter;
use App\Module\Voice\Application\Provider\Ts3ServerLookupInterface;
use App\Module\Voice\Application\Provider\Ts6ServerLookupInterface;
use PHPUnit\Framework\TestCase;

final class TeamspeakAdaptersTest extends TestCase
{
    public function testTs3NotFoundProbeMapsReasonAndCode(): void
    {
        $lookup = $this->createMock(Ts3ServerLookupInterface::class);
        $lookup->method('find')->willReturn(null);

        $adapter = new Teamspeak3Adapter($lookup);
        $instance = new VoiceInstance(new User('c@example.test', UserType::Customer), new VoiceNode('n1', 'ts3', '127.0.0.1', 10011), '999', 'Srv');

        $result = $adapter->probeStatus($instance);
        self::assertSame('voice_instance_not_found', $result['error_code']);
        self::assertSame('unknown', $result['status']);
    }

    public function testTs6RejectsInvalidAction(): void
    {
        $lookup = $this->createMock(Ts6ServerLookupInterface::class);
        $adapter = new Teamspeak6Adapter($lookup);
        $instance = new VoiceInstance(new User('c2@example.test', UserType::Customer), new VoiceNode('n2', 'ts6', '127.0.0.1', 10022), '111', 'Srv2');

        $result = $adapter->performAction($instance, 'noop');
        self::assertFalse($result['accepted']);
        self::assertSame('voice_action_invalid', $result['error_code']);
    }

    public function testSinusbotReturnsUnknownQueryState(): void
    {
        $adapter = new SinusbotAdapter();
        $instance = new VoiceInstance(new User('c3@example.test', UserType::Customer), new VoiceNode('n3', 'sinusbot', '127.0.0.1', 10088), '55', 'Bot');

        $result = $adapter->query($instance);

        self::assertSame('unknown', $result->status);
        self::assertSame('voice_query_not_supported', $result->errorCode);
    }
}
