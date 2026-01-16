<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MinecraftVersionCatalogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'catalog:sync:minecraft',
    description: 'Sync Minecraft versions from Mojang and Paper.',
)]
final class CatalogSyncMinecraftCommand extends Command
{
    private const VANILLA_MANIFEST_URL = 'https://piston-meta.mojang.com/mc/game/version_manifest_v2.json';
    private const PAPER_PROJECT_URL = 'https://api.papermc.io/v2/projects/paper';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MinecraftVersionCatalogRepository $catalogRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Syncing Minecraft versions...</info>');

        try {
            $vanillaCount = $this->syncVanilla();
            $paperCount = $this->syncPaper();
        } catch (TransportExceptionInterface $exception) {
            $output->writeln(sprintf('<error>Network error: %s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $this->entityManager->flush();

        $output->writeln(sprintf('<info>Vanilla entries synced: %d</info>', $vanillaCount));
        $output->writeln(sprintf('<info>Paper entries synced: %d</info>', $paperCount));

        return Command::SUCCESS;
    }

    private function syncVanilla(): int
    {
        $manifest = $this->httpClient->request('GET', self::VANILLA_MANIFEST_URL)->toArray(false);
        $versions = $manifest['versions'] ?? [];
        if (!is_array($versions)) {
            return 0;
        }

        $count = 0;
        foreach ($versions as $version) {
            if (!is_array($version)) {
                continue;
            }
            $versionId = trim((string) ($version['id'] ?? ''));
            $versionUrl = trim((string) ($version['url'] ?? ''));
            if ($versionId === '' || $versionUrl === '') {
                continue;
            }

            $details = $this->httpClient->request('GET', $versionUrl)->toArray(false);
            $downloads = is_array($details['downloads'] ?? null) ? $details['downloads'] : [];
            $serverDownload = is_array($downloads['server'] ?? null) ? $downloads['server'] : null;
            if ($serverDownload === null) {
                continue;
            }

            $downloadUrl = trim((string) ($serverDownload['url'] ?? ''));
            if ($downloadUrl === '') {
                continue;
            }

            $sha256 = $this->stringOrNull($serverDownload['sha256'] ?? null);
            $releasedAt = $this->parseReleasedAt($details['releaseTime'] ?? $version['releaseTime'] ?? null);

            $this->catalogRepository->upsert(
                'vanilla',
                $versionId,
                null,
                $downloadUrl,
                $sha256,
                $releasedAt,
            );
            $count++;
        }

        return $count;
    }

    private function syncPaper(): int
    {
        $project = $this->httpClient->request('GET', self::PAPER_PROJECT_URL)->toArray(false);
        $versions = $project['versions'] ?? [];
        if (!is_array($versions)) {
            return 0;
        }

        $count = 0;
        foreach ($versions as $version) {
            $versionId = trim((string) $version);
            if ($versionId === '') {
                continue;
            }

            $versionData = $this->httpClient->request('GET', sprintf('%s/versions/%s', self::PAPER_PROJECT_URL, $versionId))
                ->toArray(false);
            $builds = $versionData['builds'] ?? [];
            if (!is_array($builds) || $builds === []) {
                continue;
            }

            $latestBuild = max(array_map('intval', $builds));
            if ($latestBuild <= 0) {
                continue;
            }

            $buildData = $this->httpClient->request(
                'GET',
                sprintf('%s/versions/%s/builds/%d', self::PAPER_PROJECT_URL, $versionId, $latestBuild),
            )->toArray(false);

            $downloadName = $buildData['downloads']['application']['name'] ?? null;
            if (!is_string($downloadName) || $downloadName === '') {
                $downloadName = sprintf('paper-%s-%d.jar', $versionId, $latestBuild);
            }

            $downloadUrl = sprintf(
                '%s/versions/%s/builds/%d/downloads/%s',
                self::PAPER_PROJECT_URL,
                $versionId,
                $latestBuild,
                $downloadName,
            );

            $sha256 = $this->stringOrNull($buildData['downloads']['application']['sha256'] ?? null);
            $releasedAt = $this->parseReleasedAt($buildData['time'] ?? null);

            $this->catalogRepository->upsert(
                'paper',
                $versionId,
                (string) $latestBuild,
                $downloadUrl,
                $sha256,
                $releasedAt,
            );
            $count++;
        }

        return $count;
    }

    private function parseReleasedAt(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
