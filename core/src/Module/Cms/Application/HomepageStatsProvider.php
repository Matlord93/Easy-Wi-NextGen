<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Module\Core\Domain\Entity\CmsEvent;
use App\Module\Core\Domain\Entity\ForumThread;
use App\Module\Core\Domain\Entity\PublicServer;
use App\Module\Core\Domain\Entity\Site;
use App\Repository\CmsEventRepository;
use App\Repository\ForumThreadRepository;
use App\Repository\PublicServerRepository;
use App\Repository\TeamMemberRepository;

final class HomepageStatsProvider
{
    public function __construct(
        private readonly TeamMemberRepository $teamMemberRepository,
        private readonly CmsEventRepository $eventRepository,
        private readonly ForumThreadRepository $forumThreadRepository,
        private readonly PublicServerRepository $publicServerRepository,
    ) {
    }

    /** @return array<string, mixed> */
    public function getTemplateVars(Site $site): array
    {
        $members = $this->teamMemberRepository->findActiveBySite($site);
        $events = $this->eventRepository->findPublishedBySite($site);
        $now = new \DateTimeImmutable();

        $upcomingEvents = array_filter($events, fn (CmsEvent $e): bool => $e->getStartAt() >= $now);
        usort($upcomingEvents, fn (CmsEvent $a, CmsEvent $b): int => $a->getStartAt() <=> $b->getStartAt());
        $upcomingEvents = array_values(array_slice($upcomingEvents, 0, 3));

        $forumThreadCount = $this->countForumThreads($site);
        $latestForumThreads = $this->forumThreadRepository->findLatestActiveBySite($site, 3);
        $publicServers = $this->publicServerRepository->findVisiblePublicBySite($site->getId() ?? 0, null, null, 8);
        $normalizedServers = $this->normalizeServers($publicServers);
        $onlineServers = array_filter(
            $normalizedServers,
            static fn (array $server): bool => ($server['status'] ?? '') === 'online',
        );

        $nextEvent = count($upcomingEvents) > 0 ? $upcomingEvents[0]->getStartAt()->format('d.m.') : null;

        return [
            'cms_home_stats' => [
                'members_count' => count($members),
                'events_count' => count($events),
                'servers_count' => count($publicServers),
                'servers_online' => count($onlineServers),
                'forum_posts' => $forumThreadCount,
                'forum_posts_new' => null,
                'members_growth' => null,
                'next_event' => $nextEvent,
            ],
            'cms_recent_events' => array_map(fn (CmsEvent $e): array => [
                'slug' => $e->getSlug(),
                'title' => $e->getTitle(),
                'description' => $e->getDescription(),
                'start_at' => $e->getStartAt(),
                'location' => $e->getLocation(),
                'status' => $e->getStatus(),
                'cover_image_path' => $e->getCoverImagePath(),
            ], $upcomingEvents),
            'cms_server_status' => $normalizedServers,
            'cms_activity' => array_map(fn (ForumThread $thread): array => [
                'title' => $thread->getTitle(),
                'thread_id' => $thread->getId(),
                'board_title' => $thread->getBoard()->getTitle(),
                'author_name' => $thread->getAuthorUser()?->getName() ?? 'Unbekannt',
                'last_activity_at' => $thread->getLastActivityAt(),
            ], $latestForumThreads),
            'cms_top_members' => array_map(fn ($m): array => [
                'name' => $m->getName(),
                'role_title' => $m->getRoleTitle(),
                'avatar_path' => $m->getAvatarPath(),
            ], array_slice($members, 0, 5)),
        ];
    }

    private function countForumThreads(Site $site): int
    {
        try {
            return (int) $this->forumThreadRepository->createQueryBuilder('t')
                ->select('COUNT(t.id)')
                ->andWhere('t.site = :site')
                ->setParameter('site', $site)
                ->getQuery()
                ->getSingleScalarResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param PublicServer[] $servers
     * @return array<int, array<string, mixed>>
     */
    private function normalizeServers(array $servers): array
    {
        return array_map(function (PublicServer $server): array {
            $statusCache = $server->getStatusCache();
            $statusValue = $statusCache['status'] ?? ($statusCache['online'] ?? null);

            return [
                'name' => $server->getName(),
                'game_key' => $server->getGameKey(),
                'address' => sprintf('%s:%d', $server->getIp(), $server->getPort()),
                'status' => $this->normalizeStatus($statusValue),
                'players_current' => is_numeric($statusCache['players'] ?? null) ? (int) $statusCache['players'] : null,
                'players_max' => is_numeric($statusCache['max_players'] ?? null) ? (int) $statusCache['max_players'] : null,
                'map' => is_string($statusCache['map'] ?? null) ? $statusCache['map'] : null,
            ];
        }, $servers);
    }

    private function normalizeStatus(mixed $statusValue): string
    {
        if (is_string($statusValue) && $statusValue !== '') {
            $normalized = strtolower($statusValue);
            if (in_array($normalized, ['running', 'up', 'alive', 'ok'], true)) {
                return 'online';
            }
            if (in_array($normalized, ['down', 'stopped'], true)) {
                return 'offline';
            }

            return $normalized;
        }
        if ($statusValue === true) {
            return 'online';
        }
        if ($statusValue === false) {
            return 'offline';
        }

        return 'unknown';
    }
}
