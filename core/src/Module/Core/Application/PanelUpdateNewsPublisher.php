<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\ChangelogEntry;
use App\Repository\ChangelogEntryRepository;
use App\Repository\SiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class PanelUpdateNewsPublisher
{
    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly ChangelogEntryRepository $changelogRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publishSuccessfulUpdate(?string $previousVersion, ?string $installedVersion, ?string $releaseNotes = null): void
    {
        $installedVersion = trim((string) $installedVersion);
        if ($installedVersion === '') {
            return;
        }

        try {
            $sites = $this->siteRepository->findBy([], ['id' => 'ASC']);
            if ($sites === []) {
                return;
            }

            $publishedAt = new \DateTimeImmutable();
            foreach ($sites as $site) {
                $siteId = $site->getId();
                if ($siteId === null || $this->changelogRepository->findPanelUpdateEntry($siteId, $installedVersion) instanceof ChangelogEntry) {
                    continue;
                }

                $entry = new ChangelogEntry(
                    siteId: $siteId,
                    title: ChangelogEntryRepository::panelUpdateTitle($installedVersion),
                    content: $this->buildContent($previousVersion, $installedVersion, $releaseNotes),
                    publishedAt: $publishedAt,
                    version: $installedVersion,
                    visiblePublic: true,
                );
                $this->entityManager->persist($entry);
            }

            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logger->warning('panel_update_news.publish_failed', [
                'previous_version' => $previousVersion,
                'installed_version' => $installedVersion,
                'exception' => $exception,
            ]);
        }
    }

    private function buildContent(?string $previousVersion, string $installedVersion, ?string $releaseNotes): string
    {
        $previousVersion = trim((string) $previousVersion);
        $content = $previousVersion !== '' && $previousVersion !== $installedVersion
            ? sprintf('Der Kundenbereich wurde erfolgreich von Version %s auf Version %s aktualisiert.', $previousVersion, $installedVersion)
            : sprintf('Der Kundenbereich wurde erfolgreich auf Version %s aktualisiert.', $installedVersion);

        $releaseNotes = trim((string) $releaseNotes);
        if ($releaseNotes !== '') {
            $content .= "\n\nÄnderungen:\n" . $releaseNotes;
        }

        return $content;
    }
}
