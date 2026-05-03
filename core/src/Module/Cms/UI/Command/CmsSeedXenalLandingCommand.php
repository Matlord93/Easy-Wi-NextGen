<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Command;

use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Repository\CmsBlockRepository;
use App\Repository\CmsPageRepository;
use App\Repository\SiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the Xenal landing page (slug: startseite) with demo content
 * matching the full design including Gameserver, Clan Stats, Teams,
 * Events/Turniere and CTA sections. All data is stored as CMS blocks
 * so it can be edited via /admin/cms/xenal/landing afterwards.
 *
 * Run: php bin/console cms:seed-xenal-landing
 * Idempotent – safe to run multiple times (skips existing blocks by default,
 * use --force to overwrite).
 */
#[AsCommand(
    name: 'cms:seed-xenal-landing',
    description: 'Seed the Xenal landing page (startseite) with demo content and activate the xenal theme.',
)]
final class CmsSeedXenalLandingCommand extends Command
{
    private const LANDING_SLUG = 'startseite';

    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly CmsPageRepository $pageRepository,
        private readonly CmsBlockRepository $blockRepository,
        private readonly CmsSettingsProvider $settingsProvider,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing blocks with fresh demo data.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without persisting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        $site = $this->siteRepository->findDefault();
        if ($site === null) {
            $io->error('No site found in the database. Please complete the installer first.');
            return Command::FAILURE;
        }

        $io->title('Xenal Landing-Page Seeder');
        $io->text(sprintf('Site: %s (id=%d)', $site->getHost() ?? 'default', $site->getId()));

        // ── 1. Ensure the landing page exists ────────────────────────────────
        $page = $this->pageRepository->findOneBy(['site' => $site, 'slug' => self::LANDING_SLUG]);
        if (!$page instanceof CmsPage) {
            $io->text('Creating CmsPage "Startseite" ...');
            $page = new CmsPage($site, 'Startseite', self::LANDING_SLUG, true);
            if (!$dryRun) {
                $this->entityManager->persist($page);
                $this->entityManager->flush(); // need ID before blocks
            }
        } else {
            $io->text(sprintf('CmsPage "startseite" already exists (id=%d).', $page->getId() ?? 0));
        }

        // ── 2. Seed each block ────────────────────────────────────────────────
        $blocks = $this->buildBlocks();
        foreach ($blocks as $blockDef) {
            $this->upsertBlock($io, $page, $blockDef, $force, $dryRun);
        }

        // ── 3. Activate xenal theme ───────────────────────────────────────────
        $io->text('Activating xenal theme ...');
        if (!$dryRun) {
            $settings = $this->settingsProvider->getOrCreate($site);
            $branding = $this->settingsProvider->getBranding($site);
            $moduleToggles = $this->settingsProvider->getModuleToggles($site);
            $headerLinks = $this->settingsProvider->getNavigationLinks($site);
            $footerLinks = $this->settingsProvider->getFooterLinks($site);

            $this->settingsProvider->save(
                $site,
                'xenal',
                $branding,
                $moduleToggles,
                $headerLinks,
                $footerLinks,
            );
        }

        if (!$dryRun) {
            $this->entityManager->flush();
            $io->success('Xenal landing page seeded successfully. Visit / to see the result.');
        } else {
            $io->success('Dry-run complete – no changes were persisted.');
        }

