<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\CmsFeatureToggle;
use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\Security\CaptchaVerifierInterface;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Cms\UI\Http\MaintenancePageResponseFactory;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\ForumBoard;
use App\Module\Core\Domain\Entity\ForumPost;
use App\Module\Core\Domain\Entity\ForumPostReport;
use App\Module\Core\Domain\Entity\ForumThread;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Repository\ForumBoardRepository;
use App\Repository\ForumCategoryRepository;
use App\Repository\ForumMemberBanRepository;
use App\Repository\ForumPostRepository;
use App\Repository\ForumThreadRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route(path: '/forum')]
final class PublicForumController
{
    private const BOARD_PAGE_SIZE = 20;
    private const THREAD_PAGE_SIZE = 30;

    public function __construct(
        private readonly SiteResolver $siteResolver,
        private readonly CmsFeatureToggle $featureToggle,
        private readonly CmsMaintenanceService $maintenanceService,
        private readonly MaintenancePageResponseFactory $maintenancePageResponseFactory,
        private readonly ForumCategoryRepository $categoryRepository,
        private readonly ForumBoardRepository $boardRepository,
        private readonly ForumThreadRepository $threadRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumMemberBanRepository $banRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly CaptchaVerifierInterface $captchaVerifier,
        private readonly RateLimiterFactory $forumReplyLimiter,
        private readonly RateLimiterFactory $forumThreadLimiter,
        private readonly RateLimiterFactory $forumReportLimiter,
        private readonly LoggerInterface $logger,
        private readonly ThemeResolver $themeResolver,
        private readonly CmsSettingsProvider $settingsProvider,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'public_forum_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $site = $this->resolveForumSite($request);
        if ($site === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $maintenanceResponse = $this->maintenanceResponse($request, $site);
        if ($maintenanceResponse instanceof Response) {
            return $maintenanceResponse;
        }

        $categories = $this->categoryRepository->findBy(['site' => $site], ['sortOrder' => 'ASC', 'id' => 'ASC']);
        $boards = $this->boardRepository->findBy(['site' => $site, 'isActive' => true], ['sortOrder' => 'ASC', 'id' => 'ASC']);

        $boardsByCategory = [];
        foreach ($boards as $board) {
            $boardsByCategory[$board->getCategory()->getId() ?? 0][] = $board;
        }

        return new Response($this->twig->render('public/forum/index.html.twig', [
            'categories' => $categories,
            'boards_by_category' => $boardsByCategory,
            'can_post' => $this->requireMemberOrAdminUser($request) instanceof User,
            'q' => '',
        ] + $this->themeContext($site, 'forum', 'Forum')));
    }

    #[Route(path: '/search', name: 'public_forum_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $site = $this->resolveForumSite($request);
        if ($site === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $q = trim((string) $request->query->get('q', ''));
        $q = mb_substr($q, 0, 100);
        $results = [];
        $error = null;
        if ($q !== '') {
            if (mb_strlen($q) < 3) {
                $error = 'Bitte mindestens 3 Zeichen eingeben.';
            } else {
                $results = $this->threadRepository->searchByQuery($site, $q);
            }
        }

        return new Response($this->twig->render('public/forum/search.html.twig', [
            'q' => $q,
            'results' => $results,
            'error' => $error,
            'can_post' => $this->requireMemberOrAdminUser($request) instanceof User,
        ] + $this->themeContext($site, 'forum', 'Forum-Suche')));
    }

    #[Route(path: '/board/{slug}', name: 'public_forum_board', methods: ['GET'])]
    public function board(Request $request, string $slug): Response
    {
        $site = $this->resolveForumSite($request);
        if ($site === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $maintenanceResponse = $this->maintenanceResponse($request, $site);
        if ($maintenanceResponse instanceof Response) {
            return $maintenanceResponse;
        }

        $board = $this->boardRepository->findOneBy(['site' => $site, 'slug' => $slug]);
        if (!$board instanceof ForumBoard || !$board->isActive()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $paginated = $this->threadRepository->paginateByBoard($board, $page, self::BOARD_PAGE_SIZE);
        $totalPages = max(1, (int) ceil($paginated['total'] / self::BOARD_PAGE_SIZE));

        return new Response($this->twig->render('public/forum/board.html.twig', [
            'board' => $board,
            'threads' => $paginated['items'],
            'page' => $page,
            'total_pages' => $totalPages,
            'can_post' => $this->requireMemberOrAdminUser($request) instanceof User,
        ] + $this->themeContext($site, 'forum', (string) $board->getTitle())));
    }

    #[Route(path: '/thread/{id}', name: 'public_forum_thread', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function thread(Request $request, int $id): Response
    {
        $site = $this->resolveForumSite($request);
        if ($site === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $maintenanceResponse = $this->maintenanceResponse($request, $site);
        if ($maintenanceResponse instanceof Response) {
            return $maintenanceResponse;
        }

        $thread = $this->threadRepository->find($id);
        if (!$thread instanceof ForumThread || $thread->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }


        $page = max(1, (int) $request->query->get('page', 1));
        $paginated = $this->postRepository->paginateVisibleByThreadWithAuthor($thread, $page, self::THREAD_PAGE_SIZE);
        $totalPages = max(1, (int) ceil($paginated['total'] / self::THREAD_PAGE_SIZE));

        return new Response($this->twig->render('public/forum/thread.html.twig', [
            'thread' => $thread,
            'posts' => $paginated['items'],
            'page' => $page,
            'total_pages' => $totalPages,
            'can_post' => $this->requireMemberOrAdminUser($request) instanceof User,
            'can_moderate' => $this->isAdmin($request),
            'report_reasons' => ['spam', 'beleidigung', 'offtopic', 'sonstiges'],
        ] + $this->themeContext($site, 'forum', (string) $thread->getTitle())));
    }


    #[Route(path: '/thread/{id}-{slug}', name: 'public_forum_thread_legacy', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function threadLegacy(int $id, string $slug): Response
    {
        return new RedirectResponse(sprintf('/forum/thread/%d', $id), Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route(path: '/thread/{id}-{slug}/reply', name: 'public_forum_reply_legacy', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function replyLegacy(int $id, string $slug): Response
    {
        return new RedirectResponse(sprintf('/forum/thread/%d/reply', $id), Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route(path: '/board/{slug}/new-thread', name: 'public_forum_new_thread', methods: ['POST'])]
    public function newThread(Request $request, string $slug): Response
    {
        $site = $this->resolveForumSite($request);
        if ($site === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $actor = $this->requireMemberOrAdminUser($request);
        if (!$actor instanceof User) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if ($this->banRepository->findActiveForUser($actor) !== null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->consumeLimiter($this->forumThreadLimiter, $request, $actor)) {
            return new Response('Zu viele neue Themen. Bitte kurz warten.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $board = $this->boardRepository->findOneBy(['site' => $site, 'slug' => $slug]);
        if (!$board instanceof ForumBoard || !$board->isActive()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        if ($this->isSpamSubmission($request)) {
            return new RedirectResponse('/forum/board/' . $board->getSlug());
        }

        $csrf = new CsrfToken('forum_new_thread_' . $board->getId(), (string) $request->request->get('_token', ''));
        if (!$this->csrfTokenManager->isTokenValid($csrf)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->captchaVerifier->verify($request, 'forum_new_thread')) {
            return new Response('Captcha ungültig.', Response::HTTP_BAD_REQUEST);
        }

        $title = trim((string) $request->request->get('title', ''));
        $content = trim((string) $request->request->get('content', ''));
        if ($title === '' || $content === '') {
            return new RedirectResponse('/forum/board/' . $board->getSlug());
        }

        $thread = new ForumThread($site, $board, $actor, $title, $this->slugify($title));
        $post = new ForumPost($site, $thread, $actor, $content);
        $thread->markPostActivity();

        $this->entityManager->persist($thread);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        return new RedirectResponse(sprintf('/forum/thread/%d', $thread->getId()));
    }

    #[Route(path: '/thread/{id}/reply', name: 'public_forum_reply', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function reply(Request $request, int $id): Response
    {
        $site = $this->resolveForumSite($request);
        if ($site === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $actor = $this->requireMemberOrAdminUser($request);
        if (!$actor instanceof User) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if ($this->banRepository->findActiveForUser($actor) !== null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->consumeLimiter($this->forumReplyLimiter, $request, $actor)) {
            return new Response('Zu viele Antworten. Bitte kurz warten.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $thread = $this->threadRepository->find($id);
        if (!$thread instanceof ForumThread || $thread->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        if ($thread->isClosed()) {
            return new Response('Thread geschlossen.', Response::HTTP_FORBIDDEN);
        }

        if ($this->isSpamSubmission($request)) {
            return new RedirectResponse(sprintf('/forum/thread/%d', $thread->getId()));
        }

        $csrf = new CsrfToken('forum_reply_' . $thread->getId(), (string) $request->request->get('_token', ''));
        if (!$this->csrfTokenManager->isTokenValid($csrf)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->captchaVerifier->verify($request, 'forum_reply')) {
            return new Response('Captcha ungültig.', Response::HTTP_BAD_REQUEST);
        }

        $content = trim((string) $request->request->get('content', ''));
        if ($content === '') {
            return new RedirectResponse(sprintf('/forum/thread/%d', $thread->getId()));
        }

        $post = new ForumPost($site, $thread, $actor, $content);
        $thread->markPostActivity();

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        return new RedirectResponse(sprintf('/forum/thread/%d', $thread->getId()));
    }

    #[Route(path: '/post/{id}/report', name: 'public_forum_post_report', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function reportPost(Request $request, int $id): Response
    {
        $site = $this->resolveForumSite($request);
        if ($site === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $actor = $this->requireMemberOrAdminUser($request);
        if (!$actor instanceof User) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->consumeLimiter($this->forumReportLimiter, $request, $actor)) {
            return new Response('Zu viele Meldungen. Bitte kurz warten.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $post = $this->postRepository->find($id);
        if (!$post instanceof ForumPost || $post->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $csrf = new CsrfToken('forum_report_' . $post->getId(), (string) $request->request->get('_token', ''));
        if (!$this->csrfTokenManager->isTokenValid($csrf)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if ($this->isSpamSubmission($request)) {
            return new RedirectResponse(sprintf('/forum/thread/%d', $post->getThread()->getId()));
        }

        $reason = trim((string) $request->request->get('reason', ''));
        $details = trim((string) $request->request->get('reason_details', ''));
        if ($reason === '') {
            return new Response('Reason required.', Response::HTTP_BAD_REQUEST);
        }

        $report = new ForumPostReport($post, $actor, $reason);
        $report->setDetails($details === '' ? null : $details);
        $report->setReporterIpHash($this->hashIp($request));

        $this->entityManager->persist($report);
        $this->entityManager->flush();

        return new RedirectResponse(sprintf('/forum/thread/%d#post-%d', $post->getThread()->getId(), $post->getId()));
    }

    private function resolveForumSite(Request $request): ?\App\Module\Core\Domain\Entity\Site
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return null;
        }

        if (!$this->featureToggle->isEnabled($site, 'forum')) {
            return null;
        }

        return $site;
    }

    private function requireMemberOrAdminUser(Request $request): ?User
    {
        $user = $request->attributes->get('current_user');
        if (!$user instanceof User) {
            return null;
        }

        if (($user->isAdmin() || in_array('ROLE_MEMBER', $user->getRoles(), true)) && $user->getEmailVerifiedAt() !== null) {
            return $user;
        }

        return null;
    }

    private function maintenanceResponse(Request $request, \App\Module\Core\Domain\Entity\Site $site): ?Response
    {
        $maintenance = $this->maintenanceService->resolve($request, $site);
        if (!$maintenance['active']) {
            return null;
        }

        return $this->maintenancePageResponseFactory->create($maintenance);
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    private function consumeLimiter(RateLimiterFactory $factory, Request $request, ?User $actor): bool
    {
        $key = $actor instanceof User
            ? 'u:' . ($actor->getId() ?? $actor->getEmail())
            : 'ip:' . ($request->getClientIp() ?? 'unknown');

        return $factory->create($key)->consume()->isAccepted();
    }

    private function isSpamSubmission(Request $request): bool
    {
        $honeypot = trim((string) $request->request->get('website', ''));
        $renderedAt = (int) $request->request->get('form_rendered_at', 0);
        $tooFast = $renderedAt > 0 && (time() - $renderedAt) < 2;

        if ($honeypot !== '' || $tooFast) {
            $this->logger->warning('Forum spam protection triggered.', [
                'ip' => $request->getClientIp(),
                'has_honeypot' => $honeypot !== '',
                'too_fast' => $tooFast,
            ]);

            return true;
        }

        return false;
    }

    private function hashIp(Request $request): ?string
    {
        $ip = $request->getClientIp();
        if ($ip === null) {
            return null;
        }

        return hash('sha256', $ip);
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->attributes->get('current_user');

        return $user instanceof User && $user->isAdmin();
    }

    /** @return array<string,mixed> */
    private function themeContext(Site $site, string $slug, string $title): array
    {
        $templateKey = $this->themeResolver->resolveThemeKey($site);

        return [
            'active_theme' => $templateKey,
            'template_key' => $templateKey,
            'page' => ['slug' => $slug, 'title' => $title],
            'cms_navigation' => $this->settingsProvider->getNavigationLinks($site),
            'cms_footer_links' => $this->settingsProvider->getFooterLinks($site),
            'cms_branding' => $this->settingsProvider->getBranding($site),
        ];
    }

}
