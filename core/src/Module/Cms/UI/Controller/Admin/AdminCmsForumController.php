<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Admin;

use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\ForumMemberBan;
use App\Module\Core\Domain\Entity\ForumPost;
use App\Module\Core\Domain\Entity\ForumPostReport;
use App\Module\Core\Domain\Entity\ForumThread;
use App\Module\Core\Domain\Entity\User;
use App\Repository\ForumBoardRepository;
use App\Repository\ForumCategoryRepository;
use App\Repository\ForumMemberBanRepository;
use App\Repository\ForumPostReportRepository;
use App\Repository\ForumPostRepository;
use App\Repository\ForumThreadRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route(path: '/admin/cms/forum')]
final class AdminCmsForumController
{
    public function __construct(
        private readonly SiteResolver $siteResolver,
        private readonly ForumCategoryRepository $categoryRepository,
        private readonly ForumBoardRepository $boardRepository,
        private readonly ForumThreadRepository $threadRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumPostReportRepository $reportRepository,
        private readonly ForumMemberBanRepository $banRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_cms_forum', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $reportStatus = (string) $request->query->get('report_status', ForumPostReport::STATUS_OPEN);
        if (!in_array($reportStatus, [ForumPostReport::STATUS_OPEN, ForumPostReport::STATUS_RESOLVED], true)) {
            $reportStatus = ForumPostReport::STATUS_OPEN;
        }

        return new Response($this->twig->render('admin/cms/forum/index.html.twig', [
            'categories' => $this->categoryRepository->findBy(['site' => $site], ['sortOrder' => 'ASC', 'id' => 'ASC']),
            'boards' => $this->boardRepository->findBy(['site' => $site], ['sortOrder' => 'ASC', 'id' => 'ASC']),
            'threads' => $this->threadRepository->findBy(['site' => $site], ['updatedAt' => 'DESC']),
            'posts' => $this->postRepository->findBy(['site' => $site], ['updatedAt' => 'DESC']),
            'reports' => $this->reportRepository->findBy(['status' => $reportStatus], ['createdAt' => 'DESC']),
            'report_status' => $reportStatus,
            'activeNav' => 'cms-forum',
        ]));
    }

    #[Route(path: '/categories', name: 'admin_cms_forum_category_create', methods: ['POST'])]
    public function createCategory(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('admin_forum_category_create', (string) $request->request->get('_token', ''))) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $title = trim((string) $request->request->get('title', ''));
        $slug = trim((string) $request->request->get('slug', ''));
        if ($title === '' || $slug === '') {
            return new RedirectResponse('/admin/cms/forum');
        }

        $category = new \App\Module\Core\Domain\Entity\ForumCategory($site, $title, $slug);
        $category->setSortOrder((int) $request->request->get('sort_order', 0));

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/forum');
    }

    #[Route(path: '/boards', name: 'admin_cms_forum_board_create', methods: ['POST'])]
    public function createBoard(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('admin_forum_board_create', (string) $request->request->get('_token', ''))) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $category = $this->categoryRepository->find((int) $request->request->get('category_id', 0));
        if (!$category instanceof \App\Module\Core\Domain\Entity\ForumCategory || $category->getSite()->getId() !== $site->getId()) {
            return new RedirectResponse('/admin/cms/forum');
        }

        $title = trim((string) $request->request->get('title', ''));
        $slug = trim((string) $request->request->get('slug', ''));
        if ($title === '' || $slug === '') {
            return new RedirectResponse('/admin/cms/forum');
        }

        $board = new \App\Module\Core\Domain\Entity\ForumBoard($site, $category, $title, $slug);
        $board->setDescription(trim((string) $request->request->get('description', '')) ?: null);
        $board->setSortOrder((int) $request->request->get('sort_order', 0));
        $board->setActive($request->request->get('is_active', '1') === '1');

        $this->entityManager->persist($board);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/forum');
    }

    #[Route(path: '/threads/{id}/toggle-close', name: 'admin_cms_forum_thread_toggle_close', methods: ['POST'])]
    public function toggleCloseThread(Request $request, int $id): Response
    {
        return $this->toggleThreadFlag($request, $id, 'close');
    }

    #[Route(path: '/threads/{id}/toggle-pin', name: 'admin_cms_forum_thread_toggle_pin', methods: ['POST'])]
    public function togglePinThread(Request $request, int $id): Response
    {
        return $this->toggleThreadFlag($request, $id, 'pin');
    }

    #[Route(path: '/posts/{id}/toggle-delete', name: 'admin_cms_forum_post_toggle_delete', methods: ['POST'])]
    public function toggleDeletePost(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('admin_forum_post_delete_' . $id, (string) $request->request->get('_token', ''))) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $post = $this->postRepository->find($id);
        $admin = $request->attributes->get('current_user');
        if ($post instanceof ForumPost && $post->getSite()->getId() === $site->getId()) {
            $post->setDeleted(!$post->isDeleted(), $admin instanceof User ? $admin : null);
            $this->entityManager->flush();
        }

        return new RedirectResponse('/admin/cms/forum');
    }

    #[Route(path: '/reports/{id}/resolve', name: 'admin_cms_forum_report_resolve', methods: ['POST'])]
    public function resolveReport(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('admin_forum_report_resolve_' . $id, (string) $request->request->get('_token', ''))) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $report = $this->reportRepository->find($id);
        $admin = $request->attributes->get('current_user');
        if (!$report instanceof ForumPostReport || !$admin instanceof User) {
            return new RedirectResponse('/admin/cms/forum');
        }

        if ($request->request->get('delete_post', '0') === '1') {
            $report->getPost()->setDeleted(true, $admin);
        }

        if ($request->request->get('ban_until', '') !== '') {
            $author = $report->getPost()->getAuthorUser();
            if ($author instanceof User) {
                $ban = $this->banRepository->findOneBy(['user' => $author]);
                if (!$ban instanceof ForumMemberBan) {
                    $ban = new ForumMemberBan($author);
                    $this->entityManager->persist($ban);
                }
                $ban->setBannedUntil(new \DateTimeImmutable((string) $request->request->get('ban_until')));
                $ban->setReason((string) $request->request->get('ban_reason', 'Report moderation'));
            }
        }

        $report->resolve($admin);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/forum');
    }

    private function toggleThreadFlag(Request $request, int $id, string $flag): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $tokenId = $flag === 'close' ? 'admin_forum_thread_close_' . $id : 'admin_forum_thread_pin_' . $id;
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token', ''))) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $thread = $this->threadRepository->find($id);
        if ($thread instanceof ForumThread && $thread->getSite()->getId() === $site->getId()) {
            if ($flag === 'close') {
                $thread->setClosed(!$thread->isClosed());
            }
            if ($flag === 'pin') {
                $thread->setPinned(!$thread->isPinned());
            }
            $this->entityManager->flush();
        }

        return new RedirectResponse('/admin/cms/forum');
    }

    private function isCsrfTokenValid(string $tokenId, string $submittedToken): bool
    {
        return $this->csrfTokenManager->isTokenValid(new CsrfToken($tokenId, $submittedToken));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
