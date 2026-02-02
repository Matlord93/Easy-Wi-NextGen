<?php

declare(strict_types=1);

namespace App\Module\Unifi\Application;

use App\Module\Core\Application\EncryptionService;
use App\Module\Ports\Infrastructure\Repository\PortAllocationRepository;
use App\Module\Unifi\Domain\Entity\UnifiAuditLog;
use App\Module\Unifi\Domain\Entity\UnifiManualRule;
use App\Module\Unifi\Domain\Entity\UnifiPortMapping;
use App\Module\Unifi\Domain\Entity\UnifiPolicy;
use App\Module\Unifi\Infrastructure\Repository\UnifiManualRuleRepository;
use App\Module\Unifi\Infrastructure\Repository\UnifiPolicyRepository;
use App\Module\Unifi\Infrastructure\Repository\UnifiPortMappingRepository;
use App\Module\Unifi\Infrastructure\Repository\UnifiSettingsRepository;
use App\Repository\InstanceRepository;
use Doctrine\ORM\EntityManagerInterface;

class UnifiPortSyncService
{
    public const AUTO_PREFIX = 'PANEL';
    public const MANUAL_PREFIX = 'MANUAL';

    public function __construct(
        private readonly UnifiSettingsRepository $settingsRepository,
        private readonly UnifiPolicyRepository $policyRepository,
        private readonly UnifiManualRuleRepository $manualRuleRepository,
        private readonly UnifiPortMappingRepository $mappingRepository,
        private readonly PortAllocationRepository $portAllocationRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly EncryptionService $encryptionService,
        private readonly UnifiApiClient $apiClient,
        private readonly UnifiRuleDiff $ruleDiff,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{status: string, request_id: string, diff: array{create: UnifiRule[], update: array<int, array{rule: UnifiRule, currentId: string}>, delete: array<int, array{currentId: string, name: string}>}, errors: array<int, array{code: string, message: string}>}
     */
    public function preview(?int $instanceId = null, ?string $requestId = null): array
    {
        $requestId = $requestId ?? $this->generateRequestId();
        $settings = $this->settingsRepository->getSettings();

        $errors = [];
        $desiredResult = $this->buildDesiredRules($settings, $instanceId, $errors);

        if (!$settings->isEnabled()) {
            return [
                'status' => 'disabled',
                'request_id' => $requestId,
                'diff' => ['create' => [], 'update' => [], 'delete' => []],
                'errors' => $errors,
            ];
        }

        try {
            $password = $this->decryptPassword($settings);
            $currentRules = $this->apiClient->listPortForwardRules($settings, $password);
            $currentMap = $this->filterManagedRules($currentRules);
        } catch (UnifiApiException $exception) {
            $errors[] = ['code' => $exception->getErrorCode(), 'message' => $exception->getMessage()];
            $currentMap = [];
        } catch (\RuntimeException $exception) {
            $errors[] = ['code' => 'auth_failed', 'message' => $exception->getMessage()];
            $currentMap = [];
        }

        return [
            'status' => $errors === [] ? 'ok' : 'error',
            'request_id' => $requestId,
            'diff' => $this->ruleDiff->diff($desiredResult, $currentMap),
            'errors' => $errors,
        ];
    }

    /**
     * @return array{status: string, request_id: string, diff: array{create: UnifiRule[], update: array<int, array{rule: UnifiRule, currentId: string}>, delete: array<int, array{currentId: string, name: string}>}, errors: array<int, array{code: string, message: string}>}
     */
    public function sync(?int $instanceId = null, bool $dryRun = false, ?string $requestId = null): array
    {
        $requestId = $requestId ?? $this->generateRequestId();
        $settings = $this->settingsRepository->getSettings();
        $errors = [];
        $desiredResult = $this->buildDesiredRules($settings, $instanceId, $errors);

        if (!$settings->isEnabled()) {
            $this->recordAudit('sync', 'disabled', $requestId, 'Module disabled.', [
                'instance_id' => $instanceId,
                'dry_run' => $dryRun,
            ]);

            return [
                'status' => 'disabled',
                'request_id' => $requestId,
                'diff' => ['create' => [], 'update' => [], 'delete' => []],
                'errors' => $errors,
            ];
        }

        try {
            $password = $this->decryptPassword($settings);
            $currentRules = $this->apiClient->listPortForwardRules($settings, $password);
            $currentMap = $this->filterManagedRules($currentRules);
        } catch (UnifiApiException $exception) {
            $errors[] = ['code' => $exception->getErrorCode(), 'message' => $exception->getMessage()];
            $this->recordAudit('sync', 'error', $requestId, $exception->getErrorCode(), [
                'instance_id' => $instanceId,
                'dry_run' => $dryRun,
            ]);

            return [
                'status' => 'error',
                'request_id' => $requestId,
                'diff' => ['create' => [], 'update' => [], 'delete' => []],
                'errors' => $errors,
            ];
        } catch (\RuntimeException $exception) {
            $errors[] = ['code' => 'auth_failed', 'message' => $exception->getMessage()];
            $this->recordAudit('sync', 'error', $requestId, 'auth_failed', [
                'instance_id' => $instanceId,
                'dry_run' => $dryRun,
            ]);

            return [
                'status' => 'error',
                'request_id' => $requestId,
                'diff' => ['create' => [], 'update' => [], 'delete' => []],
                'errors' => $errors,
            ];
        }

        $diff = $this->ruleDiff->diff($desiredResult, $currentMap);
        if ($dryRun) {
            return [
                'status' => 'dry_run',
                'request_id' => $requestId,
                'diff' => $diff,
                'errors' => $errors,
            ];
        }

        foreach ($diff['create'] as $rule) {
            try {
                $response = $this->apiClient->createRule($settings, $password, $rule->toPayload());
                $ruleId = $this->extractRuleId($response);
                $this->upsertMapping($rule, $ruleId, null, null);
            } catch (UnifiApiException $exception) {
                $errors[] = ['code' => $exception->getErrorCode(), 'message' => $exception->getMessage()];
                $this->upsertMapping($rule, null, 'error', $exception->getErrorCode());
            }
        }

        foreach ($diff['update'] as $entry) {
            $rule = $entry['rule'];
            $currentId = $entry['currentId'];
            if ($currentId === '') {
                continue;
            }

            try {
                $response = $this->apiClient->updateRule($settings, $password, $currentId, $rule->toPayload());
                $ruleId = $this->extractRuleId($response) ?? $currentId;
                $this->upsertMapping($rule, $ruleId, null, null);
            } catch (UnifiApiException $exception) {
                $errors[] = ['code' => $exception->getErrorCode(), 'message' => $exception->getMessage()];
                $this->upsertMapping($rule, $currentId, 'error', $exception->getErrorCode());
            }
        }

        foreach ($diff['delete'] as $entry) {
            $currentId = $entry['currentId'];
            if ($currentId !== '') {
                try {
                    $this->apiClient->deleteRule($settings, $password, $currentId);
                } catch (UnifiApiException $exception) {
                    $errors[] = ['code' => $exception->getErrorCode(), 'message' => $exception->getMessage()];
                }
            }

            $mapping = $this->mappingRepository->findOneByRuleName($entry['name']);
            if ($mapping instanceof UnifiPortMapping) {
                $this->entityManager->remove($mapping);
            }
        }

        $status = $errors === [] ? 'success' : 'partial';
        $this->recordAudit('sync', $status, $requestId, $errors === [] ? null : 'sync_errors', [
            'instance_id' => $instanceId,
            'dry_run' => $dryRun,
            'errors' => $errors,
        ]);

        $this->entityManager->flush();

        return [
            'status' => $status,
            'request_id' => $requestId,
            'diff' => $diff,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $currentRules
     * @return array<string, array<string, mixed>>
     */
    private function filterManagedRules(array $currentRules): array
    {
        $result = [];
        foreach ($currentRules as $rule) {
            $name = (string) ($rule['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (!str_starts_with($name, self::AUTO_PREFIX . '-') && !str_starts_with($name, self::MANUAL_PREFIX . '-')) {
                continue;
            }

            $result[$name] = $rule;
        }

        return $result;
    }

    /**
     * @param array<int, array{code: string, message: string}> $errors
     * @return array<string, UnifiRule>
     */
    private function buildDesiredRules(
        \App\Module\Unifi\Domain\Entity\UnifiSettings $settings,
        ?int $instanceId,
        array &$errors,
    ): array {
        $policy = $this->policyRepository->getPolicy();
        $mode = $policy->getMode();
        $rules = [];

        if ($mode === UnifiPolicy::MODE_AUTO || $mode === UnifiPolicy::MODE_HYBRID) {
            $allocations = [];
            if ($instanceId !== null) {
                $instance = $this->instanceRepository->find($instanceId);
                if ($instance !== null) {
                    $allocations = $this->portAllocationRepository->findByInstance($instance);
                }
            } else {
                $allocations = $this->portAllocationRepository->findAll();
            }

            $nodeTargets = $settings->getNodeTargets();
            foreach ($allocations as $allocation) {
                $proto = $allocation->getProto();
                $port = $allocation->getPort();
                $poolTag = $allocation->getPoolTag();

                if (!$this->isAllocationAllowed($policy, $proto, $port, $poolTag)) {
                    $errors[] = [
                        'code' => 'policy_denied',
                        'message' => sprintf('Port %d/%s rejected by policy.', $port, $proto),
                    ];
                    continue;
                }

                $nodeId = $allocation->getNode()->getId();
                $targetIp = $nodeTargets[$nodeId] ?? '';
                if ($targetIp === '') {
                    $errors[] = [
                        'code' => 'policy_denied',
                        'message' => sprintf('Missing target IP for node %s.', $nodeId),
                    ];
                    continue;
                }

                $instance = $allocation->getInstance();
                $instanceKey = $instance->getId() ?? 0;
                $ruleName = sprintf('%s-%d-%s-%d', self::AUTO_PREFIX, $instanceKey, strtolower($proto), $port);

                $rules[$ruleName] = new UnifiRule(
                    $ruleName,
                    $proto,
                    $port,
                    $targetIp,
                    $port,
                    true,
                    'auto',
                );
            }
        }

        if ($mode === UnifiPolicy::MODE_MANUAL || $mode === UnifiPolicy::MODE_HYBRID) {
            $manualRules = $this->manualRuleRepository->findEnabled();
            foreach ($manualRules as $rule) {
                $rules[$this->manualRuleName($rule)] = new UnifiRule(
                    $this->manualRuleName($rule),
                    $rule->getProtocol(),
                    $rule->getPort(),
                    $rule->getTargetIp(),
                    $rule->getTargetPort(),
                    $rule->isEnabled(),
                    'manual',
                );
            }
        }

        return $rules;
    }

    private function manualRuleName(UnifiManualRule $rule): string
    {
        $id = $rule->getId() ?? 0;
        return sprintf('%s-%d-%s-%d', self::MANUAL_PREFIX, $id, strtolower($rule->getProtocol()), $rule->getPort());
    }

    private function isAllocationAllowed(UnifiPolicy $policy, string $protocol, int $port, ?string $poolTag): bool
    {
        $protocol = strtolower($protocol);
        $allowedProtocols = array_map('strtolower', $policy->getAllowedProtocols());
        if ($allowedProtocols !== [] && !in_array($protocol, $allowedProtocols, true)) {
            return false;
        }

        $allowedTags = array_map('strtolower', $policy->getAllowedTags());
        if ($allowedTags !== []) {
            $poolTag = strtolower((string) $poolTag);
            if ($poolTag === '' || !in_array($poolTag, $allowedTags, true)) {
                return false;
            }
        }

        $allowedPorts = $policy->getAllowedPorts();
        $allowedRanges = $policy->getAllowedRanges();
        if ($allowedPorts === [] && $allowedRanges === []) {
            return true;
        }

        if (in_array($port, $allowedPorts, true)) {
            return true;
        }

        foreach ($allowedRanges as $range) {
            $start = (int) ($range['start'] ?? 0);
            $end = (int) ($range['end'] ?? 0);
            if ($start > 0 && $end > 0 && $port >= $start && $port <= $end) {
                return true;
            }
        }

        return false;
    }

    private function decryptPassword(\App\Module\Unifi\Domain\Entity\UnifiSettings $settings): string
    {
        $payload = $settings->getPasswordEncrypted();
        if ($payload === null) {
            throw new UnifiApiException('UniFi password not configured.', 'auth_failed');
        }

        return $this->encryptionService->decrypt($payload);
    }

    private function extractRuleId(array $response): ?string
    {
        $data = $response['data'] ?? null;
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            return (string) ($data[0]['_id'] ?? $data[0]['id'] ?? '');
        }

        if (is_array($data) && isset($data['_id'])) {
            return (string) $data['_id'];
        }

        return null;
    }

    private function upsertMapping(UnifiRule $rule, ?string $ruleId, ?string $status, ?string $error): void
    {
        $mapping = $this->mappingRepository->findOneByRuleName($rule->getName());
        if (!$mapping instanceof UnifiPortMapping) {
            $mapping = new UnifiPortMapping(
                $rule->getName(),
                $rule->getType(),
                $rule->getProtocol(),
                $rule->getPort(),
                $rule->getTargetIp(),
                $rule->getTargetPort(),
            );
            $this->entityManager->persist($mapping);
        } else {
            $mapping->updateRule($rule->getProtocol(), $rule->getPort(), $rule->getTargetIp(), $rule->getTargetPort());
        }

        if ($ruleId !== null) {
            $mapping->setUnifiRuleId($ruleId);
        }

        if ($status !== null) {
            $mapping->setLastSyncStatus($status);
            $mapping->setLastError($error);
        } else {
            $mapping->setLastSyncStatus('ok');
            $mapping->setLastError(null);
        }
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function recordAudit(string $action, string $status, string $requestId, ?string $error, ?array $context = null): void
    {
        $log = new UnifiAuditLog($action, $status, $requestId, $error, $context);
        $this->entityManager->persist($log);
    }

    private function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
