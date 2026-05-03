<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Admin;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\User;
use App\Repository\CmsBlockRepository;
use App\Repository\CmsPageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Dedicated admin for the Xenal landing page (slug: startseite).
 * Provides structured forms for every landing-page section block
 * so editors never have to touch raw JSON.
 */
#[Route(path: '/admin/cms/xenal')]
final class AdminXenalLandingController
{
    private const LANDING_SLUG = 'startseite';

    /** Block types managed on the landing page, in visual order. */
    private const BLOCK_TYPES = [
        'hero',
        'xn_servers',
        'xn_stats',
        'xn_teams',
        'xn_events',
        'xn_cta',
    ];

    public function __construct(
        private readonly SiteResolver $siteResolver,
        private readonly CmsPageRepository $pageRepository,
        private readonly CmsBlockRepository $blockRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    /* ──────────────────────────────────────────────────────────────────
       GET /admin/cms/xenal/landing  — overview + all section editors
    ────────────────────────────────────────────────────────────────── */
    #[Route(path: '/landing', name: 'admin_xenal_landing', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->ensureLandingPage($site);
        $blocks = $this->collectBlocks($page);

        return new Response($this->twig->render('admin/cms/xenal/landing.html.twig', [
            'activeNav' => 'cms-xenal-landing',
            'page'      => $page,
            'blocks'    => $blocks,
            'saved'     => $request->query->get('saved'),
        ]));
    }