        return Command::SUCCESS;
    }

    /**
     * Returns the ordered list of block definitions to seed.
     * Each entry: [type, sortOrder, payload].
     *
     * @return list<array{type: string, sortOrder: int, payload: array<string, mixed>}>
     */
    private function buildBlocks(): array
    {
        return [
            // ── HERO ────────────────────────────────────────────────────────
            [
                'type'      => 'hero',
                'sortOrder' => 0,
                'payload'   => [
                    'headline'        => 'DOMINATE<br>THE GAME.',
                    'subheadline'     => '',
                    'meta'            => ['Community', 'Competition', 'Performance'],
                    'ctaText'         => 'Join the Clan',
                    'ctaUrl'          => '/login',
                    'cta2Text'        => 'Unsere Server',
                    'cta2Url'         => '#gameserver',
                    'backgroundImage' => '',
                    'rightImage'      => '',
                    'logoLetter'      => 'X',
                    'brandName'       => 'XENAL GAMING',
                ],
            ],

            // ── GAMESERVER ──────────────────────────────────────────────────
            [
                'type'      => 'xn_servers',
                'sortOrder' => 10,
                'payload'   => [
                    'sectionTitle' => 'Unsere Gameserver',
                    'moreText'     => 'Zur Serverübersicht',
                    'moreLink'     => '/server',
                    'servers'      => [
                        [
                            'name'          => 'CS2 Competitive',
                            'game'          => 'cs2',
                            'status'        => 'online',
                            'players'       => '12/116',
                            'playerSuffix'  => 'Spieler',
                            'registered'    => '12 116 Spieler',
                            'map'           => '',
                            'info'          => 'Bester Server',
                            'currentPlayer' => 'Gnever Namer',
                            'link'          => '/server/cs2-competitive',
                            'linkText'      => 'Server anzeigen',
                        ],
                        [
                            'name'          => 'ARK',
                            'game'          => 'ark',
                            'status'        => 'online',
                            'players'       => '45/70',
                            'playerSuffix'  => 'Spieler',
                            'registered'    => '',
                            'map'           => 'In: Mirage',
                            'info'          => 'Aktuell: Game Server',
                            'currentPlayer' => 'Garver Sayger',
                            'link'          => '/server/ark',
                            'linkText'      => 'Server anzeigen',
                        ],
                        [
                            'name'          => 'CS2',
                            'game'          => 'cs2',
                            'status'        => 'offline',
                            'players'       => '45/70',
                            'playerSuffix'  => 'Spieler',
                            'registered'    => '',
                            'map'           => 'In: Vivavekt',
                            'info'          => 'Das kostet',
                            'currentPlayer' => '',
                            'link'          => '/server/cs2',
                            'linkText'      => 'Server anzeigen',
                        ],
                        [
                            'name'          => 'RUST',
                            'game'          => 'rust',
                            'status'        => 'online',
                            'players'       => '78/150',
                            'playerSuffix'  => 'Spieler',
                            'registered'    => '',
                            'map'           => 'In: Rome Teruna',
                            'info'          => 'Das lösest',
                            'currentPlayer' => '',
                            'link'          => '/server/rust',
                            'linkText'      => 'Server anzeigen',
                        ],
                    ],
                ],
            ],

            // ── CLAN STATS ──────────────────────────────────────────────────
            [
                'type'      => 'xn_stats',
                'sortOrder' => 20,
                'payload'   => [
                    'sectionTitle' => 'CLAN STATS',
                    'stats'        => [
                        ['value' => '25+', 'label' => 'AKTIVE MEMBER'],
                        ['value' => '4',   'label' => 'TEAMS'],
                        ['value' => '7',   'label' => 'EVENTS / MONAT'],
                        ['value' => '3',   'label' => 'AKTIVE TOURNAMENTS'],
                    ],
                ],
            ],

            // ── TEAMS ───────────────────────────────────────────────────────
            [
                'type'      => 'xn_teams',
                'sortOrder' => 30,
                'payload'   => [
                    'sectionTitle' => 'UNSERE TEAMS',
                    'moreText'     => 'Alle Teams anzeigen',
                    'moreLink'     => '/teams',
                    'overviewText' => 'Zur Serverübersicht',
                    'overviewLink' => '/teams',
                    'teams'        => [
                        [
                            'name'     => 'Valorant Team',
                            'game'     => 'valorant',
                            'division' => 'Division 2',
                            'image'    => '',
                            'color'    => 'rgba(220,20,60,.45)',
                            'info1'    => '5 AKTIVE RAIDER, REISEN 2024 TO:F0',
                            'info2'    => '',
                            'links'    => [
                                ['label' => 'Mehr Infos', 'url' => '/teams/valorant'],
                            ],
                        ],
                        [
                            'name'     => 'CS2 Main Squad',
                            'game'     => 'cs2',
                            'division' => 'ESL Open',
                            'image'    => '',
                            'color'    => 'rgba(224,120,0,.45)',
                            'info1'    => 'JAG: RETRION RAIDEN REDIN MTG',
                            'info2'    => '',
                            'links'    => [
                                ['label' => 'Mehr Infos', 'url' => '/teams/cs2'],
                            ],
                        ],
                        [
                            'name'     => 'Casual Squad',
                            'game'     => 'cs2',
                            'division' => 'Community Team',
                            'image'    => '',
                            'color'    => 'rgba(40,80,180,.45)',
                            'info1'    => 'CAIMAN: ASSAULT Solo',
                            'info2'    => 'Esiner Farevamas',
                            'links'    => [
                                ['label' => 'Mehr Infos', 'url' => '/teams/casual'],
                            ],
                        ],
                    ],
                ],
            ],

            // ── EVENTS & TURNIERE ────────────────────────────────────────────
            [
                'type'      => 'xn_events',
                'sortOrder' => 40,
                'payload'   => [
                    'sectionTitle'  => 'EVENTS & TURNIERE',
                    'upcomingTitle' => 'Kommende Events',
                    'upcoming'      => [
                        ['date' => '18.02', 'name' => 'Clan Scrim'],
                        ['date' => '21.02', 'name' => 'Community Night'],
                        ['date' => '02.03', 'name' => 'Tournament Qualifier'],
                    ],
                    'tournament'    => [
                        'badge'        => 'ESL',
                        'title'        => 'Aktuelles Turnier',
                        'game'         => 'ESL Open',
                        'details'      => '1 Bay – Angeles Remotely, Cheats',
                        'link'         => '/events/esl-open',
                        'linkText'     => 'Details anzeigen',
                        'labelDays'    => 'Tage',
                        'labelHours'   => 'Std',
                        'labelMinutes' => 'Min',
                        'labelSeconds' => 'Sek',
                        'countdown'    => [
                            'days'    => '06',
                            'hours'   => '14',
                            'minutes' => '31',
                            'seconds' => '01',
                        ],
                    ],
                ],
            ],

            // ── CTA BAR ─────────────────────────────────────────────────────
            [
                'type'      => 'xn_cta',
                'sortOrder' => 50,
                'payload'   => [
                    'title'   => 'BEREIT EIN TEIL VON XENAL ZU WERDEN?',
                    'socials' => [],
                ],
            ],
        ];
    }

    /**
     * @param array{type: string, sortOrder: int, payload: array<string, mixed>} $def
     */
    private function upsertBlock(
        SymfonyStyle $io,
        CmsPage $page,
        array $def,
        bool $force,
        bool $dryRun,
    ): void {
        $existing = $this->blockRepository->findOneBy(['page' => $page, 'type' => $def['type']]);

        if ($existing instanceof CmsBlock) {
            if (!$force) {
                $io->text(sprintf('  [skip]  Block "%s" already exists (use --force to overwrite).', $def['type']));
                return;
            }

            $io->text(sprintf('  [update] Block "%s" (overwriting).', $def['type']));
            $block = $existing;
        } else {
            $io->text(sprintf('  [create] Block "%s".', $def['type']));
            $block = new CmsBlock($page, $def['type'], '', $def['sortOrder']);
            if (!$dryRun) {
                $this->entityManager->persist($block);
            }
        }

        if (!$dryRun) {
            $block->setType($def['type']);
            $block->setContent('');
            $block->setVersion(2);
            $block->setSortOrder($def['sortOrder']);
            $block->setPayloadJson($def['payload']);
            $block->setSettingsJson(['editor' => 'xenal_landing', 'seeded' => true]);
        }
    }
}
