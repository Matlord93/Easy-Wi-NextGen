<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\ScalarValue;
use App\Module\Ports\Infrastructure\Repository\PortBlockFinderInterface;
use App\Repository\MinecraftVersionCatalogRepositoryInterface;
use App\Repository\SharedStorageTemplateLocatorInterface;

/**
 * @psalm-type SharedPath = array{source:string,target:string,mode:string,readonly:bool,exclude:list<string>}
 * @psalm-type JobPayload = array{ports: array<array-key,int>, port_reservations:list<array{role:string,protocol:string,port:int}>, env_vars:list<array{key:string,value:string}>, secrets:list<array{key:string,placeholder:string}>, shared_paths?:list<SharedPath>, ...<string,string>}
 */
final class InstanceJobPayloadBuilder
{
    private MinecraftCatalogService $minecraftCatalogService;
    private bool $managedCatalogAvailable;
    private MinecraftJavaVersionResolver $minecraftJavaVersionResolver;

    public function __construct(
        private readonly TemplateInstallResolver $templateInstallResolver,
        private readonly PortBlockFinderInterface $portBlockRepository,
        private readonly SharedStorageTemplateLocatorInterface $templateRepository,
        ?MinecraftCatalogService $minecraftCatalogService = null,
        ?MinecraftJavaVersionResolver $minecraftJavaVersionResolver = null,
        ?JavaBinaryConfig $javaBinaryConfig = null,
    ) {
        $this->managedCatalogAvailable = $minecraftCatalogService !== null;
        $this->minecraftCatalogService = $minecraftCatalogService ?? new MinecraftCatalogService(new class () implements MinecraftVersionCatalogRepositoryInterface {
            #[\Override]
            public function findVersionsByChannel(string $channel, bool $activeOnly = true): array
            {
                return [];
            }
            #[\Override]
            public function findBuildsGroupedByVersion(string $channel, bool $activeOnly = true): array
            {
                return [];
            }
            #[\Override]
            public function findActiveByChannel(string $channel): array
            {
                return [];
            }
            #[\Override]
            public function findLatestVersion(string $channel, bool $activeOnly = true): ?string
            {
                return null;
            }
            #[\Override]
            public function findLatestBuild(string $channel, string $version, bool $activeOnly = true): ?string
            {
                return null;
            }
            #[\Override]
            public function findEntry(string $channel, string $version, ?string $build, bool $activeOnly = true): ?\App\Module\Core\Domain\Entity\MinecraftVersionCatalog
            {
                return null;
            }
            #[\Override]
            public function versionExists(string $channel, string $version, bool $activeOnly = true): bool
            {
                return false;
            }
            #[\Override]
            public function buildExists(string $channel, string $version, string $build, bool $activeOnly = true): bool
            {
                return false;
            }
        });
        $this->minecraftJavaVersionResolver = $minecraftJavaVersionResolver ?? new MinecraftJavaVersionResolver($javaBinaryConfig);
    }

    /**
     * @return JobPayload
     */
    public function buildSniperInstallPayload(Instance $instance, bool $useSharedStorage = false): array
    {
        $payload = $this->buildBasePayload($instance);
        if ($useSharedStorage) {
            $template = $instance->getTemplate();
            $sharedTemplate = $template->supportsSharedStorage()
                ? $template
                : ($this->templateRepository->findSharedStorageVariantForIdentity($template) ?? $template);
            $sharedPaths = $sharedTemplate->getSharedPaths();
            if ($sharedPaths === []) {
                throw new \RuntimeException('Template does not support shared storage.');
            }
            $payload['template_id'] = (string) ($sharedTemplate->getId() ?? '');
            $payload['shared_paths'] = $sharedPaths;
            $payload['use_shared_storage'] = 'true';
        }

        return $payload;
    }

    /**
     * @return JobPayload
     */
    public function buildSniperUpdatePayload(Instance $instance, ?string $targetBuildId = null, ?string $targetVersion = null): array
    {
        $payload = $this->buildBasePayload($instance, true, $targetVersion, $targetBuildId);

        if ($targetBuildId !== null) {
            $payload['target_build_id'] = $targetBuildId;
        }
        if ($targetVersion !== null) {
            $payload['target_version'] = $targetVersion;
        }

        return $payload;
    }

