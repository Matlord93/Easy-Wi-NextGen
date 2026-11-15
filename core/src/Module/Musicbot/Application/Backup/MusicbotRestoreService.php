<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Module\Musicbot\Domain\Entity\MusicbotAutoDjSettings;
use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Config\ScalarValue;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylist;
use App\Module\Musicbot\Domain\Entity\MusicbotPlaylistItem;
use App\Module\Musicbot\Domain\Entity\MusicbotPlugin;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;
use App\Module\Musicbot\Domain\Enum\MusicbotAutoDjMode;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use App\Module\Musicbot\Domain\Enum\MusicbotPlaylistVisibility;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotRestoreService
{
    private const EXCLUDED_CONFIG_FIELDS = [
        'control_sock',
        'runtime_socket',
        'tmp_path',
        'log_path',
        'pid_file',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotBackupDataProvider $dataProvider,
    ) {
    }

    public function restore(MusicbotInstance $instance, MusicbotBackupManifest $manifest, bool $dryRun = false): MusicbotRestoreReport
    {
        $warnings = [];
        $restored = [];
        $missing = [];

        try {
            $data = $manifest->data;

            if (isset($data['instance'])) {
                $this->restoreInstanceConfig($instance, $data['instance'], $dryRun);
                $restored[] = 'instance_config';
            }

            if (isset($data['connections'])) {
                $count = $this->restoreConnections($instance, (array) $data['connections'], $dryRun);
                $restored[] = "connections ({$count})";
            }

            if (isset($data['playlists'])) {
                [$count, $w] = $this->restorePlaylists($instance, (array) $data['playlists'], $dryRun);
                $restored[] = "playlists ({$count})";
                $warnings = array_merge($warnings, $w);
            }

            if (isset($data['radio_stations'])) {
                $count = $this->restoreRadioStations($instance, (array) $data['radio_stations'], $dryRun);
                $restored[] = "radio_stations ({$count})";
            }

            if (isset($data['autodj'])) {
                $this->restoreAutoDj($instance, (array) $data['autodj'], $dryRun);
                $restored[] = 'autodj';
            }

            if (isset($data['plugins'])) {
                $count = $this->restorePlugins($instance, (array) $data['plugins'], $dryRun);
                $restored[] = "plugins ({$count})";
            }

            foreach (['control_sock', 'runtime', 'tmp', 'logs'] as $excluded) {
                if (isset($data[$excluded])) {
                    $warnings[] = "Skipped non-restorable section: {$excluded}";
                }
            }
        } catch (\Throwable $e) {
            return new MusicbotRestoreReport(
                success: false,
                dryRun: $dryRun,
                restored: $restored,
                warnings: $warnings,
                missing: $missing,
                error: $e->getMessage(),
            );
        }

        return new MusicbotRestoreReport(
            success: true,
            dryRun: $dryRun,
            restored: $restored,
            warnings: $warnings,
            missing: $missing,
            error: null,
        );
    }

    private function restoreInstanceConfig(MusicbotInstance $instance, mixed $instanceData, bool $dryRun): void
    {
        if (!is_array($instanceData)) {
            return;
        }

        $config = JsonMap::fromValue($instanceData['instance_config'] ?? null) ?? [];

        foreach (self::EXCLUDED_CONFIG_FIELDS as $field) {
            unset($config[$field]);
        }

        if (!$dryRun) {
            if (isset($instanceData['name']) && is_string($instanceData['name'])) {
                $instance->setName($instanceData['name']);
            }

            $existing = $instance->getInstanceConfig();
            $merged = array_merge($existing, $config);

            foreach (self::EXCLUDED_CONFIG_FIELDS as $field) {
                if (isset($existing[$field])) {
                    self::setField($merged, $field, $existing[$field]);
                }
            }

            $instance->setInstanceConfig($merged);
            $this->entityManager->persist($instance);
            $this->entityManager->flush();
        }
    }

    private function restoreConnections(MusicbotInstance $instance, array $connectionsData, bool $dryRun): int
    {
        $count = 0;

        foreach ($connectionsData as $connData) {
            if (!is_array($connData)) {
                continue;
            }

            $platform = MusicbotPlatform::tryFrom((string) ($connData['platform'] ?? ''));
            if ($platform === null) {
                continue;
            }

            if (!$dryRun) {
                $existing = null;
                foreach ($this->dataProvider->getConnections($instance) as $conn) {
                    if ($conn->getPlatform() === $platform) {
                        $existing = $conn;
                        break;
                    }
                }

                $connectionConfig = JsonMap::fromValue($connData['connection_config'] ?? null) ?? [];
                $secretConfig = JsonMap::fromValue($connData['secret_config'] ?? null) ?? [];

                if ($existing !== null) {
                    $existing->setConnectionConfig($connectionConfig);
                } else {
                    $existing = new MusicbotConnection($instance, $platform, $connectionConfig, $secretConfig);
                    $existing->setEnabled((bool) ($connData['enabled'] ?? true));
                    $this->entityManager->persist($existing);
                }

                $this->entityManager->flush();
            }

            ++$count;
        }

        return $count;
    }

    /** @return array{int, list<string>} */
    private function restorePlaylists(MusicbotInstance $instance, array $playlistsData, bool $dryRun): array
    {
        $count = 0;
        $warnings = [];

        foreach ($playlistsData as $plData) {
            if (!is_array($plData)) {
                continue;
            }

            $name = (string) ($plData['name'] ?? '');
            if ($name === '') {
                $warnings[] = 'Skipped playlist with empty name.';
                continue;
            }

            if (!$dryRun) {
                $playlist = null;
                foreach ($this->dataProvider->getPlaylists($instance) as $pl) {
                    if ($pl->getName() === $name) {
                        $playlist = $pl;
                        break;
                    }
                }

                $visibility = MusicbotPlaylistVisibility::tryFrom((string) ($plData['visibility'] ?? ''))
                    ?? MusicbotPlaylistVisibility::Private;

                if ($playlist === null) {
                    $playlist = new MusicbotPlaylist($instance->getCustomer(), $name, $instance);
                    $this->entityManager->persist($playlist);
                }

                $playlist->setVisibility($visibility);
                $playlist->setDescription(self::optionalText($plData['description'] ?? null));
                $playlist->setSortOrder((int) ($plData['sort_order'] ?? 0));
                $this->entityManager->flush();

                $itemsData = (array) ($plData['items'] ?? []);
                foreach (array_keys($itemsData) as $itemIndex) {
                    $itemData = JsonArray::fromValue($itemsData[$itemIndex]);
                    if ($itemData === null) {
                        continue;
                    }

                    $sha256 = (string) ($itemData['track_sha256'] ?? '');
                    $track = $sha256 !== '' ? $this->dataProvider->findTrackBySha256($instance, $sha256) : null;

                    if ($track === null) {
                        $warnings[] = sprintf(
                            'Track "%s" (sha256: %s) not found for playlist "%s".',
                            ScalarValue::text($itemData['track_title'] ?? ''),
                            $sha256,
                            $name,
                        );
                        continue;
                    }

                    $existingItems = $this->dataProvider->getPlaylistItems($playlist);
                    $alreadyExists = false;
                    foreach ($existingItems as $existingItem) {
                        if ($existingItem->getTrack()->getSha256() === $sha256) {
                            $alreadyExists = true;
                            break;
                        }
                    }

                    if (!$alreadyExists) {
                        $item = new MusicbotPlaylistItem($playlist, $track, (int) ($itemData['position'] ?? 0));
                        $item->setMetadata(JsonMap::fromValue($itemData['metadata'] ?? null) ?? []);
                        $this->entityManager->persist($item);
                    }
                }

                $this->entityManager->flush();
            }

            ++$count;
        }

        return [$count, $warnings];
    }

    private function restoreRadioStations(MusicbotInstance $instance, array $stationsData, bool $dryRun): int
    {
        $count = 0;

        foreach ($stationsData as $stData) {
            if (!is_array($stData)) {
                continue;
            }

            $name = (string) ($stData['name'] ?? '');
            $url = (string) ($stData['stream_url'] ?? '');

            if ($name === '' || $url === '') {
                continue;
            }

            if (!$dryRun) {
                $existing = null;
                foreach ($this->dataProvider->getRadioStations($instance) as $st) {
                    if ($st->getName() === $name) {
                        $existing = $st;
                        break;
                    }
                }

                if ($existing === null) {
                    $station = new MusicbotRadioStation($instance->getCustomer(), $name, $url);
                    $station->setInstance($instance);
                    $station->setGenre(self::optionalText($stData['genre'] ?? null));
                    $station->setDescription(self::optionalText($stData['description'] ?? null));
                    $station->setHomepage(self::optionalText($stData['homepage'] ?? null));
                    $station->setLogoUrl(self::optionalText($stData['logo_url'] ?? null));
                    $station->setCountry(self::optionalText($stData['country'] ?? null));
                    $station->setLanguage(self::optionalText($stData['language'] ?? null));
                    $this->entityManager->persist($station);
                    $this->entityManager->flush();
                }
            }

            ++$count;
        }

        return $count;
    }

    private function restoreAutoDj(MusicbotInstance $instance, array $djData, bool $dryRun): void
    {
        if (empty($djData)) {
            return;
        }

        if (!$dryRun) {
            $settings = $this->dataProvider->getAutoDjSettings($instance);

            if ($settings === null) {
                $settings = new MusicbotAutoDjSettings($instance->getCustomer(), $instance);
                $this->entityManager->persist($settings);
            }

            $settings->setEnabled((bool) ($djData['enabled'] ?? false));
            $mode = MusicbotAutoDjMode::tryFrom((string) ($djData['mode'] ?? '')) ?? MusicbotAutoDjMode::Random;
            $settings->setMode($mode);
            $settings->setAvoidRepeats((bool) ($djData['avoid_repeats'] ?? true));
            $settings->setMinQueueSize((int) ($djData['min_queue_size'] ?? 2));
            $settings->setShuffle((bool) ($djData['shuffle'] ?? true));
            $settings->setRepeat((bool) ($djData['repeat'] ?? false));
            $settings->setIdleSeconds((int) ($djData['idle_seconds'] ?? 60));
            $settings->setVolumeOverride(isset($djData['volume_override']) ? (int) $djData['volume_override'] : null);

            $this->entityManager->flush();
        }
    }

    private function restorePlugins(MusicbotInstance $instance, array $pluginsData, bool $dryRun): int
    {
        $count = 0;

        foreach ($pluginsData as $pluginData) {
            if (!is_array($pluginData)) {
                continue;
            }

            $identifier = (string) ($pluginData['identifier'] ?? '');
            if ($identifier === '') {
                continue;
            }

            if (!$dryRun) {
                $plugin = null;
                foreach ($this->dataProvider->getPlugins($instance) as $p) {
                    if ($p->getIdentifier() === $identifier) {
                        $plugin = $p;
                        break;
                    }
                }

                if ($plugin === null) {
                    $plugin = new MusicbotPlugin(
                        $identifier,
                        (string) ($pluginData['name'] ?? $identifier),
                        (string) ($pluginData['version'] ?? '0.0.0'),
                        $instance->getCustomer(),
                        $instance,
                    );
                    $this->entityManager->persist($plugin);
                }

                $plugin->setEnabled((bool) ($pluginData['enabled'] ?? false));
                $plugin->setConfig(JsonMap::fromValue($pluginData['config'] ?? null) ?? []);
                $plugin->setPermissions(JsonMap::fromValue($pluginData['permissions'] ?? null) ?? []);

                $this->entityManager->flush();
            }

            ++$count;
        }

        return $count;
    }
    private static function optionalText(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @template TValue
     * @param array<string, TValue> $fields
     * @param TValue $value
     */
    private static function setField(array &$fields, string $key, mixed $value): void
    {
        $fields[$key] = $value;
    }

}
