<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ModuleSetting;
use App\Entity\User;
use App\Enum\ModuleKey;
use App\Repository\ModuleSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ModuleRegistry
{
    /**
     * @var array<string, array{label: string, version: string, description: string, default_enabled?: bool}>
     */
    private const DEFINITIONS = [
        ModuleKey::Web->value => [
            'label' => 'Web Hosting',
            'version' => '1.0.0',
            'description' => 'Webspace provisioning and PHP runtime management.',
        ],
        ModuleKey::Mail->value => [
            'label' => 'Mail',
            'version' => '1.0.0',
            'description' => 'Mailboxes, aliases, and domain routing.',
        ],
        ModuleKey::Dns->value => [
            'label' => 'DNS',
            'version' => '1.0.0',
            'description' => 'DNS records and zone automation.',
        ],
        ModuleKey::Game->value => [
            'label' => 'Game',
            'version' => '1.0.0',
            'description' => 'Game server lifecycle management.',
        ],
        ModuleKey::Ts->value => [
            'label' => 'Teamspeak',
            'version' => '1.0.0',
            'description' => 'TS3 instances and voice service orchestration.',
        ],
        ModuleKey::Ts6->value => [
            'label' => 'Teamspeak 6 (Experimental)',
            'version' => '0.1.0',
            'description' => 'Planned TS6 lifecycle management (feature flagged).',
            'default_enabled' => false,
        ],
        ModuleKey::TsVirtual->value => [
            'label' => 'TS Virtual Servers (Experimental)',
            'version' => '0.1.0',
            'description' => 'Virtual server hosting on top of a TS6 node (feature flagged).',
            'default_enabled' => false,
        ],
        ModuleKey::Billing->value => [
            'label' => 'Billing',
            'version' => '1.0.0',
            'description' => 'Invoices, payments, and dunning workflows.',
        ],
    ];

    public function __construct(
        private readonly ModuleSettingRepository $moduleSettingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listModules(): array
    {
        $settings = $this->moduleSettingRepository->findAll();
        $indexed = [];
        foreach ($settings as $setting) {
            $indexed[$setting->getModuleKey()] = $setting;
        }

        $modules = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $setting = $indexed[$key] ?? null;
            $defaultEnabled = $definition['default_enabled'] ?? true;
            $modules[] = [
                'key' => $key,
                'label' => $definition['label'],
                'version' => $definition['version'],
                'description' => $definition['description'],
                'enabled' => $setting?->isEnabled() ?? $defaultEnabled,
                'updatedAt' => $setting?->getUpdatedAt(),
            ];
        }

        return $modules;
    }

    public function setEnabled(string $moduleKey, bool $enabled, User $actor): ModuleSetting
    {
        if (!array_key_exists($moduleKey, self::DEFINITIONS)) {
            throw new \InvalidArgumentException('Unknown module key.');
        }

        $definition = self::DEFINITIONS[$moduleKey];
        $setting = $this->moduleSettingRepository->find($moduleKey);

        $previousEnabled = $setting?->isEnabled() ?? true;

        if ($setting === null) {
            $setting = new ModuleSetting($moduleKey, $definition['version'], $enabled);
            $this->entityManager->persist($setting);
        } else {
            $setting->setVersion($definition['version']);
            $setting->setEnabled($enabled);
        }

        $this->auditLogger->log($actor, $enabled ? 'module.enabled' : 'module.disabled', [
            'module_key' => $moduleKey,
            'version' => $definition['version'],
            'previous_enabled' => $previousEnabled,
            'enabled' => $enabled,
        ]);

        return $setting;
    }

    public function isEnabled(string $moduleKey): bool
    {
        if (!array_key_exists($moduleKey, self::DEFINITIONS)) {
            return false;
        }

        $definition = self::DEFINITIONS[$moduleKey];
        $setting = $this->moduleSettingRepository->find($moduleKey);

        if ($setting !== null) {
            return $setting->isEnabled();
        }

        return $definition['default_enabled'] ?? true;
    }
}
