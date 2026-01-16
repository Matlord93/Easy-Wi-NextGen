<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Instance;
use App\Entity\Template;

final class SetupChecker
{
    public const ACTION_INSTALL = 'install';
    public const ACTION_START = 'start';
    public const ACTION_UPDATE = 'update';

    /**
     * @return array{is_ready: bool, missing: array<int, array{key: string, label: string, type: string}>, blocked_actions: array<int, string>}
     */
    public function getSetupStatus(Instance $instance): array
    {
        $requirements = $this->getRequirements($instance->getTemplate());
        $missing = $this->resolveMissing($instance, $requirements);

        return [
            'is_ready' => $missing === [],
            'missing' => $missing,
            'blocked_actions' => $missing === [] ? [] : [
                self::ACTION_INSTALL,
                self::ACTION_START,
                self::ACTION_UPDATE,
            ],
        ];
    }

    /**
     * @return array{vars: array<int, array<string, mixed>>, secrets: array<int, array<string, mixed>>}
     */
    public function getRequirements(Template $template): array
    {
        return [
            'vars' => $this->normalizeRequirements($template->getRequirementVars(), 'var'),
            'secrets' => $this->normalizeRequirements($template->getRequirementSecrets(), 'secret'),
        ];
    }

    /**
     * @return array{vars: array<int, array<string, mixed>>, secrets: array<int, array<string, mixed>>}
     */
    public function getCustomerRequirements(Template $template): array
    {
        $requirements = $this->getRequirements($template);

        $filter = static fn (array $entry): bool => $entry['scope'] === 'customer_allowed';

        return [
            'vars' => array_values(array_filter($requirements['vars'], $filter)),
            'secrets' => array_values(array_filter($requirements['secrets'], $filter)),
        ];
    }

    /**
     * @param array<string, mixed> $requirement
     */
    public function validateRequirementValue(array $requirement, mixed $value): ?string
    {
        $type = (string) ($requirement['type'] ?? 'text');
        if ($this->isValueMissing($value, $type)) {
            return 'Value is required.';
        }

        if ($type === 'number' && !is_numeric($value)) {
            return 'Value must be numeric.';
        }

        $validation = $requirement['validation'] ?? null;
        if (is_string($validation) && $validation !== '') {
            if (@preg_match($validation, (string) $value) !== 1) {
                return 'Value does not match the required format.';
            }
        }
        if (is_array($validation)) {
            $pattern = $validation['pattern'] ?? null;
            if (is_string($pattern) && $pattern !== '') {
                if (@preg_match($pattern, (string) $value) !== 1) {
                    return 'Value does not match the required format.';
                }
            }
            if ($type === 'number' && is_numeric($value)) {
                $numeric = (float) $value;
                if (isset($validation['min']) && is_numeric($validation['min']) && $numeric < (float) $validation['min']) {
                    return 'Value is below the minimum.';
                }
                if (isset($validation['max']) && is_numeric($validation['max']) && $numeric > (float) $validation['max']) {
                    return 'Value is above the maximum.';
                }
            }
        }

        return null;
    }

    /**
     * @param array{vars: array<int, array<string, mixed>>, secrets: array<int, array<string, mixed>>} $requirements
     * @return array<int, array{key: string, label: string, type: string}>
     */
    private function resolveMissing(Instance $instance, array $requirements): array
    {
        $missing = [];
        $vars = $instance->getSetupVars();

        foreach ($requirements['vars'] as $entry) {
            if (!$entry['required']) {
                continue;
            }

            $key = $entry['key'];
            $value = $vars[$key] ?? null;
            if ($this->isValueMissing($value, $entry['type']) || $this->validateRequirementValue($entry, $value) !== null) {
                $missing[] = [
                    'key' => $key,
                    'label' => $entry['label'],
                    'type' => 'var',
                ];
            }
        }

        foreach ($requirements['secrets'] as $entry) {
            if (!$entry['required']) {
                continue;
            }
            if (!$instance->hasSetupSecret($entry['key'])) {
                $missing[] = [
                    'key' => $entry['key'],
                    'label' => $entry['label'],
                    'type' => 'secret',
                ];
            }
        }

        return $missing;
    }

    private function isValueMissing(mixed $value, string $type): bool
    {
        if ($value === null) {
            return true;
        }

        if ($type === 'number') {
            return $value === '' || !is_numeric($value);
        }

        return trim((string) $value) === '';
    }

    /**
     * @param array<int, mixed> $requirements
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRequirements(array $requirements, string $kind): array
    {
        $normalized = [];

        foreach ($requirements as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $key = trim((string) ($entry['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $type = strtolower(trim((string) ($entry['type'] ?? '')));
            $allowedTypes = ['text', 'number', 'password'];
            if (!in_array($type, $allowedTypes, true)) {
                $type = $kind === 'secret' ? 'password' : 'text';
            }

            $scope = strtolower(trim((string) ($entry['scope'] ?? 'customer_allowed')));
            if (!in_array($scope, ['admin_only', 'customer_allowed'], true)) {
                $scope = 'customer_allowed';
            }

            $normalized[] = [
                'key' => $key,
                'label' => trim((string) ($entry['label'] ?? $key)) ?: $key,
                'type' => $type,
                'required' => (bool) ($entry['required'] ?? false),
                'scope' => $scope,
                'validation' => $entry['validation'] ?? null,
                'helptext' => trim((string) ($entry['helptext'] ?? '')),
            ];
        }

        return $normalized;
    }
}
