<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Command;

use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Repository\CmsEventRepository;
use App\Repository\CmsPageRepository;
use App\Repository\CmsPostRepository;
use App\Repository\SiteRepository;
use App\Repository\TeamMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'cms:migrate-legacy', description: 'Migrate legacy CMS content into CMS pages/blocks (idempotent).')]
final class CmsMigrateLegacyCommand extends Command
{
    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly CmsPageRepository $pageRepository,
        private readonly CmsPostRepository $postRepository,
        private readonly CmsEventRepository $eventRepository,
        private readonly TeamMemberRepository $teamRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not persist changes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        foreach ($this->siteRepository->findAll() as $site) {
            $this->upsertPage($site, 'blog', 'Blog / News', $this->renderBlog($site), $dryRun);
            $this->upsertPage($site, 'events', 'Events', $this->renderEvents($site), $dryRun);
            $this->upsertPage($site, 'teams', 'Teams', $this->renderTeams($site), $dryRun);
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success($dryRun ? 'Dry-run completed.' : 'Legacy CMS migration completed.');

        return Command::SUCCESS;
    }

    private function upsertPage(object $site, string $slug, string $title, string $content, bool $dryRun): void
    {
        $page = $this->pageRepository->findOneBy(['site' => $site, 'slug' => $slug]);
        if (!$page instanceof CmsPage) {
            $page = new CmsPage($site, $title, $slug, true);
            if (!$dryRun) {
                $this->entityManager->persist($page);
            }
        }

        $block = $this->entityManager->getRepository(CmsBlock::class)->findOneBy([
            'page' => $page,
            'type' => 'rich_text',
            'sortOrder' => 10,
        ]);

        if (!$block instanceof CmsBlock) {
            $block = new CmsBlock($page, 'rich_text', $content, 10);
            $block->setPayloadJson(['source' => 'legacy-migration']);
            if (!$dryRun) {
                $this->entityManager->persist($block);
            }
            return;
        }

        if (($block->getPayloadJson()['source'] ?? null) === 'legacy-migration') {
            $block->setContent($content);
            if (!$dryRun) {
                $this->entityManager->persist($block);
            }
        }
    }

    private function renderBlog(object $site): string
    {
        $posts = $this->postRepository->findBy(['site' => $site, 'isPublished' => true], ['publishedAt' => 'DESC']);
        $html = '<h2>Blog</h2><ul>';
        foreach ($posts as $post) {
            $html .= sprintf('<li><strong>%s</strong><br>%s</li>', htmlspecialchars($post->getTitle()), htmlspecialchars($post->getExcerpt()));
        }

        return $html . '</ul>';
    }

    private function renderEvents(object $site): string
    {
        $events = $this->eventRepository->findBy(['site' => $site, 'isPublished' => true], ['startsAt' => 'ASC']);
        $html = '<h2>Events</h2><ul>';
        foreach ($events as $event) {
            $html .= sprintf('<li><strong>%s</strong> (%s)</li>', htmlspecialchars($event->getTitle()), $event->getStartsAt()->format('Y-m-d H:i'));
        }

        return $html . '</ul>';
    }

    private function renderTeams(object $site): string
    {
        $members = $this->teamRepository->findBy(['site' => $site, 'isActive' => true], ['sortOrder' => 'ASC']);
        $html = '<h2>Teams</h2><ul>';
        foreach ($members as $member) {
            $html .= sprintf('<li><strong>%s</strong> – %s</li>', htmlspecialchars($member->getName()), htmlspecialchars($member->getRole()));
        }

        return $html . '</ul>';
    }

}
