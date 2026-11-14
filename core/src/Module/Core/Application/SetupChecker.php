<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Config\ScalarValue;

/**
 * @psalm-type RequirementValidation = array{pattern?: string, min?: float, max?: float}
 * @psalm-type Requirement = array{
 *     key: string, label: string, type: 'text'|'number'|'password', required: bool,
 *     scope: 'admin_only'|'customer_allowed', validation: string|RequirementValidation|null, helptext: string
 * }
 */
final class SetupChecker
{
    public const ACTION_INSTALL = 'install';
    public const ACTION_START = 'start';
    public const ACTION_UPDATE = 'update';

    /**
     * @return array{is_ready: bool, missing: array<int, array{key: string, label: string, type: string}>,
     * blocked_actions: array<int, string>}
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
     * @return array{vars: list<Requirement>, secrets: list<Requirement>}
     */
    public function getRequirements(Template $template): array
    {
        return [
            'vars' => $this->normalizeRequirements($template->getRequirementVars(), 'var'),
            'secrets' => $this->normalizeRequirements($template->getRequirementSecrets(), 'secret'),
        ];
    }

    /**
     * @return array{vars: list<Requirement>, secrets: list<Requirement>}
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
     * @param array<array-key, mixed> $requirement
     */
    public function validateRequirementValue(array $requirement, mixed $value): ?string
    {
        $type = JsonMap::strings($requirement)['type'] ?? 'text';
        if ($this->isValueMissing($value, $type)) {
            return 'Value is required.';
        }

        if ($type === 'number' && !is_numeric($value)) {
            return 'Value must be numeric.';
        }

        $validation = self::normalizeValidation($requirement['validation'] ?? null);
        if (is_string($validation) && $validation !== '') {
            if (@preg_match($validation, ScalarValue::text($value)) !== 1) {
                return 'Value does not match the required format.';
            }
        }
        if (is_array($validation)) {
            $pattern = $validation['pattern'] ?? null;
            if (is_string($pattern) && $pattern !== '') {
                if (@preg_match($pattern, ScalarValue::text($value)) !== 1) {
                    return 'Value does not match the required format.';
                }
            }
            if ($type === 'number' && is_numeric($value)) {
                $numeric = (float) $value;
                if (isset($validation['min']) && $numeric < $validation['min']) {
                    return 'Value is below the minimum.';
                }
                if (isset($validation['max']) && $numeric > $validation['max']) {
                    return 'Value is above the maximum.';
                }
            }
        }

        return null;
    }

    /**
     * @param array{vars: list<Requirement>, secrets: list<Requirement>} $requirements
     * @return array<int, array{key: string, label: string, type: string}>
     */
    private function resolveMissing(Instance $instance, array $requirements): array
    {
        $missing = [];
        $vars = $instance->getSetupVars();
        $loginMode = $this->resolveSteamLoginMode($instance);
        $usesSteamLogin = $this->usesSteamLogin($instance->getTemplate());

        foreach ($requirements['vars'] as $entry) {
            if (!$entry['required']) {
                continue;
            }

            $key = $entry['key'];
            if ($this->shouldSkipSteamCredential($key, $loginMode)) {
                continue;
            }
            $value = ScalarValue::text($vars[$key] ?? null);
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

        if ($usesSteamLogin && $loginMode === 'account') {
            $missing = $this->appendSteamLoginMissing($instance, $missing);
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

        return trim(ScalarValue::text($value)) === '';
    }

    /**
     * @param array<array-key, mixed> $requirements
     * @return list<Requirement>
     */
    private function normalizeRequirements(array $requirements, string $kind): array
    {
        $normalized = [];

        foreach (array_keys($requirements) as $index) {
            $entry = JsonArray::fromValue($requirements[$index]);
            if (!is_array($entry)) {
                continue;
            }

            $key = trim(ScalarValue::text($entry['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $type = strtolower(trim(ScalarValue::text($entry['type'] ?? '')));
            $allowedTypes = ['text', 'number', 'password'];
            if (!in_array($type, $allowedTypes, true)) {
                $type = $kind === 'secret' ? 'password' : 'text';
            }

            $scope = strtolower(trim(ScalarValue::text($entry['scope'] ?? 'customer_allowed')));
            if (!in_array($scope, ['admin_only', 'customer_allowed'], true)) {
                $scope = 'customer_allowed';
            }

            $label = trim(ScalarValue::text($entry['label'] ?? $key));
            $normalized[] = [
                'key' => $key,
                'label' => $label !== '' && $label !== '0' ? $label : $key,
                'type' => $type,
                'required' => (bool) ($entry['required'] ?? false),
                'scope' => $scope,
                'validation' => self::normalizeValidation($entry['validation'] ?? null),
                'helptext' => trim(ScalarValue::text($entry['helptext'] ?? '')),
            ];
        }

        return $normalized;
    }

    /** @return string|RequirementValidation|null */
    private static function normalizeValidation(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }
        $normalized = [];
        $strings = JsonMap::strings($value);
        if (isset($strings['pattern'])) {
            $normalized['pattern'] = $strings['pattern'];
        }
        foreach (['min', 'max'] as $key) {
            $number = self::validationNumber($value[$key] ?? null);
            if ($number !== null) {
                $normalized[$key] = $number;
            }
        }
        return $normalized;
    }

    private static function validationNumber(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function usesSteamLogin(Template $template): bool
    {
        $installCommand = $template->getInstallCommand();
        if ($installCommand === '') {
            return false;
        }

        return str_contains($installCommand, '{{STEAM_LOGIN}}')
            || str_contains($installCommand, '{{STEAM_ACCOUNT}}')
            || str_contains($installCommand, '{{STEAM_PASSWORD}}');
    }

    private function resolveSteamLoginMode(Instance $instance): string
    {
        $vars = $instance->getSetupVars();
        $mode = strtolower(trim(ScalarValue::text($vars['STEAM_LOGIN_MODE'] ?? '')));
        if (in_array($mode, ['anonymous', 'account'], true)) {
            return $mode;
        }

        $account = $instance->getSteamAccount();
        return $account !== null && $account !== '' && $account !== '0' ? 'account' : 'anonymous';
    }

    private function shouldSkipSteamCredential(string $key, string $loginMode): bool
    {
        if ($loginMode !== 'account') {
            return in_array($key, ['STEAM_ACCOUNT', 'STEAM_PASSWORD'], true);
        }

        return false;
    }

    /**
     * @param array<int, array{key: string, label: string, type: string}> $missing
     * @return array<int, array{key: string, label: string, type: string}>
     */
    private function appendSteamLoginMissing(Instance $instance, array $missing): array
    {
        $missingKeys = [];
        foreach ($missing as $entry) {
            $missingKeys[$entry['key']] = true;
        }

        if ($instance->getSteamAccount() === null || $instance->getSteamAccount() === '') {
            if (!isset($missingKeys['STEAM_ACCOUNT'])) {
                $missing[] = [
                    'key' => 'STEAM_ACCOUNT',
                    'label' => 'Steam Benutzername',
                    'type' => 'var',
                ];
            }
        }

        $vars = $instance->getSetupVars();
        $password = trim(ScalarValue::text($vars['STEAM_PASSWORD'] ?? ''));
        if ($password === '') {
            if (!isset($missingKeys['STEAM_PASSWORD'])) {
                $missing[] = [
                    'key' => 'STEAM_PASSWORD',
                    'label' => 'Steam Passwort',
                    'type' => 'var',
                ];
            }
        }

        return $missing;
    }
}
