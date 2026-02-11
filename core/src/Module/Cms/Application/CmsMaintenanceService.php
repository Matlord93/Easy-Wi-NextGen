<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\Site;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

final class CmsMaintenanceService
{
    public function __construct(private readonly AppSettingsService $settingsService)
    {
    }

    /**
     * @return array{active: bool, scope: string|null, message: string, graphic_path: string, starts_at: ?\DateTimeImmutable, ends_at: ?\DateTimeImmutable}
     */
    public function resolve(Request $request, Site $site): array
    {
        $settings = $this->settingsService->getSettings();

        $global = [
            'enabled' => (bool) ($settings[AppSettingsService::KEY_CMS_MAINTENANCE_ENABLED] ?? false),
            'message' => (string) ($settings[AppSettingsService::KEY_CMS_MAINTENANCE_MESSAGE] ?? ''),
            'graphic_path' => (string) ($settings[AppSettingsService::KEY_CMS_MAINTENANCE_GRAPHIC] ?? ''),
            'allowlist' => (string) ($settings[AppSettingsService::KEY_CMS_MAINTENANCE_ALLOWLIST] ?? ''),
            'starts_at' => $this->parseDateTime($settings[AppSettingsService::KEY_CMS_MAINTENANCE_STARTS_AT] ?? null),
            'ends_at' => $this->parseDateTime($settings[AppSettingsService::KEY_CMS_MAINTENANCE_ENDS_AT] ?? null),
        ];

        $siteConfig = [
            'enabled' => $site->isMaintenanceEnabled(),
            'message' => $site->getMaintenanceMessage(),
            'graphic_path' => $site->getMaintenanceGraphicPath(),
            'allowlist' => $site->getMaintenanceAllowlist(),
            'starts_at' => $site->getMaintenanceStartsAt(),
            'ends_at' => $site->getMaintenanceEndsAt(),
        ];

        $clientIp = $request->getClientIp();
        $allowlist = array_merge(
            $this->parseAllowlist($global['allowlist']),
            $this->parseAllowlist($siteConfig['allowlist']),
        );

        if ($clientIp !== null && $allowlist !== [] && IpUtils::checkIp($clientIp, $allowlist)) {
            return [
                'active' => false,
                'scope' => null,
                'message' => '',
                'graphic_path' => '',
                'starts_at' => null,
                'ends_at' => null,
            ];
        }

        $now = new \DateTimeImmutable();
        if ($this->isActive($siteConfig['enabled'], $siteConfig['starts_at'], $siteConfig['ends_at'], $now)) {
            return [
                'active' => true,
                'scope' => 'site',
                'message' => $siteConfig['message'],
                'graphic_path' => $siteConfig['graphic_path'],
                'starts_at' => $siteConfig['starts_at'],
                'ends_at' => $siteConfig['ends_at'],
            ];
        }

        if ($this->isActive($global['enabled'], $global['starts_at'], $global['ends_at'], $now)) {
            return [
                'active' => true,
                'scope' => 'global',
                'message' => $global['message'],
                'graphic_path' => $global['graphic_path'],
                'starts_at' => $global['starts_at'],
                'ends_at' => $global['ends_at'],
            ];
        }

        return [
            'active' => false,
            'scope' => null,
            'message' => '',
            'graphic_path' => '',
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parseAllowlist(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $parts = preg_split('/[\n,]+/', $raw) ?: [];
        $parts = array_filter(array_map('trim', $parts), static fn (string $entry): bool => $entry !== '');

        return array_values($parts);
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i', $value)
            ?: date_create_immutable($value);

        return $parsed instanceof \DateTimeImmutable ? $parsed : null;
    }

    private function isActive(bool $enabled, ?\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt, \DateTimeImmutable $now): bool
    {
        if (!$enabled) {
            return false;
        }

        if ($startsAt !== null && $now < $startsAt) {
            return false;
        }

        if ($endsAt !== null && $now > $endsAt) {
            return false;
        }

        return true;
    }
}
