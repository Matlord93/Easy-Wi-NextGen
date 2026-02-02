<?php

declare(strict_types=1);

namespace App\Module\Unifi\Application;

final class UnifiRuleDiff
{
    /**
     * @param array<string, UnifiRule> $desired
     * @param array<string, array<string, mixed>> $current
     *
     * @return array{create: UnifiRule[], update: array<int, array{rule: UnifiRule, currentId: string}>, delete: array<int, array{currentId: string, name: string}>}
     */
    public function diff(array $desired, array $current): array
    {
        $create = [];
        $update = [];
        $delete = [];

        foreach ($desired as $name => $rule) {
            if (!isset($current[$name])) {
                $create[] = $rule;
                continue;
            }

            $currentRule = $current[$name];
            if ($this->isDifferent($rule, $currentRule)) {
                $update[] = [
                    'rule' => $rule,
                    'currentId' => (string) ($currentRule['id'] ?? ''),
                ];
            }
        }

        foreach ($current as $name => $currentRule) {
            if (!isset($desired[$name])) {
                $delete[] = [
                    'currentId' => (string) ($currentRule['id'] ?? ''),
                    'name' => $name,
                ];
            }
        }

        return [
            'create' => $create,
            'update' => $update,
            'delete' => $delete,
        ];
    }

    /**
     * @param array<string, mixed> $current
     */
    private function isDifferent(UnifiRule $rule, array $current): bool
    {
        $currentPort = (int) ($current['port'] ?? $current['dst_port'] ?? 0);
        $currentTargetPort = (int) ($current['target_port'] ?? $current['fwd_port'] ?? 0);
        $currentTargetIp = (string) ($current['target_ip'] ?? $current['fwd'] ?? '');
        $currentProtocol = strtolower((string) ($current['protocol'] ?? $current['proto'] ?? ''));
        $currentEnabled = isset($current['enabled']) ? (bool) $current['enabled'] : true;

        return $currentPort !== $rule->getPort()
            || $currentTargetPort !== $rule->getTargetPort()
            || $currentTargetIp !== $rule->getTargetIp()
            || $currentProtocol !== strtolower($rule->getProtocol())
            || $currentEnabled !== $rule->isEnabled();
    }
}
