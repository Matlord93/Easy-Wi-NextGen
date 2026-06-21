<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Application\Dto\PluginManifest;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlugin;
use App\Repository\MusicbotPluginRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotPluginService
{
    public function __construct(
        private readonly MusicbotPluginRepository $pluginRepository,
        private readonly PluginRegistryService $registryService,
        private readonly PluginConfigService $configService,
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotQuotaService $quotaService,
    ) {
    }

    /** @return PluginManifest[] */
    public function availableManifests(): array
    {
        return $this->registryService->listManifests();
    }

    /** @return MusicbotPlugin[] */
    public function pluginsForInstance(User $customer, MusicbotInstance $instance): array
    {
        return array_values(array_filter(
            $this->pluginRepository->findByCustomer($customer),
            static fn (MusicbotPlugin $plugin): bool => $plugin->getInstance() === null || $plugin->getInstance() === $instance,
        ));
    }

    public function assignPlugin(User $customer, MusicbotInstance $instance, string $identifier): MusicbotPlugin
    {
        $manifest = $this->requireManifest($identifier);
        $existing = $this->pluginRepository->findOneBy([
            'customer' => $customer,
            'instance' => $instance,
            'identifier' => $manifest->identifier,
        ]);
        if (!$existing instanceof MusicbotPlugin) {
            $this->quotaService->assertCanAssignPlugin($customer);
        }
        $plugin = $this->pluginRepository->findOneBy([
            'customer' => $customer,
            'instance' => $instance,
            'identifier' => $manifest->identifier,
        ]);
        if (!$plugin instanceof MusicbotPlugin) {
            $plugin = new MusicbotPlugin($manifest->identifier, $manifest->name, $manifest->version, $customer, $instance, [], $manifest->permissions);
            $this->entityManager->persist($plugin);
        }
        $plugin->setName($manifest->name);
        $plugin->setVersion($manifest->version);
        $plugin->setPermissions($manifest->permissions);
        $plugin->setInstance($instance);
        $plugin->setCustomer($customer);

        return $plugin;
    }

    public function setEnabled(User $customer, MusicbotPlugin $plugin, bool $enabled): void
    {
        $this->assertPluginCustomer($customer, $plugin);
        $plugin->setEnabled($enabled);
    }

    /** @param array<string, mixed> $config */
    public function saveConfig(User $customer, MusicbotPlugin $plugin, array $config): void
    {
        $this->assertPluginCustomer($customer, $plugin);
        $manifest = $this->requireManifest($plugin->getIdentifier());
        $this->configService->saveConfig($plugin, $manifest, $config);
    }

    public function findPluginForCustomer(int $id, User $customer): ?MusicbotPlugin
    {
        return $this->pluginRepository->findOneForCustomer($id, $customer);
    }

    public function requireManifest(string $identifier): PluginManifest
    {
        $manifest = $this->registryService->findManifest(strtolower(trim($identifier)));
        if (!$manifest instanceof PluginManifest) {
            throw new \InvalidArgumentException('Plugin manifest not found.');
        }

        return $manifest;
    }

    private function assertPluginCustomer(User $customer, MusicbotPlugin $plugin): void
    {
        if ($plugin->getCustomer() !== $customer) {
            throw new \InvalidArgumentException('Plugin does not belong to this customer.');
        }
    }
}