    /**
     * @return JobPayload&array{config_files:list<array{path:string,content_base64:string}>}
     */
    public function buildRuntimePayload(Instance $instance): array
    {
        $payload = $this->buildBasePayload($instance, false);
        $payload['config_files'] = $this->buildConfigOverridePayload($instance);

        return $payload;
    }

    /**
     * @return JobPayload
     */
    private function buildBasePayload(Instance $instance, bool $install = true, ?string $targetVersion = null, ?string $targetBuild = null): array
    {
        $template = $instance->getTemplate();
        $payload = [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'template_id' => (string) $template->getId(),
            'game_key' => $template->getGameKey(),
            'game_type' => $template->getGameKey(),
            'display_name' => $template->getDisplayName(),
            'steam_app_id' => $template->getSteamAppId() !== null ? (string) $template->getSteamAppId() : '',
            'sniper_profile' => $template->getSniperProfile() ?? '',
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'cpu_limit' => (string) $instance->getCpuLimit(),
            'ram_limit' => (string) $instance->getRamLimit(),
            'disk_limit' => (string) $instance->getDiskLimit(),
            'install_command' => $this->templateInstallResolver->resolveInstallCommand($instance),
            'update_command' => $this->templateInstallResolver->resolveUpdateCommand($instance),
            'start_params' => $template->getStartParams(),
            'required_ports' => implode(',', array_column(MinecraftPortPlan::forInstance($instance), 'name')),
            'env_vars' => $this->buildEnvVars($instance),
            'secrets' => $this->buildSecretPlaceholders($instance),
        ];
        $payload['shared_runtime_mode'] = $this->resolveSharedRuntimeMode($template->getGameKey(), $template->getSharedPaths());

        if (($value = $instance->getInstanceBaseDir()) !== null) {
            $payload['base_dir'] = $value;
        }
        if (($value = $instance->getInstallPath()) !== null) {
            $payload['install_path'] = $value;
        }

        $payload = array_merge($payload, $this->buildPortPayload($instance));

        $payload['autostart'] = 'false';

        if (($value = $instance->getLockedBuildId()) !== null) {
            $payload['locked_build_id'] = $value;
        }
        if (($value = $instance->getLockedVersion()) !== null) {
            $payload['locked_version'] = $value;
        }

        $javaBin = $this->resolveMinecraftJavaBin($instance);
        if ($javaBin === null && str_contains($template->getStartParams(), '{{JAVA_BIN}}')) {
            $mcVersion = $instance->getLockedVersion() ?? $instance->getInstalledVersion();
            $javaBin = $this->minecraftJavaVersionResolver->javaBin($mcVersion, $instance->getInstalledJavaVersion());
        }
        if ($javaBin !== null) {
            $payload['java_bin'] = $javaBin;
            $payload['JAVA_BIN'] = $javaBin;
        }

        $channel = $this->minecraftCatalogService->channelFromResolver($instance);
        $managedCapable = ($instance->getNode()->getMetadata()['minecraft_managed_install'] ?? false) === true;
        $linux = strtolower((string) ($instance->getNode()->getMetadata()['os'] ?? $instance->getNode()->getLastHeartbeatStats()['os'] ?? 'linux')) !== 'windows';
        if ($channel !== null && $this->managedCatalogAvailable && $linux && $install && !$managedCapable) {
            throw new \RuntimeException('MINECRAFT_AGENT_UPDATE_REQUIRED');
        }
        if ($channel !== null && $this->managedCatalogAvailable && $linux && $managedCapable) {
            $version = $install ? ($targetVersion ?? $instance->getLockedVersion()) : ($instance->getInstalledVersion() ?? $instance->getLockedVersion());
            $build = $install ? ($targetBuild ?? $instance->getLockedBuildId()) : ($instance->getInstalledBuildId() ?? $instance->getLockedBuildId());
            $entry = $install ? $this->minecraftCatalogService->resolveEntry($channel, $version, $build) : $this->minecraftCatalogService->resolveInstalledEntry($channel, $version, $build);
            if ($entry === null && !$install && $version !== null) {
                $entry = new \App\Module\Core\Domain\Entity\MinecraftVersionCatalog($channel, $version, $build, '');
                $entry->setJavaVersion($instance->getInstalledJavaVersion());
            }
            if ($entry === null) {
                throw new \RuntimeException('No installable Minecraft catalog entry. Synchronize the catalog first.');
            }
            $payload['minecraft_managed'] = 'true';
            $payload['minecraft_channel'] = $channel;
            $payload['minecraft_version'] = $entry->getMcVersion();
            $payload['minecraft_build'] = $entry->getBuild() ?? '';
            $payload['minecraft_install_strategy'] = (string) ($entry->getMetadata()['strategy'] ?? 'download');
            $payload['minecraft_download_url'] = $entry->getDownloadUrl();
            $payload['minecraft_build_java_version'] = (string) ($entry->getMetadata()['build_java'] ?? '');
            $payload['minecraft_source_revision'] = (string) ($entry->getMetadata()['source_revision'] ?? '');
            $payload['minecraft_sha256'] = $entry->getSha256() ?? '';
            $payload['minecraft_sha1'] = (string) ($entry->getMetadata()['sha1'] ?? '');
            if ($channel !== 'bedrock') {
                $payload['minecraft_java_version'] = !$install && $instance->getInstalledJavaVersion() !== null
                    ? ($instance->getInstalledJavaVersion() ?? '')
                    : $this->minecraftJavaVersionResolver->resolve($entry->getMcVersion(), $entry->getJavaVersion());
            }
            // The agent owns configuration and Java selection, including legacy seeded templates.
            $payload['start_params'] = $channel === 'bedrock'
                ? 'LD_LIBRARY_PATH={{INSTANCE_DIR}} {{INSTANCE_DIR}}/bedrock_server'
                : '{{JAVA_BIN}} -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar'.($channel === 'bungeecord' ? '' : ' nogui');
            // This command also runs on agents that silently skip managed preparation.
            // Never report a successful installation just because an echo exited zero.
            $artifact = $channel === 'bedrock' ? 'bedrock_server' : 'server.jar';
            $check = $channel === 'bedrock' ? '-x' : '-r';
            $payload['install_command'] = sprintf(
                'if [ ! -f %1$s ] || [ ! -s %1$s ] || [ ! %2$s %1$s ]; then echo "MINECRAFT_ARTIFACT_MISSING: %1$s was not installed. Update and restart the Linux agent, then reinstall Minecraft." >&2; exit 1; fi; echo "Minecraft server artifact verified"',
                $artifact,
                $check,
            );
            $payload['update_command'] = $payload['install_command'];
        }

        return $payload;
    }


