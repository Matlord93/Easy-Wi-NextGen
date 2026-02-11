<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\UI\Controller\Admin\AdminCmsForumController;
use App\Module\Cms\UI\Controller\Public\PublicForumController;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\ForumBoard;
use App\Module\Core\Domain\Entity\ForumCategory;
use App\Module\Core\Domain\Entity\ForumPost;
use App\Module\Core\Domain\Entity\ForumPostReport;
use App\Module\Core\Domain\Entity\ForumThread;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ForumProductionReadinessTest extends KernelTestCase
{
    private static bool $schemaBootstrapped = false;

    public function testBoardPaginationBasicsAndPinnedSort(): void
    {
        self::bootKernel();
        [$site, $board] = $this->seedForum();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        for ($i = 1; $i <= 25; ++$i) {
            $thread = new ForumThread($site, $board, null, 'Thread ' . $i, 'thread-' . $i);
            $thread->markPostActivity();
            $em->persist($thread);
        }

        $pinned = new ForumThread($site, $board, null, 'Pinned important', 'pinned-important');
        $pinned->setPinned(true);
        $pinned->markPostActivity();
        $em->persist($pinned);
        $em->flush();

        /** @var PublicForumController $controller */
        $controller = self::getContainer()->get(PublicForumController::class);
        $page1 = $controller->board(Request::create('http://demo.local/forum/board/general?page=1', 'GET'), 'general');
        $page2 = $controller->board(Request::create('http://demo.local/forum/board/general?page=2', 'GET'), 'general');

        self::assertSame(20, substr_count($page1->getContent() ?? '', '/forum/thread/'));
        self::assertSame(6, substr_count($page2->getContent() ?? '', '/forum/thread/'));

        $content = $page1->getContent() ?? '';
        self::assertLessThan(
            strpos($content, 'Thread 25') ?: PHP_INT_MAX,
            strpos($content, 'Pinned important') ?: 0
        );
    }

    public function testCloseThreadBlocksReplyAndForm(): void
    {
        self::bootKernel();
        [$site, $board, $member] = $this->seedForumWithMember();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var CsrfTokenManagerInterface $csrf */
        $csrf = self::getContainer()->get(CsrfTokenManagerInterface::class);
        /** @var PublicForumController $controller */
        $controller = self::getContainer()->get(PublicForumController::class);

        $thread = new ForumThread($site, $board, $member, 'Closed', 'closed');
        $thread->setClosed(true);
        $post = new ForumPost($site, $thread, $member, 'Body');
        $em->persist($thread);
        $em->persist($post);
        $em->flush();

        $threadResponse = $controller->thread(Request::create('http://demo.local/forum/thread/' . $thread->getId(), 'GET'), $thread->getId() ?? 0);
        self::assertStringNotContainsString('/reply', $threadResponse->getContent() ?? '');

        $replyRequest = Request::create('http://demo.local/forum/thread/' . $thread->getId() . '/reply', 'POST', [
            'content' => 'Nope',
            'form_rendered_at' => (string) (time() - 3),
            '_token' => $csrf->getToken('forum_reply_' . $thread->getId())->getValue(),
        ]);
        $replyRequest->attributes->set('current_user', $member);

        $replyResponse = $controller->reply($replyRequest, $thread->getId() ?? 0);
        self::assertSame(403, $replyResponse->getStatusCode());
    }

    public function testReplyRateLimitAndReportResolveAndSoftDeleteRestore(): void
    {
        self::bootKernel();
        [$site, $board, $member, $admin] = $this->seedForumWithMemberAndAdmin();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var CsrfTokenManagerInterface $csrf */
        $csrf = self::getContainer()->get(CsrfTokenManagerInterface::class);
        /** @var PublicForumController $publicController */
        $publicController = self::getContainer()->get(PublicForumController::class);
        /** @var AdminCmsForumController $adminController */
        $adminController = self::getContainer()->get(AdminCmsForumController::class);

        $thread = new ForumThread($site, $board, $member, 'Rate', 'rate');
        $post = new ForumPost($site, $thread, $member, 'Initial');
        $em->persist($thread);
        $em->persist($post);
        $em->flush();

        for ($i = 0; $i < 5; ++$i) {
            $replyRequest = Request::create('http://demo.local/forum/thread/' . $thread->getId() . '/reply', 'POST', [
                'content' => 'Reply ' . $i,
                'form_rendered_at' => (string) (time() - 3),
                '_token' => $csrf->getToken('forum_reply_' . $thread->getId())->getValue(),
            ]);
            $replyRequest->attributes->set('current_user', $member);
            self::assertSame(302, $publicController->reply($replyRequest, $thread->getId() ?? 0)->getStatusCode());
        }

        $rateLimitRequest = Request::create('http://demo.local/forum/thread/' . $thread->getId() . '/reply', 'POST', [
            'content' => 'Reply 6',
            'form_rendered_at' => (string) (time() - 3),
            '_token' => $csrf->getToken('forum_reply_' . $thread->getId())->getValue(),
        ]);
        $rateLimitRequest->attributes->set('current_user', $member);
        self::assertSame(429, $publicController->reply($rateLimitRequest, $thread->getId() ?? 0)->getStatusCode());

        $reportRequest = Request::create('http://demo.local/forum/post/' . $post->getId() . '/report', 'POST', [
            'reason' => 'spam',
            'reason_details' => 'contains spam',
            'form_rendered_at' => (string) (time() - 3),
            '_token' => $csrf->getToken('forum_report_' . $post->getId())->getValue(),
        ]);
        $reportRequest->attributes->set('current_user', $member);
        self::assertSame(302, $publicController->reportPost($reportRequest, $post->getId() ?? 0)->getStatusCode());

        $report = $em->getRepository(ForumPostReport::class)->findOneBy(['post' => $post]);
        self::assertNotNull($report);

        $resolveRequest = Request::create('http://demo.local/admin/cms/forum/reports/' . $report->getId() . '/resolve', 'POST', [
            '_token' => $csrf->getToken('admin_forum_report_resolve_' . $report->getId())->getValue(),
            'delete_post' => '1',
        ]);
        $resolveRequest->attributes->set('current_user', $admin);
        self::assertSame(302, $adminController->resolveReport($resolveRequest, $report->getId() ?? 0)->getStatusCode());

        $em->refresh($post);
        self::assertTrue($post->isDeleted());

        $toggleDeleteRequest = Request::create('http://demo.local/admin/cms/forum/posts/' . $post->getId() . '/toggle-delete', 'POST', [
            '_token' => $csrf->getToken('admin_forum_post_delete_' . $post->getId())->getValue(),
        ]);
        $toggleDeleteRequest->attributes->set('current_user', $admin);
        $adminController->toggleDeletePost($toggleDeleteRequest, $post->getId() ?? 0);

        $em->refresh($post);
        self::assertFalse($post->isDeleted());
    }

    /** @return array{Site, ForumBoard} */
    private function seedForum(): array
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema($em);
        self::getContainer()->get('cache.rate_limiter')->clear();
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM forum_post_reports');
        $conn->executeStatement('DELETE FROM forum_posts');
        $conn->executeStatement('DELETE FROM forum_threads');
        $conn->executeStatement('DELETE FROM forum_boards');
        $conn->executeStatement('DELETE FROM forum_categories');
        $conn->executeStatement('DELETE FROM cms_site_settings');
        $conn->executeStatement('DELETE FROM user_sessions');
        $conn->executeStatement('DELETE FROM users');
        $conn->executeStatement('DELETE FROM sites');

        $site = new Site('Demo', 'demo.local');
        $settings = new CmsSiteSettings($site);
        $settings->setModuleTogglesJson(['forum' => true]);
        $category = new ForumCategory($site, 'General', 'general-cat');
        $board = new ForumBoard($site, $category, 'General Board', 'general');

        $em->persist($site);
        $em->persist($settings);
        $em->persist($category);
        $em->persist($board);
        $em->flush();

        return [$site, $board];
    }

    /** @return array{Site, ForumBoard, User} */
    private function seedForumWithMember(): array
    {
        [$site, $board] = $this->seedForum();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $member = new User('member@example.test', UserType::Customer);
        $member->setMemberAccessEnabled(true);
        $member->setPasswordHash('hash');
        $member->setEmailVerifiedAt(new \DateTimeImmutable());
        $em->persist($member);
        $em->flush();

        return [$site, $board, $member];
    }

    /** @return array{Site, ForumBoard, User, User} */
    private function seedForumWithMemberAndAdmin(): array
    {
        [$site, $board, $member] = $this->seedForumWithMember();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User('admin@example.test', UserType::Admin);
        $admin->setPasswordHash('hash');
        $em->persist($admin);
        $em->flush();

        return [$site, $board, $member, $admin];
    }

    private function ensureSchema(EntityManagerInterface $em): void
    {
        if (self::$schemaBootstrapped) {
            return;
        }

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        if ($metadata !== []) {
            $tool->dropSchema($metadata);
            $tool->createSchema($metadata);
        }

        self::$schemaBootstrapped = true;
    }
}
