<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Job;

use App\Module\Voice\Application\Driver\VoiceDriver;
use App\Module\Voice\Application\Model\ActionLog;
use App\Module\Voice\Application\Model\PermissionSet;
use App\Module\Voice\Application\Model\VoiceServer;
use App\Module\Voice\Application\Model\VoiceUser;
use App\Module\Voice\Application\Query\VoiceQueryEngine;

final class VoiceProvisioningJobs
{
    public function __construct(
        private readonly VoiceDriver $driver,
        private readonly VoiceQueryEngine $engine,
        private readonly ActionLogStoreInterface $logStore,
    ) {
    }

    /** @return array<string, mixed> */
    public function createServer(VoiceServer $server, string $actor): array
    {
        $result = $this->driver->createServer($server, $this->engine);
        $this->audit($server, 'create_server', $actor);

        return $result;
    }

    /** @return array<string, mixed> */
    public function manageUser(VoiceServer $server, VoiceUser $user, PermissionSet $permissions, string $actor): array
    {
        $userResult = $this->driver->manageUser($server, $user, $this->engine);
        $permissionResult = $this->driver->applyPermissions($server, $user, $permissions, $this->engine);
        $tokenResult = $this->driver->createToken($server, $permissions, $this->engine);

        $tokenMetadata = $this->sanitizeTokenMetadata($tokenResult);
        $this->audit($server, 'manage_user', $actor, [
            'user' => $user->id(),
            'channel' => $user->channelId(),
            'token' => $tokenMetadata['redacted'],
            'token_hash' => $tokenMetadata['hash'],
        ]);

        return [
            'user' => $userResult,
            'permissions' => $permissionResult,
            'token' => $tokenResult,
        ];
    }

    public function runBackup(VoiceServer $server, BackupIntegrationHookInterface $backupHook, string $actor): void
    {
        $backupHook->triggerForServer($server);
        $this->audit($server, 'backup', $actor);
    }

    /** @param array<string, scalar|null> $metadata */
    private function audit(VoiceServer $server, string $action, string $actor, array $metadata = []): void
    {
        $metadata['correlation_id'] = bin2hex(random_bytes(8));
        $this->logStore->append(new ActionLog($server->id(), $action, $actor, new \DateTimeImmutable(), $metadata));
    }

    /** @param array<string, mixed> $tokenResult
     *  @return array{redacted:string,hash:string|null}
     */
    private function sanitizeTokenMetadata(array $tokenResult): array
    {
        foreach (['token', 'value', 'key'] as $field) {
            $candidate = $tokenResult[$field] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return [
                    'redacted' => '[REDACTED]',
                    'hash' => hash('sha256', $candidate),
                ];
            }
        }

        return ['redacted' => '[NOT_PROVIDED]', 'hash' => null];
    }

}
