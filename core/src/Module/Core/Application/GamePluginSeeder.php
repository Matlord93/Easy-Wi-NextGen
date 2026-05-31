<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\GamePlugin;
use App\Module\Core\Domain\Entity\Template;
use App\Repository\GamePluginRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

final class GamePluginSeeder
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly GamePluginSeedCatalog $catalog,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{plugins: int, updated: int, skipped_missing_template: int, entries: int, missing_game_keys: array<int, string>}
     */
    public function seed(?EntityManagerInterface $entityManager = null, bool $updateExisting = false): array
    {
        $entityManager = $entityManager ?? $this->registry->getManager();
        $templateRepository = $entityManager->getRepository(Template::class);
        $pluginRepository = $entityManager->getRepository(GamePlugin::class);

        $pluginsCreated = 0;
        $pluginsUpdated = 0;
        $skippedMissingTemplate = 0;
        $missingGameKeys = [];
        $entries = $this->catalog->listPlugins();

        foreach ($entries as $pluginData) {
            $templateGameKey = strtolower(trim((string) ($pluginData['template_game_key'] ?? '')));
            $pluginName = trim((string) ($pluginData['name'] ?? ''));
            $pluginVersion = trim((string) ($pluginData['version'] ?? ''));
            if ($templateGameKey === '' || $pluginName === '' || $pluginVersion === '') {
                continue;
            }

            $template = $templateRepository->findOneBy(['gameKey' => $templateGameKey]);
            if (!$template instanceof Template) {
                $skippedMissingTemplate++;
                $missingGameKeys[$templateGameKey] = $templateGameKey;
                continue;
            }

            $existingPlugin = $pluginRepository instanceof GamePluginRepository
                ? $pluginRepository->findDuplicateForGameKey($templateGameKey, $pluginName, $pluginVersion)
                : $pluginRepository->findOneBy([
                    'template' => $template,
                    'name' => $pluginName,
                    'version' => $pluginVersion,
                ]);

            if ($existingPlugin instanceof GamePlugin) {
                if ($updateExisting) {
                    $existingPlugin->setTemplate($template);
                    $existingPlugin->setChecksum((string) ($pluginData['checksum'] ?? ''));
                    $existingPlugin->setDownloadUrl((string) ($pluginData['download_url'] ?? ''));
                    $existingPlugin->setDescription($pluginData['description'] ?? null);
                    $existingPlugin->setInstallMode((string) ($pluginData['install_mode'] ?? 'extract'));
                    $existingPlugin->setExtractSubdir(isset($pluginData['extract_subdir']) ? (string) $pluginData['extract_subdir'] : null);
                    $pluginsUpdated++;
                }

                continue;
            }

            $plugin = new GamePlugin(
                $template,
                $pluginName,
                $pluginVersion,
                (string) ($pluginData['checksum'] ?? ''),
                (string) ($pluginData['download_url'] ?? ''),
                $pluginData['description'] ?? null,
                (string) ($pluginData['install_mode'] ?? 'extract'),
                isset($pluginData['extract_subdir']) ? (string) $pluginData['extract_subdir'] : null,
            );

            $entityManager->persist($plugin);
            $pluginsCreated++;
        }

        if ($pluginsCreated > 0 || $pluginsUpdated > 0) {
            try {
                $entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $entityManager->clear();
                $pluginsCreated = 0;
                $pluginsUpdated = 0;
            }
        }

        if ($skippedMissingTemplate > 0) {
            $this->logger->warning('Skipped game plugin seed entries because templates were missing.', [
                'missing_game_keys' => array_values($missingGameKeys),
                'skipped' => $skippedMissingTemplate,
            ]);
        }

        return [
            'plugins' => $pluginsCreated,
            'updated' => $pluginsUpdated,
            'skipped_missing_template' => $skippedMissingTemplate,
            'entries' => count($entries),
            'missing_game_keys' => array_values($missingGameKeys),
        ];
    }
}
