<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\Instance;
use App\Module\Ports\Application\PortLeaseManager;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Module\Ports\Infrastructure\Repository\PortPoolRepository;
use App\Service\Installer\TemplateInstallResolver;
use Doctrine\ORM\EntityManagerInterface;

final class InstanceInstallService
{
    public function __construct(
        private readonly SetupChecker $setupChecker,
        private readonly PortPoolRepository $portPoolRepository,
        private readonly PortBlockRepository $portBlockRepository,
        private readonly PortLeaseManager $portLeaseManager,
        private readonly TemplateInstallResolver $templateInstallResolver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{is_ready: bool, error_code?: string, missing?: array<int, array{key: string, label: string, type: string}>}
     */
    public function getInstallStatus(Instance $instance): array
    {
        $status = $this->setupChecker->getSetupStatus($instance);
        if (!$status['is_ready']) {
            return [
                'is_ready' => false,
                'error_code' => 'MISSING_REQUIREMENTS',
                'missing' => $status['missing'],
            ];
        }

        $nodeId = $instance->getNode()->getId();
        if (trim($nodeId) === '') {
            return [
                'is_ready' => false,
                'error_code' => 'NODE_NOT_SET',
            ];
        }

        $supportedOs = $instance->getTemplate()->getSupportedOs();
        $nodeOs = $this->resolveNodeOs($instance->getNode());
        if ($nodeOs === null || !in_array($nodeOs, $supportedOs, true)) {
            return [
                'is_ready' => false,
                'error_code' => 'TEMPLATE_OS_MISMATCH',
            ];
        }

        return [
            'is_ready' => true,
        ];
    }

    /**
     * @return array{ok: bool, error_code?: string, missing?: array<int, array{key: string, label: string, type: string}>, payload?: array<string, mixed>}
     */
    public function prepareInstall(Instance $instance): array
    {
        $status = $this->getInstallStatus($instance);
        if (!$status['is_ready']) {
            return [
                'ok' => false,
                'error_code' => $status['error_code'] ?? 'MISSING_REQUIREMENTS',
                'missing' => $status['missing'] ?? [],
            ];
        }

        try {
            $portAllocation = $this->allocateDefaultPorts($instance);
        } catch (\RuntimeException) {
            return [
                'ok' => false,
                'error_code' => 'NO_PORTS_AVAILABLE',
            ];
        }

        $payload = [
            'install_command' => $this->templateInstallResolver->resolveInstallCommand($instance),
            'start_params' => $instance->getTemplate()->getStartParams(),
            'ports' => $portAllocation['ports'],
            'port_reservations' => $portAllocation['reservations'],
            'env_vars' => $this->buildEnvVars($instance),
            'secrets' => $this->buildSecretPlaceholders($instance),
            'port_block_id' => $portAllocation['port_block_id'],
        ];

        return [
            'ok' => true,
            'payload' => $payload,
        ];
    }

    /**
     * @return array{ports: array<int, int>, reservations: array<int, array{role: string, protocol: string, port: int}>, port_block_id: string|null}
     */
    private function allocateDefaultPorts(Instance $instance): array
    {
        $requiredPorts = $instance->getTemplate()->getRequiredPorts();
        $requiredCount = count($requiredPorts);

        $portBlock = null;
        if ($instance->getPortBlockId() !== null) {
            $portBlock = $this->portBlockRepository->find($instance->getPortBlockId());
        }

        if ($portBlock === null && $requiredCount > 0) {
            $pools = $this->portPoolRepository->findBy(['node' => $instance->getNode()]);
            foreach ($pools as $pool) {
                try {
                    $portBlock = $this->portLeaseManager->allocateBlock($pool, $instance->getCustomer(), $requiredCount);
                } catch (\RuntimeException) {
                    continue;
                }

                $this->entityManager->persist($portBlock);
                $portBlock->assignInstance($instance);
                $instance->setPortBlockId($portBlock->getId());
                $this->entityManager->persist($instance);
                break;
            }
        }

        if ($requiredCount > 0 && $portBlock === null) {
            throw new \RuntimeException('No port pools available.');
        }

        if ($requiredCount === 0 || $portBlock === null) {
            return [
                'ports' => [],
                'reservations' => [],
                'port_block_id' => null,
            ];
        }

        $ports = $portBlock->getPorts();
        if (count($ports) < $requiredCount) {
            throw new \RuntimeException('Port block does not contain enough ports.');
        }

        return [
            'ports' => array_slice($ports, 0, $requiredCount),
            'reservations' => $this->buildPortReservations($ports, $requiredPorts),
            'port_block_id' => $portBlock->getId(),
        ];
    }

    /**
     * @param int[] $ports
     * @param array<int, array<string, mixed>> $requiredPorts
     * @return array<int, array{role: string, protocol: string, port: int}>
     */
    private function buildPortReservations(array $ports, array $requiredPorts): array
    {
        $reservations = [];
        foreach ($requiredPorts as $index => $definition) {
            if (!isset($ports[$index])) {
                continue;
            }

            $reservations[] = [
                'role' => (string) ($definition['name'] ?? 'port'),
                'protocol' => (string) ($definition['protocol'] ?? 'udp'),
                'port' => $ports[$index],
            ];
        }

        return $reservations;
    }

    /**
     * @return array<int, array{key: string, value: string}>
     */
    private function buildEnvVars(Instance $instance): array
    {
        $vars = [];
        $envVars = $instance->getTemplate()->getEnvVars();
        foreach ($envVars as $entry) {
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $vars[$key] = (string) ($entry['value'] ?? '');
        }

        foreach ($instance->getSetupVars() as $key => $value) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }
            $vars[$normalizedKey] = (string) $value;
        }

        $normalized = [];
        foreach ($vars as $key => $value) {
            $normalized[] = [
                'key' => $key,
                'value' => $value,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int, array{key: string, placeholder: string}>
     */
    private function buildSecretPlaceholders(Instance $instance): array
    {
        $placeholders = [];
        foreach ($instance->getSetupSecrets() as $key => $_payload) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }
            $placeholders[] = [
                'key' => $normalizedKey,
                'placeholder' => sprintf('{{secret:%s}}', $normalizedKey),
            ];
        }

        return $placeholders;
    }

    private function resolveNodeOs(Agent $node): ?string
    {
        $stats = $node->getLastHeartbeatStats();
        $os = is_array($stats) ? (string) ($stats['os'] ?? '') : '';
        $os = strtolower(trim($os));

        return $os !== '' ? $os : null;
    }
}
