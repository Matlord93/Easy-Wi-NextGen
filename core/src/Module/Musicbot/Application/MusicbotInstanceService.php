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
        $this->quotaService->assertCanCreateMusicbot($customer);

        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Bot-Name darf nicht leer sein.');
        }
        if (mb_strlen($name) > 60) {
            throw new \InvalidArgumentException('Bot-Name darf maximal 60 Zeichen lang sein.');
        }

        $customerId = $customer->getId() ?? 0;
        $serviceName = sprintf('mb-%d-%s', $customerId, bin2hex(random_bytes(5)));
        $installPath = '/opt/musicbot/instances/' . $serviceName;

        $instance = new MusicbotInstance($customer, $node, $name, $serviceName, $installPath);
        $instance->setInstanceConfig([
            'autostart' => $autostart,
            'command_prefix' => '!',
            'default_volume' => 50,
            'webradio_enabled' => $webradioEnabled,
        ]);
        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        if ($tsEnabled) {
            $this->quotaService->assertCanManageTeamspeakConnection($customer);

            $connectionConfig = [
                'host' => trim((string) ($tsConfig['host'] ?? '')),
                'port' => max(1, min(65535, (int) ($tsConfig['port'] ?? 9987))),
                'nickname' => substr(trim((string) ($tsConfig['nickname'] ?? 'Musicbot')), 0, 30) ?: 'Musicbot',
                'channel_id' => trim((string) ($tsConfig['channel_id'] ?? '')),
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
        if ($tsConnection !== null) {
            if (array_key_exists('enabled', $tsConfig)) {
                $tsConnection->setEnabled((bool) $tsConfig['enabled']);
            }

            $connConfig = $tsConnection->getConnectionConfig();
            foreach (['host', 'port', 'nickname', 'channel_id'] as $field) {
                if (!array_key_exists($field, $tsConfig)) {
                    continue;
                }
                $value = $tsConfig[$field];
                $connConfig[$field] = match ($field) {
                    'port' => max(1, min(65535, (int) $value)),
                    'nickname' => substr(trim((string) $value), 0, 30),
                    default => trim((string) $value),
                };
            }
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
}