    /* ──────────────────────────────────────────────────────────────────
       POST /admin/cms/xenal/landing/hero
    ────────────────────────────────────────────────────────────────── */
    #[Route(path: '/landing/hero', name: 'admin_xenal_landing_hero', methods: ['POST'])]
    public function saveHero(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->ensureLandingPage($site);
        $block = $this->findOrCreateBlock($page, 'hero', 0);

        $meta = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->request->get('meta', 'Community,Competition,Performance'))
        )));

        $payload = [
            'headline'        => trim((string) $request->request->get('headline', 'DOMINATE<br>THE GAME.')),
            'subheadline'     => trim((string) $request->request->get('subheadline', '')),
            'meta'            => $meta,
            'ctaText'         => trim((string) $request->request->get('ctaText', 'Join the Clan')),
            'ctaUrl'          => trim((string) $request->request->get('ctaUrl', '/login')),
            'cta2Text'        => trim((string) $request->request->get('cta2Text', 'Unsere Server')),
            'cta2Url'         => trim((string) $request->request->get('cta2Url', '#gameserver')),
            'backgroundImage' => trim((string) $request->request->get('backgroundImage', '')),
            'rightImage'      => trim((string) $request->request->get('rightImage', '')),
            'logoLetter'      => trim((string) $request->request->get('logoLetter', 'X')),
            'brandName'       => trim((string) $request->request->get('brandName', 'XENAL GAMING')),
        ];

        $this->saveBlock($block, 'hero', $payload, $request->attributes->get('current_user'));
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/xenal/landing?saved=hero');
    }

    /* ──────────────────────────────────────────────────────────────────
       POST /admin/cms/xenal/landing/servers
    ────────────────────────────────────────────────────────────────── */
    #[Route(path: '/landing/servers', name: 'admin_xenal_landing_servers', methods: ['POST'])]
    public function saveServers(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = $this->ensureLandingPage($site);
        $block = $this->findOrCreateBlock($page, 'xn_servers', 10);

        // Structured server list from repeated fields
        $names          = (array) $request->request->all('srv_name');
        $games          = (array) $request->request->all('srv_game');
        $statuses       = (array) $request->request->all('srv_status');
        $players        = (array) $request->request->all('srv_players');
        $playerSuffixes = (array) $request->request->all('srv_player_suffix');
        $registered     = (array) $request->request->all('srv_registered');
        $maps           = (array) $request->request->all('srv_map');
        $infos          = (array) $request->request->all('srv_info');
        $currentPlayers = (array) $request->request->all('srv_current_player');
        $links          = (array) $request->request->all('srv_link');
        $linkTexts      = (array) $request->request->all('srv_link_text');

        $servers = [];
        foreach ($names as $i => $name) {
            if (trim($name) === '') {
                continue;
            }

            $servers[] = array_filter([
                'name'          => trim($name),
                'game'          => trim($games[$i] ?? 'cs2'),
                'status'        => trim($statuses[$i] ?? 'online'),
                'players'       => trim($players[$i] ?? ''),
                'playerSuffix'  => trim($playerSuffixes[$i] ?? 'Spieler'),
                'registered'    => trim($registered[$i] ?? ''),
                'map'           => trim($maps[$i] ?? ''),
                'info'          => trim($infos[$i] ?? ''),
                'currentPlayer' => trim($currentPlayers[$i] ?? ''),
                'link'          => trim($links[$i] ?? ''),
                'linkText'      => trim($linkTexts[$i] ?? 'Server anzeigen'),
            ], fn ($v) => $v !== '');
        }

        // If someone submitted raw JSON instead, prefer that
        $rawJson = trim((string) $request->request->get('servers_json', ''));
        if ($rawJson !== '') {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                $servers = $decoded;
            }
        }

        $payload = [
            'sectionTitle' => trim((string) $request->request->get('sectionTitle', 'Unsere Gameserver')),
            'moreText'     => trim((string) $request->request->get('moreText', 'Zur Serverübersicht')),
            'moreLink'     => trim((string) $request->request->get('moreLink', '/servers')),
            'maxItems'     => max(1, (int) $request->request->get('maxItems', 4)),
            'servers'      => $servers,
        ];

        $this->saveBlock($block, 'xn_servers', $payload, $request->attributes->get('current_user'));
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/xenal/landing?saved=servers');
    }

    /* ──────────────────────────────────────────────────────────────────
       POST /admin/cms/xenal/landing/stats
    ────────────────────────────────────────────────────────────────── */
    #[Route(path: '/landing/stats', name: 'admin_xenal_landing_stats', methods: ['POST'])]
    public function saveStats(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page  = $this->ensureLandingPage($site);
        $block = $this->findOrCreateBlock($page, 'xn_stats', 20);

        $values = (array) $request->request->all('stat_value');
        $labels = (array) $request->request->all('stat_label');

        $stats = [];
        foreach ($values as $i => $val) {
            if (trim($val) === '' && trim($labels[$i] ?? '') === '') {
                continue;
            }

            $stats[] = [
                'value' => trim($val),
                'label' => trim($labels[$i] ?? ''),
            ];
        }

        $payload = [
            'sectionTitle' => trim((string) $request->request->get('sectionTitle', 'CLAN STATS')),
            'memberCount'  => max(0, (int) $request->request->get('memberCount', 0)),
            'stats'        => $stats,
        ];

        $this->saveBlock($block, 'xn_stats', $payload, $request->attributes->get('current_user'));
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/xenal/landing?saved=stats');
    }

    /* ──────────────────────────────────────────────────────────────────
       POST /admin/cms/xenal/landing/teams
    ────────────────────────────────────────────────────────────────── */
    #[Route(path: '/landing/teams', name: 'admin_xenal_landing_teams', methods: ['POST'])]
    public function saveTeams(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page  = $this->ensureLandingPage($site);
        $block = $this->findOrCreateBlock($page, 'xn_teams', 30);

        $names     = (array) $request->request->all('team_name');
        $games     = (array) $request->request->all('team_game');
        $divisions = (array) $request->request->all('team_division');
        $images    = (array) $request->request->all('team_image');
        $colors    = (array) $request->request->all('team_color');
        $info1s    = (array) $request->request->all('team_info1');
        $info2s    = (array) $request->request->all('team_info2');
        $linkUrls  = (array) $request->request->all('team_link_url');
        $linkLabels = (array) $request->request->all('team_link_label');

        $teams = [];
        foreach ($names as $i => $name) {
            if (trim($name) === '') {
                continue;
            }

            $links = [];
            if (!empty($linkUrls[$i])) {
                $links[] = [
                    'label' => trim($linkLabels[$i] ?? 'Mehr Infos'),
                    'url'   => trim($linkUrls[$i]),
                ];
            }

            $teams[] = array_filter([
                'name'     => trim($name),
                'game'     => trim($games[$i] ?? 'casual'),
                'division' => trim($divisions[$i] ?? ''),
                'image'    => trim($images[$i] ?? ''),
                'color'    => trim($colors[$i] ?? ''),
                'info1'    => trim($info1s[$i] ?? ''),
                'info2'    => trim($info2s[$i] ?? ''),
                'links'    => $links ?: null,
            ], fn ($v) => $v !== '' && $v !== null);
        }

        $payload = [
            'sectionTitle' => trim((string) $request->request->get('sectionTitle', 'UNSERE TEAMS')),
            'moreText'     => trim((string) $request->request->get('moreText', 'Alle Teams anzeigen')),
            'moreLink'     => trim((string) $request->request->get('moreLink', '/teams')),
            'overviewText' => trim((string) $request->request->get('overviewText', '')),
            'overviewLink' => trim((string) $request->request->get('overviewLink', '')),
            'teams'        => $teams,
        ];

        $this->saveBlock($block, 'xn_teams', $payload, $request->attributes->get('current_user'));
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/xenal/landing?saved=teams');
    }

    /* ──────────────────────────────────────────────────────────────────
       POST /admin/cms/xenal/landing/events
    ────────────────────────────────────────────────────────────────── */
    #[Route(path: '/landing/events', name: 'admin_xenal_landing_events', methods: ['POST'])]
    public function saveEvents(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page  = $this->ensureLandingPage($site);
        $block = $this->findOrCreateBlock($page, 'xn_events', 40);

        $evDates = (array) $request->request->all('ev_date');
        $evNames = (array) $request->request->all('ev_name');

        $tournTitles = (array) $request->request->all('tour_title');
        $tournGames = (array) $request->request->all('tour_game');
        $tournDetails = (array) $request->request->all('tour_details');
        $tournLinks = (array) $request->request->all('tour_link');

        $upcoming = [];
        foreach ($evDates as $i => $date) {
            if (trim($date) === '' && trim($evNames[$i] ?? '') === '') {
                continue;
            }

            $upcoming[] = [
                'date' => trim($date),
                'name' => trim($evNames[$i] ?? ''),
            ];
        }

        $tournaments = [];
        foreach ($tournTitles as $i => $title) {
            if (trim($title) === '') {
                continue;
            }

            $tournaments[] = array_filter([
                'badge' => 'CUP',
                'title' => trim($title),
                'game' => trim($tournGames[$i] ?? ''),
                'details' => trim($tournDetails[$i] ?? ''),
                'link' => trim($tournLinks[$i] ?? ''),
                'linkText' => 'Details anzeigen',
                'labelDays' => 'Tage',
                'labelHours' => 'Std',
                'labelMinutes' => 'Min',
                'labelSeconds' => 'Sek',
                'countdown' => ['days' => '00', 'hours' => '00', 'minutes' => '00', 'seconds' => '00'],
            ], fn ($v) => $v !== '');
        }

        $payload = [
            'sectionTitle'  => trim((string) $request->request->get('sectionTitle', 'EVENTS & TURNIERE')),
            'upcomingTitle' => trim((string) $request->request->get('upcomingTitle', 'Kommende Events')),
            'upcoming'      => $upcoming,
            'tournaments'   => $tournaments,
            'tournament'    => [
                'badge'        => trim((string) $request->request->get('t_badge', 'ESL')),
                'title'        => trim((string) $request->request->get('t_title', 'Aktuelles Turnier')),
                'game'         => trim((string) $request->request->get('t_game', '')),
                'details'      => trim((string) $request->request->get('t_details', '')),
                'link'         => trim((string) $request->request->get('t_link', '')),
                'linkText'     => trim((string) $request->request->get('t_link_text', 'Details anzeigen')),
                'labelDays'    => trim((string) $request->request->get('t_label_days', 'Tage')),
                'labelHours'   => trim((string) $request->request->get('t_label_hours', 'Std')),
                'labelMinutes' => trim((string) $request->request->get('t_label_minutes', 'Min')),
                'labelSeconds' => trim((string) $request->request->get('t_label_seconds', 'Sek')),
                'countdown'    => [
                    'days'    => trim((string) $request->request->get('t_cd_days', '00')),
                    'hours'   => trim((string) $request->request->get('t_cd_hours', '00')),
                    'minutes' => trim((string) $request->request->get('t_cd_minutes', '00')),
                    'seconds' => trim((string) $request->request->get('t_cd_seconds', '00')),
                ],
            ],
        ];

        $this->saveBlock($block, 'xn_events', $payload, $request->attributes->get('current_user'));
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/xenal/landing?saved=events');
    }

    /* ──────────────────────────────────────────────────────────────────
       POST /admin/cms/xenal/landing/cta
    ────────────────────────────────────────────────────────────────── */
    #[Route(path: '/landing/cta', name: 'admin_xenal_landing_cta', methods: ['POST'])]
    public function saveCta(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page  = $this->ensureLandingPage($site);
        $block = $this->findOrCreateBlock($page, 'xn_cta', 50);

        $socialLabels = (array) $request->request->all('cta_social_label');
        $socialUrls   = (array) $request->request->all('cta_social_url');

        $socials = [];
        foreach ($socialLabels as $i => $label) {
            if (trim($label) === '') {
                continue;
            }

            $socials[] = [
                'label' => trim($label),
                'url'   => trim($socialUrls[$i] ?? '#'),
            ];
        }

        $payload = [
            'title'   => trim((string) $request->request->get('title', 'BEREIT EIN TEIL VON XENAL ZU WERDEN?')),
            'socials' => $socials,
        ];

        $this->saveBlock($block, 'xn_cta', $payload, $request->attributes->get('current_user'));
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/xenal/landing?saved=cta');
    }

    /* ──────────────────────────────────────────────────────────────────
       Helpers
    ────────────────────────────────────────────────────────────────── */

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }

    /** Ensure startseite page exists, create if missing. */
    private function ensureLandingPage(object $site): CmsPage
    {
        $page = $this->pageRepository->findOneBy(['site' => $site, 'slug' => self::LANDING_SLUG]);

        if (!$page instanceof CmsPage) {
            $page = new CmsPage($site, 'Startseite', self::LANDING_SLUG, true);
            $this->entityManager->persist($page);
            $this->entityManager->flush();
        }

        return $page;
    }

    /**
     * Return a map of blockType → block for every known landing section.
     *
     * @return array<string, CmsBlock|null>
     */
    private function collectBlocks(CmsPage $page): array
    {
        $blocks = [];
        foreach (self::BLOCK_TYPES as $type) {
            $blocks[$type] = null;
        }

        foreach ($this->blockRepository->findBy(['page' => $page], ['sortOrder' => 'ASC']) as $block) {
            if (array_key_exists($block->getType(), $blocks) && $blocks[$block->getType()] === null) {
                $blocks[$block->getType()] = $block;
            }
        }

        return $blocks;
    }

    /** Find existing block by type, or create a new empty one. */
    private function findOrCreateBlock(CmsPage $page, string $type, int $defaultSortOrder): CmsBlock
    {
        $block = $this->blockRepository->findOneBy(['page' => $page, 'type' => $type]);

        if (!$block instanceof CmsBlock) {
            $block = new CmsBlock($page, $type, '', $defaultSortOrder);
            $block->setVersion(2);
            $this->entityManager->persist($block);
        }

        return $block;
    }

    /** @param array<string, mixed> $payload */
    private function saveBlock(CmsBlock $block, string $type, array $payload, mixed $actor): void
    {
        $block->setType($type);
        $block->setContent('');
        $block->setVersion(2);
        $block->setPayloadJson($payload);
        $block->setSettingsJson(['editor' => 'xenal_landing']);

        if ($actor instanceof User) {
            $this->auditLogger->log($actor, 'cms.xenal_landing.block_saved', [
                'block_type' => $type,
            ]);
        }
    }
}
