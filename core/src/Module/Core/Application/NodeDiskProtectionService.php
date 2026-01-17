<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;

final class NodeDiskProtectionService
{
    private const DISK_STAT_KEY = 'disk_stat';
    private const PROTECTION_KEY = 'disk_protection';

    /**
     * @return array{free_bytes: int, free_percent: float, checked_at: \DateTimeImmutable}|null
     */
    public function getDiskStat(Agent $node): ?array
    {
        $metadata = $node->getMetadata();
        if (!is_array($metadata)) {
            return null;
        }

        $stat = $metadata[self::DISK_STAT_KEY] ?? null;
        if (!is_array($stat)) {
            return null;
        }

        $freeBytes = is_numeric($stat['free_bytes'] ?? null) ? (int) $stat['free_bytes'] : null;
        $freePercent = is_numeric($stat['free_percent'] ?? null) ? (float) $stat['free_percent'] : null;
        $checkedAtRaw = $stat['checked_at'] ?? null;
        if ($freeBytes === null || $freePercent === null || !is_string($checkedAtRaw) || $checkedAtRaw === '') {
            return null;
        }

        try {
            $checkedAt = new \DateTimeImmutable($checkedAtRaw);
        } catch (\Exception) {
            return null;
        }

        return [
            'free_bytes' => $freeBytes,
            'free_percent' => $freePercent,
            'checked_at' => $checkedAt,
        ];
    }

    public function isProtectionActive(Agent $node, \DateTimeImmutable $now): bool
    {
        if ($this->isOverrideActive($node, $now)) {
            return false;
        }

        $stat = $this->getDiskStat($node);
        if ($stat === null) {
            return false;
        }

        return $stat['free_percent'] < $node->getNodeDiskProtectionThresholdPercent();
    }

    public function isOverrideActive(Agent $node, \DateTimeImmutable $now): bool
    {
        $overrideUntil = $node->getNodeDiskProtectionOverrideUntil();
        if ($overrideUntil === null) {
            return false;
        }

        return $overrideUntil > $now;
    }

    /**
     * @return array{previous: bool, current: bool}
     */
    public function updateDiskStat(Agent $node, int $freeBytes, float $freePercent, \DateTimeImmutable $checkedAt): array
    {
        $metadata = $node->getMetadata() ?? [];
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $previousProtection = false;
        $previous = $metadata[self::PROTECTION_KEY] ?? null;
        if (is_array($previous) && array_key_exists('active', $previous)) {
            $previousProtection = (bool) $previous['active'];
        }

        $currentProtection = $freePercent < $node->getNodeDiskProtectionThresholdPercent();
        $metadata[self::DISK_STAT_KEY] = [
            'free_bytes' => $freeBytes,
            'free_percent' => $freePercent,
            'checked_at' => $checkedAt->format(DATE_RFC3339),
        ];
        $metadata[self::PROTECTION_KEY] = [
            'active' => $currentProtection,
            'updated_at' => $checkedAt->format(DATE_RFC3339),
        ];

        $node->setMetadata($metadata);

        return ['previous' => $previousProtection, 'current' => $currentProtection];
    }
}
