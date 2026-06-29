<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Application\MusicbotInstanceService;
use App\Module\Musicbot\Application\MusicbotQuotaServiceInterface;
use App\Module\Musicbot\Application\MusicbotSecretConfigServiceInterface;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Exception\MusicbotQuotaExceededException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MusicbotInstanceServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private MusicbotQuotaServiceInterface $quota;
    private MusicbotSecretConfigServiceInterface $secretConfig;
    private AgentJobDispatcherInterface $dispatcher;
    private AuditLogger $auditLogger;

    protected function setUp(): void
    {
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->quota = $this->createStub(MusicbotQuotaServiceInterface::class);
        $this->secretConfig = $this->createStub(MusicbotSecretConfigServiceInterface::class);
        $this->dispatcher = $this->createStub(AgentJobDispatcherInterface::class);
        $this->auditLogger = $this->createStub(AuditLogger::class);
    }

    private function makeService(): MusicbotInstanceService
    {
        return new MusicbotInstanceService(
            $this->em,
            $this->quota,
            $this->secretConfig,
            $this->dispatcher,
            $this->auditLogger,
        );
    }

    private function makeUser(int $id = 1): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        return $user;
    }

    private function makeNode(string $id = 'node-1'): Agent
    {
        $node = $this->createStub(Agent::class);
        $node->method('getId')->willReturn($id);
        $node->method('getName')->willReturn('Test Node');
        return $node;
    }

    private function makeJob(): AgentJob
    {
        $job = $this->createStub(AgentJob::class);
        $job->method('getId')->willReturn('job-abc');
        return $job;
    }

    public function testCreateSucceeds_WhenUnderLimit(): void
    {
        $this->dispatcher->method('dispatch')->willReturn($this->makeJob());
        $this->secretConfig->method('encrypt')->willReturn(['server_password' => 'enc', 'channel_password' => 'enc']);

        $service = $this->makeService();
        $instance = $service->createInstance(
            customer: $this->makeUser(),
            node: $this->makeNode(),
            name: 'My Bot',
            tsEnabled: false,
            tsConfig: [],
            tsSecrets: [],
        );

        $this->assertInstanceOf(MusicbotInstance::class, $instance);
        $this->assertSame('My Bot', $instance->getName());
    }

    public function testCreateFails_WhenQuotaExceeded(): void
    {
        $this->quota->method('assertCanCreateMusicbot')
            ->willThrowException(new MusicbotQuotaExceededException('Limit erreicht.'));

        $this->expectException(MusicbotQuotaExceededException::class);

        $this->makeService()->createInstance(
            customer: $this->makeUser(),
            node: $this->makeNode(),
            name: 'Bot',
            tsEnabled: false,
            tsConfig: [],
            tsSecrets: [],
        );
    }

    public function testCreateFails_WithEmptyName(): void
    {
        $this->dispatcher->method('dispatch')->willReturn($this->makeJob());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('leer');

        $this->makeService()->createInstance(
            customer: $this->makeUser(),
            node: $this->makeNode(),
            name: '   ',
            tsEnabled: false,
            tsConfig: [],
            tsSecrets: [],
        );
    }

    public function testCreateFails_WithTooLongName(): void
    {
        $this->dispatcher->method('dispatch')->willReturn($this->makeJob());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('60 Zeichen');

        $this->makeService()->createInstance(
            customer: $this->makeUser(),
            node: $this->makeNode(),
            name: str_repeat('x', 61),
            tsEnabled: false,
            tsConfig: [],
            tsSecrets: [],
        );
    }

    public function testCreateSetsInstanceConfig(): void
    {
        $this->dispatcher->method('dispatch')->willReturn($this->makeJob());

        $service = $this->makeService();
        $instance = $service->createInstance(
            customer: $this->makeUser(),
            node: $this->makeNode(),
            name: 'AutostartBot',
            tsEnabled: false,
            tsConfig: [],
            tsSecrets: [],
            autostart: true,
            webradioEnabled: true,
        );

        $cfg = $instance->getInstanceConfig();
        $this->assertTrue($cfg['autostart']);
        $this->assertTrue($cfg['webradio_enabled']);
        $this->assertSame('!', $cfg['command_prefix']);
        $this->assertSame(50, $cfg['default_volume']);
    }

    public function testUpdateSettings_ChangesName(): void
    {
        $this->dispatcher->method('dispatch')->willReturn($this->makeJob());
        $customer = $this->makeUser();
        $node = $this->makeNode();
        $instance = new MusicbotInstance($customer, $node, 'OldName', 'mb-1-abc', '/opt/mb');

        $this->makeService()->updateSettings(
            customer: $customer,
            instance: $instance,
            general: ['name' => 'NewName'],
            tsConfig: [],
            tsSecrets: [],
        );

        $this->assertSame('NewName', $instance->getName());
    }

    public function testUpdateSettings_TooLongNameThrows(): void
    {
        $customer = $this->makeUser();
        $node = $this->makeNode();
        $instance = new MusicbotInstance($customer, $node, 'Bot', 'mb-1-abc', '/opt/mb');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('60 Zeichen');

        $this->makeService()->updateSettings(
            customer: $customer,
            instance: $instance,
            general: ['name' => str_repeat('y', 61)],
            tsConfig: [],
            tsSecrets: [],
        );
    }

    public function testUpdateSettings_EmptyNameKeepsExisting(): void
    {
        $customer = $this->makeUser();
        $node = $this->makeNode();
        $instance = new MusicbotInstance($customer, $node, 'KeepMe', 'mb-1-abc', '/opt/mb');

        $this->makeService()->updateSettings(
            customer: $customer,
            instance: $instance,
            general: ['name' => ''],
            tsConfig: [],
            tsSecrets: [],
        );

        $this->assertSame('KeepMe', $instance->getName());
    }

    public function testUpdateSettings_EmptyPasswordPreservesExistingSecret(): void
    {
        $existing = ['server_password' => 'v1:nonce:cipher', 'channel_password' => ''];
        $this->secretConfig->method('mergeSecretUpdates')
            ->willReturnCallback(function (array $enc, array $updates): array {
                foreach ($updates as $k => $v) {
                    if ($v !== '' && $v !== '********') {
                        $enc[$k] = 'new-' . $v;
                    }
                }
                return $enc;
            });

        $connection = $this->createMock(MusicbotConnection::class);
        $connection->method('getConnectionConfig')->willReturn(['host' => 'ts.example.com', 'port' => 9987, 'nickname' => 'Bot', 'channel_id' => '']);
        $connection->method('getSecretConfig')->willReturn($existing);
        $connection->expects($this->once())->method('setSecretConfig')
            ->with($this->callback(fn(array $v) => $v['server_password'] === 'v1:nonce:cipher'));

        $repo = $this->createStub(\Doctrine\ORM\EntityRepository::class);
        $repo->method('findOneBy')->willReturn($connection);
        $this->em->method('getRepository')->willReturn($repo);

        $customer = $this->makeUser();
        $node = $this->makeNode();
        $instance = new MusicbotInstance($customer, $node, 'Bot', 'mb-1-abc', '/opt/mb');

        $this->makeService()->updateSettings(
            customer: $customer,
            instance: $instance,
            general: [],
            tsConfig: ['host' => 'ts.example.com', 'port' => 9987],
            tsSecrets: ['server_password' => '', 'channel_password' => ''],
        );
    }


    public function testUpdateSettings_PersistsLiveTeamspeakFieldsAndAllowsClearingChannel(): void
    {
        $this->secretConfig->method('mergeSecretUpdates')->willReturnArgument(0);

        $connection = $this->createMock(MusicbotConnection::class);
        $connection->method('isEnabled')->willReturn(true);
        $connection->method('getConnectionConfig')->willReturn([
            'host' => 'ts.example.com',
            'port' => 9987,
            'nickname' => 'Bot',
            'channel_id' => '42',
            'channel_name' => 'Old channel',
        ]);
        $connection->method('getSecretConfig')->willReturn([]);
        $connection->expects($this->once())->method('setConnectionConfig')
            ->with($this->callback(function (array $config): bool {
                return $config['channel_id'] === ''
                    && $config['channel_name'] === ''
                    && $config['channel_description'] === 'Live description'
                    && $config['avatar'] === 'avatar.png'
                    && $config['badges'] === 'badge-1,badge-2'
                    && $config['away_status'] === 'Streaming'
                    && $config['default_recording_mode'] === 'manual'
                    && $config['voice_quality'] === 'high'
                    && $config['codec'] === 'opus_music'
                    && $config['codec_bitrate'] === 128;
            }));

        $repo = $this->createStub(\Doctrine\ORM\EntityRepository::class);
        $repo->method('findOneBy')->willReturn($connection);
        $this->em->method('getRepository')->willReturn($repo);

        $customer = $this->makeUser();
        $node = $this->makeNode();
        $instance = new MusicbotInstance($customer, $node, 'Bot', 'mb-1-abc', '/opt/mb');

        $this->makeService()->updateSettings(
            customer: $customer,
            instance: $instance,
            general: [],
            tsConfig: [
                'enabled' => true,
                'host' => 'ts.example.com',
                'port' => 9987,
                'nickname' => 'Bot',
                'channel_id' => '',
                'channel_name' => '',
                'channel_description' => 'Live description',
                'avatar' => 'avatar.png',
                'badges' => 'badge-1,badge-2',
                'away_status' => 'Streaming',
                'default_recording_mode' => 'manual',
                'voice_quality' => 'high',
                'codec' => 'opus_music',
                'codec_bitrate' => '128',
            ],
            tsSecrets: [],
        );
    }

    public function testDeleteInstance_DispatchesUninstallJobAndRemovesRecord(): void
    {
        $customer = $this->makeUser();
        $node = $this->makeNode();
        $instance = new MusicbotInstance($customer, $node, 'ToDelete', 'mb-1-del', '/opt/mb');

        $dispatched = false;
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function (Agent $n, string $type) use (&$dispatched): AgentJob {
                if ($type === 'musicbot.uninstall') {
                    $dispatched = true;
                }
                return $this->makeJob();
            });

        $removed = false;
        $this->em->method('remove')
            ->willReturnCallback(function () use (&$removed): void { $removed = true; });

        $this->makeService()->deleteInstance($customer, $instance);

        $this->assertTrue($dispatched, 'musicbot.uninstall job must be dispatched');
        $this->assertTrue($removed, 'instance must be removed from EntityManager');
    }

    public function testDeleteInstance_ContinuesEvenIfDispatchFails(): void
    {
        $customer = $this->makeUser();
        $node = $this->makeNode();
        $instance = new MusicbotInstance($customer, $node, 'FailBot', 'mb-1-fail', '/opt/mb');

        $this->dispatcher->method('dispatch')
            ->willThrowException(new \RuntimeException('Agent unreachable'));

        $removed = false;
        $this->em->method('remove')
            ->willReturnCallback(function () use (&$removed): void { $removed = true; });

        $this->makeService()->deleteInstance($customer, $instance);

        $this->assertTrue($removed, 'instance must still be removed even when dispatch fails');
    }
}
