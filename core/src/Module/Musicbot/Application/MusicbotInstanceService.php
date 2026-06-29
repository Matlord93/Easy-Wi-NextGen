<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotInstanceService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotQuotaServiceInterface $quotaService,
        private readonly MusicbotSecretConfigServiceInterface $secretConfigService,
        private readonly AgentJobDispatcherInterface $jobDispatcher,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Create a new MusicbotInstance for the given customer.
     *
     * @param array<string, mixed> $tsConfig    Public TS connection fields: host, port, nickname, channel_id
     * @param array<string, string> $tsSecrets  Plaintext secret fields: server_password, channel_password
     */
    public function createInstance(
        User $customer,
        Agent $node,
        string $name,
        bool $tsEnabled,
        array $tsConfig,
        array $tsSecrets,
        bool $autostart = false,
        bool $webradioEnabled = false,
    ): MusicbotInstance {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Bot-Name darf nicht leer sein.');
        }
        if (mb_strlen($name) > 60) {
            throw new \InvalidArgumentException('Bot-Name darf maximal 60 Zeichen lang sein.');
        }

        $instance = $this->entityManager->wrapInTransaction(function () use ($customer, $node, $name, $autostart, $webradioEnabled): MusicbotInstance {
            // Pessimistic write lock on the customer row prevents concurrent quota bypasses.
            $this->entityManager->lock($customer, LockMode::PESSIMISTIC_WRITE);
            $this->quotaService->assertCanCreateMusicbot($customer);

            $customerId = $customer->getId() ?? 0;
            $serviceName = sprintf('mb-%d-%s', $customerId, bin2hex(random_bytes(5)));
            $installPath = '/opt/musicbot/instances/' . $serviceName;

            $newInstance = new MusicbotInstance($customer, $node, $name, $serviceName, $installPath);
            $newInstance->setInstanceConfig([
                'autostart' => $autostart,
                'command_prefix' => '!',
                'default_volume' => 50,
                'webradio_enabled' => $webradioEnabled,
            ]);
            $this->entityManager->persist($newInstance);
            $this->entityManager->flush();

            return $newInstance;
        });

        $customerId = $customer->getId() ?? 0;

        if ($tsEnabled) {
            $this->quotaService->assertCanManageTeamspeakConnection($customer);

            $connectionConfig = [
                'host' => trim((string) ($tsConfig['host'] ?? '')),
                'port' => max(1, min(65535, (int) ($tsConfig['port'] ?? 9987))),
                'nickname' => substr(trim((string) ($tsConfig['nickname'] ?? 'Musicbot')), 0, 30) ?: 'Musicbot',
                'channel_id' => trim((string) ($tsConfig['channel_id'] ?? '')),
                'channel_name' => trim((string) ($tsConfig['channel_name'] ?? '')),
                'channel_description' => trim((string) ($tsConfig['channel_description'] ?? '')),
                'avatar' => trim((string) ($tsConfig['avatar'] ?? '')),
                'badges' => trim((string) ($tsConfig['badges'] ?? '')),
                'away_status' => trim((string) ($tsConfig['away_status'] ?? '')),
                'default_recording_mode' => trim((string) ($tsConfig['default_recording_mode'] ?? '')),
                'voice_quality' => trim((string) ($tsConfig['voice_quality'] ?? '')),
                'codec' => trim((string) ($tsConfig['codec'] ?? '')),
                'codec_bitrate' => trim((string) ($tsConfig['codec_bitrate'] ?? '')),
                'profile' => 'ts3',
                'backend' => 'ts3_client_compatible',
                'capability_status' => 'client_backend_required',
            ];

            $encryptedSecrets = $this->secretConfigService->encrypt([
                'server_password' => (string) ($tsSecrets['server_password'] ?? ''),
                'channel_password' => (string) ($tsSecrets['channel_password'] ?? ''),
            ]);

            $connection = new MusicbotConnection(
                $instance,
                MusicbotPlatform::Teamspeak,
                $connectionConfig,
                $encryptedSecrets,
            );
            $connection->setEnabled(true);
            $this->entityManager->persist($connection);
        }

        $job = $this->jobDispatcher->dispatch($node, 'musicbot.install', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $customerId,
            'node_id' => $node->getId(),
            'service_name' => $instance->getServiceName(),
            'install_path' => $instance->getInstallPath(),
        ]);

        $this->auditLogger->log($customer, 'musicbot.instance_created', [
            'instance_id' => $instance->getId(),
            'node_id' => $node->getId(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return $instance;
    }

    /**
     * Update persistent settings for an instance.
     *
     * @param array<string, mixed>  $general    name, autostart, command_prefix, default_volume
     * @param array<string, mixed>  $tsConfig   host, port, nickname, channel_id, enabled
     * @param array<string, string> $tsSecrets  server_password, channel_password (empty = keep existing)
     */
    public function updateSettings(
        User $customer,
        MusicbotInstance $instance,
        array $general,
        array $tsConfig,
        array $tsSecrets,
    ): void {
        $name = trim((string) ($general['name'] ?? ''));
        if ($name !== '') {
            if (mb_strlen($name) > 60) {
                throw new \InvalidArgumentException('Bot-Name darf maximal 60 Zeichen lang sein.');
            }
            $instance->setName($name);
        }

        $cfg = $instance->getInstanceConfig();
        $cfg['autostart'] = (bool) ($general['autostart'] ?? $cfg['autostart'] ?? false);
        $prefix = trim((string) ($general['command_prefix'] ?? $cfg['command_prefix'] ?? '!'));
        $cfg['command_prefix'] = substr($prefix, 0, 5) ?: '!';
        $cfg['default_volume'] = max(0, min(100, (int) ($general['default_volume'] ?? $cfg['default_volume'] ?? 50)));
        if (array_key_exists('auto_dj', $general)) {
            $cfg['auto_dj'] = (bool) $general['auto_dj'];
        }
        if (array_key_exists('repeat_default', $general)) {
            $repeatVal = (string) $general['repeat_default'];
            $cfg['repeat_default'] = in_array($repeatVal, ['off', 'one', 'all'], true) ? $repeatVal : 'off';
        }
        if (array_key_exists('shuffle_default', $general)) {
            $cfg['shuffle_default'] = (bool) $general['shuffle_default'];
        }
        $instance->setInstanceConfig($cfg);

        $tsConnection = $this->findTeamspeakConnection($instance);
        if (($tsConfig['enabled'] ?? false) && array_key_exists('host', $tsConfig) && trim((string) $tsConfig['host']) === '') {
            throw new \InvalidArgumentException('TeamSpeak-Host darf nicht leer sein.');
        }
        if (($tsConfig['enabled'] ?? false) && array_key_exists('nickname', $tsConfig) && trim((string) $tsConfig['nickname']) === '') {
            throw new \InvalidArgumentException('TeamSpeak-Nickname darf nicht leer sein.');
        }
        if ($tsConnection === null && (($tsConfig['enabled'] ?? false) || $this->hasAnyConnectionUpdate($tsConfig))) {
            $tsConnection = new MusicbotConnection($instance, MusicbotPlatform::Teamspeak, [
                'profile' => 'ts3',
                'backend' => 'ts3_client_compatible',
                'backend_type' => 'placeholder',
                'host' => 'localhost',
                'port' => 9987,
                'nickname' => 'Musicbot',
                'command_prefix' => $cfg['command_prefix'] ?? '!',
                'capability_status' => 'client_backend_required',
            ]);
            $this->entityManager->persist($tsConnection);
        }
        if ($tsConnection !== null) {
            if (array_key_exists('enabled', $tsConfig)) {
                $tsConnection->setEnabled((bool) $tsConfig['enabled']);
            }

            $connConfig = $tsConnection->getConnectionConfig();
            foreach (['profile' => 'ts3', 'backend' => 'ts3_client_compatible', 'backend_type' => 'placeholder'] as $field => $default) {
                if (trim((string) ($connConfig[$field] ?? '')) === '') {
                    $connConfig[$field] = $default;
                }
            }
            if (!array_key_exists('command_prefix', $tsConfig) && array_key_exists('command_prefix', $general)) {
                $connConfig['command_prefix'] = $cfg['command_prefix'];
            }
            foreach (['host', 'port', 'nickname', 'channel_id', 'channel_name', 'channel_description', 'avatar', 'badges', 'away_status', 'default_recording_mode', 'voice_quality', 'codec', 'codec_bitrate', 'commands_enabled', 'chat_scopes', 'command_config', 'command_prefix'] as $field) {
                if (!array_key_exists($field, $tsConfig)) {
                    continue;
                }
                $value = $tsConfig[$field];
                if ($field === 'commands_enabled' && (bool) $value) {
                    $this->quotaService->assertTeamspeakCommandsAllowed($customer);
                }
                if (in_array($field, ['host', 'nickname'], true) && trim((string) $value) === '') {
                    continue;
                }
                if (in_array($field, ['port', 'codec_bitrate'], true) && trim((string) $value) !== '' && !is_numeric($value)) {
                    throw new \InvalidArgumentException('Der TeamSpeak-Port muss numerisch sein.');
                }
                $connConfig[$field] = match ($field) {
                    'port' => max(1, min(65535, (int) $value)),
                    'codec_bitrate' => trim((string) $value) === '' ? '' : max(1, min(512, (int) $value)),
                    'commands_enabled' => (bool) $value,
                    'chat_scopes' => $this->normalizeTeamspeakChatScopes($value),
                    'command_config' => is_array($value) ? $value : [],
                    'nickname' => substr(trim((string) $value), 0, 30),
                    'command_prefix' => substr(trim((string) $value), 0, 5) ?: ($connConfig['command_prefix'] ?? $cfg['command_prefix'] ?? '!'),
                    default => trim((string) $value),
                };
            }
            if ($tsConnection->isEnabled() && trim((string) ($connConfig['host'] ?? '')) === '') {
                throw new \InvalidArgumentException('TeamSpeak-Host darf nicht leer sein.');
            }
            if ($tsConnection->isEnabled() && trim((string) ($connConfig['nickname'] ?? '')) === '') {
                throw new \InvalidArgumentException('TeamSpeak-Nickname darf nicht leer sein.');
            }
            $connConfig['capability_status'] = (string) ($connConfig['capability_status'] ?? 'client_backend_required');
            $tsConnection->setConnectionConfig($connConfig);

            $updatedSecrets = $this->secretConfigService->mergeSecretUpdates(
                $tsConnection->getSecretConfig(),
                [
                    'server_password' => (string) ($tsSecrets['server_password'] ?? ''),
                    'channel_password' => (string) ($tsSecrets['channel_password'] ?? ''),
                ],
            );
            $tsConnection->setSecretConfig($updatedSecrets);
        }

        $this->auditLogger->log($customer, 'musicbot.settings_updated', [
            'instance_id' => $instance->getId(),
        ]);

        $this->entityManager->flush();
    }

    /**
     * Delete an instance: dispatch uninstall job (best-effort), then remove the record.
     */
    public function deleteInstance(User $customer, MusicbotInstance $instance): void
    {
        try {
            $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.uninstall', [
                'instance_id' => (string) $instance->getId(),
                'customer_id' => (string) ($customer->getId() ?? 0),
                'node_id' => $instance->getNode()->getId(),
                'service_name' => $instance->getServiceName(),
                'install_path' => $instance->getInstallPath(),
            ]);
        } catch (\Throwable) {
            // Non-fatal: the record is removed regardless so the customer is unblocked.
        }

        $this->auditLogger->log($customer, 'musicbot.instance_deleted', [
            'instance_id' => $instance->getId(),
            'service_name' => $instance->getServiceName(),
        ]);

        $this->entityManager->remove($instance);
        $this->entityManager->flush();
    }

    private function findTeamspeakConnection(MusicbotInstance $instance): ?MusicbotConnection
    {
        $repo = $this->entityManager->getRepository(MusicbotConnection::class);
        $result = $repo->findOneBy([
            'musicbotInstance' => $instance,
            'platform' => MusicbotPlatform::Teamspeak,
        ]);

        return $result instanceof MusicbotConnection ? $result : null;
    }

    /** @return list<string> */
    private function normalizeTeamspeakChatScopes(mixed $value): array
    {
        if (!is_array($value)) {
            return ['channel', 'private'];
        }
        $scopes = [];
        foreach ($value as $scope) {
            $scope = strtolower(trim((string) $scope));
            if (in_array($scope, ['channel', 'private'], true)) {
                $scopes[] = $scope;
            }
        }

        return array_values(array_unique($scopes)) ?: ['channel', 'private'];
    }

    /** @param array<string, mixed> $tsConfig */
    private function hasAnyConnectionUpdate(array $tsConfig): bool
    {
        foreach (['host', 'port', 'nickname', 'channel_id', 'channel_name', 'channel_description', 'avatar', 'badges', 'away_status', 'default_recording_mode', 'voice_quality', 'codec', 'codec_bitrate', 'commands_enabled', 'chat_scopes', 'command_config', 'command_prefix'] as $field) {
            if (!array_key_exists($field, $tsConfig)) {
                continue;
            }
            $value = $tsConfig[$field];
            if (is_array($value) ? $value !== [] : trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }
}
