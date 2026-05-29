<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\MinecraftVersionCatalog;
use App\Module\Gameserver\Application\MinecraftJavaVersionResolver;
use App\Repository\MinecraftVersionCatalogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MinecraftVersionImportService
{
    public const CHANNELS = ['vanilla', 'paper', 'bedrock'];

    public function __construct(
        private readonly MinecraftVersionCatalogRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MinecraftJavaVersionResolver $javaVersionResolver,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%/../minecraft-server-jar-downloads.md')]
        private readonly string $vanillaPath,
        #[Autowire('%kernel.project_dir%/../paper-versions.json')]
        private readonly string $paperPath,
        #[Autowire('%kernel.project_dir%/../bedrock-server-downloads.json')]
        private readonly string $bedrockPath,
    ) {
    }

    /**
     * @param array<int, string> $channels
     * @return array{created:int,updated:int,skipped:int,deactivated:int,errors:array<int,string>,dryRun:bool}
     */
    public function import(array $channels, bool $dryRun = false, bool $force = false, bool $deactivateMissing = false): array
    {
        $channels = array_values(array_intersect(self::CHANNELS, array_unique($channels)));
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'deactivated' => 0, 'errors' => [], 'dryRun' => $dryRun];
        $seen = [];

        foreach ($channels as $channel) {
            try {
                $items = $this->readChannel($channel);
            } catch (\RuntimeException $exception) {
                $summary['errors'][] = $exception->getMessage();
                continue;
            }

            foreach ($items as $item) {
                $key = $this->key($item['channel'], $item['mcVersion'], $item['build']);
                if (isset($seen[$key])) {
                    $summary['skipped']++;
                    continue;
                }
                $seen[$key] = true;
                if (!filter_var($item['downloadUrl'], FILTER_VALIDATE_URL)) {
                    $summary['skipped']++;
                    continue;
                }

                $entry = $this->repository->findOneBy([
                    'channel' => $item['channel'],
                    'mcVersion' => $item['mcVersion'],
                    'build' => $item['build'],
                ]);

                if ($entry instanceof MinecraftVersionCatalog && $entry->getSource() === 'manual' && !$force) {
                    $summary['skipped']++;
                    continue;
                }

                if (!$entry instanceof MinecraftVersionCatalog) {
                    $summary['created']++;
                    if (!$dryRun) {
                        $entry = new MinecraftVersionCatalog($item['channel'], $item['mcVersion'], $item['build'], $item['downloadUrl'], $item['sha256'], $item['releasedAt']);
                        $this->applyImportFields($entry, $item);
                        $this->entityManager->persist($entry);
                    }
                    continue;
                }

                $summary['updated']++;
                if (!$dryRun) {
                    $this->applyImportFields($entry, $item);
                    $this->entityManager->persist($entry);
                }
            }
        }

        if ($deactivateMissing) {
            foreach ($channels as $channel) {
                foreach ($this->repository->findBy(['channel' => $channel, 'source' => 'import', 'isActive' => true]) as $entry) {
                    if (!$entry instanceof MinecraftVersionCatalog) {
                        continue;
                    }
                    if (isset($seen[$this->key($entry->getChannel(), $entry->getMcVersion(), $entry->getBuild())])) {
                        continue;
                    }
                    $summary['deactivated']++;
                    if (!$dryRun) {
                        $entry->setIsActive(false);
                        $this->entityManager->persist($entry);
                    }
                }
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $summary;
    }

    /** @return array<int, array{channel:string,mcVersion:string,build:?string,downloadUrl:string,sha256:?string,releasedAt:?\DateTimeImmutable,javaVersion:?string}> */
    private function readChannel(string $channel): array
    {
        return match ($channel) {
            'vanilla' => $this->readVanilla(),
            'paper' => $this->readPaper(),
            'bedrock' => $this->readBedrock(),
            default => [],
        };
    }

    /** @return array<int, array{channel:string,mcVersion:string,build:?string,downloadUrl:string,sha256:?string,releasedAt:?\DateTimeImmutable,javaVersion:?string}> */
    private function readVanilla(): array
    {
        $content = $this->readFile($this->vanillaPath);
        $items = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (!preg_match('/^\|\s*([^|]+?)\s*\|\s*(https?:\/\/[^|\s]+)\s*\|/', $line, $matches)) {
                continue;
            }
            $version = trim($matches[1]);
            if ($version === '' || stripos($version, 'Minecraft Version') !== false) {
                continue;
            }
            $items[] = $this->item('vanilla', $version, null, trim($matches[2]), null);
        }
        return $items;
    }

    /** @return array<int, array{channel:string,mcVersion:string,build:?string,downloadUrl:string,sha256:?string,releasedAt:?\DateTimeImmutable,javaVersion:?string}> */
    private function readPaper(): array
    {
        $data = json_decode($this->readFile($this->paperPath), true);
        if (!is_array($data)) {
            throw new \RuntimeException($this->trans('minecraft_versions_import_error_paper_invalid'));
        }
        $items = [];
        $versions = isset($data['versions']) && is_array($data['versions']) ? $data['versions'] : $data;
        foreach ($versions as $version => $value) {
            $url = is_string($value) ? $value : (is_array($value) ? (string) ($value['url'] ?? '') : '');
            $build = null;
            if (preg_match('/paper-.+-(\d+)\.jar(?:$|\?)/', basename(parse_url($url, PHP_URL_PATH) ?: ''), $matches)) {
                $build = $matches[1];
            }
            if ($build === null && preg_match('/builds\/(\d+)\//', $url, $matches)) {
                $build = $matches[1];
            }
            if ($build === null) {
                continue;
            }
            $items[] = $this->item('paper', (string) $version, $build, $url, null);
        }
        return $items;
    }

    /** @return array<int, array{channel:string,mcVersion:string,build:?string,downloadUrl:string,sha256:?string,releasedAt:?\DateTimeImmutable,javaVersion:?string}> */
    private function readBedrock(): array
    {
        $data = json_decode($this->readFile($this->bedrockPath), true);
        if (!is_array($data)) {
            throw new \RuntimeException($this->trans('minecraft_versions_import_error_bedrock_invalid'));
        }
        $items = [];
        foreach ($data as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach ($group as $version => $platforms) {
                if (!is_array($platforms)) {
                    continue;
                }
                $url = (string) ($platforms['linux']['url'] ?? $platforms['windows']['url'] ?? '');
                $items[] = $this->item('bedrock', (string) $version, null, $url, null);
            }
        }
        return $items;
    }

    private function readFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException($this->trans('minecraft_versions_import_error_source_unreadable', ['%path%' => $path]));
        }
        return (string) file_get_contents($path);
    }

    /** @return array{channel:string,mcVersion:string,build:?string,downloadUrl:string,sha256:?string,releasedAt:?\DateTimeImmutable,javaVersion:?string} */
    private function item(string $channel, string $mcVersion, ?string $build, string $downloadUrl, ?string $sha256): array
    {
        return [
            'channel' => $channel,
            'mcVersion' => trim($mcVersion),
            'build' => $build !== null && trim($build) !== '' ? trim($build) : null,
            'downloadUrl' => trim($downloadUrl),
            'sha256' => $sha256,
            'releasedAt' => null,
            'javaVersion' => $channel === 'bedrock' ? null : $this->javaVersionResolver->resolve($mcVersion),
        ];
    }

    /** @param array{downloadUrl:string,sha256:?string,releasedAt:?\DateTimeImmutable,javaVersion:?string} $item */
    private function applyImportFields(MinecraftVersionCatalog $entry, array $item): void
    {
        $entry->setDownloadUrl($item['downloadUrl']);
        $entry->setSha256($item['sha256']);
        $entry->setReleasedAt($item['releasedAt']);
        $entry->setJavaVersion($item['javaVersion']);
        $entry->setSource('import');
        $entry->setIsActive(true);
    }


    /** @param array<string, string> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'portal');
    }

    private function key(string $channel, string $version, ?string $build): string
    {
        return $channel . '|' . $version . '|' . (string) $build;
    }
}