    private function resolveMinecraftJavaBin(Instance $instance): ?string
    {
        $channel = $this->minecraftCatalogService->channelFromResolver($instance);
        if (!in_array($channel, ['vanilla', 'paper', 'spigot', 'bungeecord'], true)) {
            return null;
        }

        $entry = $this->minecraftCatalogService->resolveEntry($channel, $instance->getLockedVersion(), $instance->getLockedBuildId());
        $mcVersion = $entry?->getMcVersion() ?? $instance->getLockedVersion() ?? $instance->getInstalledVersion();
        $javaVersion = $entry?->getJavaVersion() ?? ($instance->getInstalledJavaVersion() ?? '');

        return $this->minecraftJavaVersionResolver->javaBin($mcVersion, $javaVersion);
    }

    /**
     * @param array<int, array<string, mixed>> $sharedPaths
     */
    private function resolveSharedRuntimeMode(string $gameKey, array $sharedPaths): string
    {
        if (str_contains(strtolower($gameKey), 'minecraft')) {
            return 'none';
        }
        $hasBind = false;
        $hasOverlay = false;
        foreach ($sharedPaths as $path) {
            $mode = strtolower(trim((string) ($path['mode'] ?? '')));
            if ($mode === 'bind') {
                $hasBind = true;
            } elseif ($mode === 'overlay') {
                $hasOverlay = true;
            }
        }
        if ($hasBind && $hasOverlay) {
            return 'bind_overlay';
        }
        if ($hasOverlay) {
            return 'overlay';
        }
        if ($hasBind) {
            return 'bind';
        }
        return 'none';
    }

    /**
     * @return list<array{path: string, content_base64: string}>
     */
    private function buildConfigOverridePayload(Instance $instance): array
    {
        $entries = [];
        foreach ($instance->getConfigOverrides() as $path => $payload) {
            if ($path === '') {
                continue;
            }
            $content = $payload['content'] ?? null;
            if (!is_string($content)) {
                continue;
            }

            $entries[] = [
                'path' => $path,
                'content_base64' => base64_encode($content),
            ];
        }

        return $entries;
    }

