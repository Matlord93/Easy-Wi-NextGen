<?php

declare(strict_types=1);

namespace App\Tests\Module\Voice\Application\Job;

use App\Module\Voice\Application\Driver\VoiceDriver;
use App\Module\Voice\Application\Job\BackupIntegrationHookInterface;
use App\Module\Voice\Application\Job\InMemoryActionLogStore;
use App\Module\Voice\Application\Job\VoiceProvisioningJobs;
use App\Module\Voice\Application\Model\PermissionSet;
use App\Module\Voice\Application\Model\VoiceServer;
use App\Module\Voice\Application\Model\VoiceUser;
use App\Module\Voice\Application\Query\VoiceQueryEngine;
use PHPUnit\Framework\TestCase;

final class VoiceProvisioningJobsTest extends TestCase
{
    public function testUserActionsAreAuditLoggedAndBackupHookIsCalled(): void
    {
        $driver = new class implements VoiceDriver {
            public function provider(): string { return 'ts3'; }
            public function supports(VoiceServer $server): bool { return true; }
            public function createServer(VoiceServer $server, VoiceQueryEngine $engine): array { return ['created' => true]; }
            public function listUsers(VoiceServer $server, VoiceQueryEngine $engine): array { return []; }
            public function manageUser(VoiceServer $server, VoiceUser $user, VoiceQueryEngine $engine): array { return ['user' => $user->id()]; }
            public function applyPermissions(VoiceServer $server, VoiceUser $user, PermissionSet $permissions, VoiceQueryEngine $engine): array { return ['perms' => $permissions->permissions()]; }
            public function createToken(VoiceServer $server, PermissionSet $permissions, VoiceQueryEngine $engine): array { return ['token' => 'abc']; }
        };

        $logs = new InMemoryActionLogStore();
        $jobs = new VoiceProvisioningJobs($driver, new VoiceQueryEngine(), $logs);
        $server = new VoiceServer('s1', 'ts3', '127.0.0.1', 10011, 9987);

        $jobs->createServer($server, 'admin:1');
        $jobs->manageUser($server, new VoiceUser('42', 'alice', '7'), new PermissionSet(['b_virtualserver_join_ignore_password']), 'admin:1');

        $hook = new class implements BackupIntegrationHookInterface {
            public bool $called = false;
            public function triggerForServer(VoiceServer $server): void
            {
                $this->called = true;
            }
        };
        $jobs->runBackup($server, $hook, 'admin:1');

        self::assertTrue($hook->called);
        self::assertCount(3, $logs->all());

        $manageUserLog = $logs->all()[1];
        self::assertSame('manage_user', $manageUserLog->action());
        self::assertSame('[REDACTED]', $manageUserLog->metadata()['token']);
        self::assertSame(hash('sha256', 'abc'), $manageUserLog->metadata()['token_hash']);

        foreach ($logs->all() as $log) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string) ($log->metadata()['correlation_id'] ?? ''));
        }
    }
}