    /**
     * @return array{ports:array<array-key,int>,port_reservations:list<array{role:string,protocol:string,port:int}>}
     */
    private function buildPortPayload(Instance $instance): array
    {
        $portBlock = $this->portBlockRepository->findByInstance($instance);
        if ($portBlock === null) {
            return [
                'ports' => [],
                'port_reservations' => [],
            ];
        }

        $ports = $portBlock->getPorts();
        $reservations = [];
        $requiredPorts = MinecraftPortPlan::forInstance($instance);
        foreach ($requiredPorts as $index => $definition) {
            if (!isset($ports[$index])) {
                continue;
            }

            $reservations[] = [
                'role' => $definition['name'],
                'protocol' => $definition['protocol'],
                'port' => $ports[$index],
            ];
        }

        return [
            'ports' => $ports,
            'port_reservations' => MinecraftPortPlan::withBedrockSignaling($instance->getTemplate(), $reservations),
        ];
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function buildEnvVars(Instance $instance): array
    {
        $vars = [];
        $envVars = $instance->getTemplate()->getEnvVars();
        foreach (array_keys($envVars) as $envIndex) {
            $entry = JsonArray::orEmpty($envVars[$envIndex]);
            $key = trim(ScalarValue::text($entry['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $vars[$key] = ScalarValue::text($entry['value'] ?? '');
        }

        $setupVarKeys = [];
        $setupVars = $instance->getSetupVars();
        foreach (array_keys($setupVars) as $key) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }
            $setupVarKeys[$normalizedKey] = true;
            $vars[$normalizedKey] = ScalarValue::text($setupVars[$key]);
        }

        if (($value = $instance->getSteamAccount()) !== null && $value !== '') {
            $vars['STEAM_ACCOUNT'] = $value;
        }
        if (($value = $instance->getGslKey()) !== null && $value !== '') {
            $vars['STEAM_GSLT'] = $value;
        }
        if (($value = $instance->getServerName()) !== null && $value !== '') {
            $vars['SERVER_NAME'] = $value;
        }

        $steamAccount = $vars['STEAM_ACCOUNT'] ?? '';
        $steamPassword = $vars['STEAM_PASSWORD'] ?? '';
        if ($steamAccount !== '' && $steamPassword !== '') {
            $vars['STEAM_LOGIN'] = sprintf('%s %s', $steamAccount, $steamPassword);
        } else {
            $vars['STEAM_LOGIN'] = 'anonymous';
        }

        if (!isset($setupVarKeys['SERVER_MEMORY']) && $instance->getRamLimit() > 0) {
            $vars['SERVER_MEMORY'] = (string) $instance->getRamLimit();
        }

        $javaBin = $this->resolveMinecraftJavaBin($instance);
        if ($javaBin !== null && (!isset($setupVarKeys['JAVA_BIN']) || ($vars['JAVA_BIN'] ?? '') === '')) {
            $vars['JAVA_BIN'] = $javaBin;
        }

        if (!isset($vars['JAVA_BIN']) && str_contains($instance->getTemplate()->getStartParams(), '{{JAVA_BIN}}')) {
            $mcVersion = $instance->getLockedVersion() ?? $instance->getInstalledVersion();
            $vars['JAVA_BIN'] = $this->minecraftJavaVersionResolver->javaBin($mcVersion, $instance->getInstalledJavaVersion());
        }

        foreach (['SERVER_PASSWORD', 'RCON_PASSWORD', 'ADMIN_PASSWORD', 'SERVER_ADMIN_PASSWORD'] as $passwordKey) {
            if (!isset($setupVarKeys[$passwordKey]) && isset($vars[$passwordKey]) && strtolower($vars[$passwordKey]) === 'change-me') {
                unset($vars[$passwordKey]);
            }
        }
        foreach (['SERVER_PASSWORD', 'RCON_PASSWORD'] as $optionalPasswordKey) {
            if (!isset($vars[$optionalPasswordKey])) {
                $vars[$optionalPasswordKey] = '';
            }
        }

        if ($this->minecraftCatalogService->channelFromResolver($instance) !== null) {
            // Leave native JVM overhead below the systemd memory ceiling.
            $heap = max(128, (int) floor((float) $instance->getRamLimit() * 0.75));
            $vars['JAVA_XMX'] = $heap.'M';
            $vars['JAVA_XMS'] = min(512, $heap).'M';
            $vars['MAX_PLAYERS'] = (string) ($instance->getCurrentSlots());
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
     * @return list<array{key: string, placeholder: string}>
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
}
