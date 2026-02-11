<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\UI\Controller\Public\PublicForumController;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\ForumBoard;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ForumPostFlowSmokeTest extends KernelTestCase
{
    private static bool $schemaBootstrapped = false;

    public function testMemberCanCreateThreadAndReply(): void
    {
        self::bootKernel();
        [$board] = $this->seedForumBoard();

        /** @var PublicForumController $controller */
        $controller = self::getContainer()->get(PublicForumController::class);

        $member = new User('member-flow@example.test', UserType::Customer);
        $member->setMemberAccessEnabled(true);
        $member->setPasswordHash('test-hash');
        $member->setEmailVerifiedAt(new \DateTimeImmutable());

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($member);
        $em->flush();

        /** @var CsrfTokenManagerInterface $csrf */
        $csrf = self::getContainer()->get(CsrfTokenManagerInterface::class);

        $newThreadRequest = Request::create('http://demo.local/forum/board/general/new-thread', 'POST', [
            'title' => 'Hello Forum',
            'content' => 'First post content',
            '_token' => $csrf->getToken('forum_new_thread_' . $board->getId())->getValue(),
        ]);
        $newThreadRequest->attributes->set('current_user', $member);

        $createResponse = $controller->newThread($newThreadRequest, 'general');
        self::assertSame(302, $createResponse->getStatusCode());

        $location = (string) $createResponse->headers->get('Location');
        self::assertStringContainsString('/forum/thread/', $location);

        preg_match('#/forum/thread/(\d+)$#', $location, $matches);
        self::assertNotEmpty($matches);

        $threadId = (int) $matches[1];

        $replyRequest = Request::create(sprintf('http://demo.local/forum/thread/%d/reply', $threadId), 'POST', [
            'content' => 'Second post reply',
            '_token' => $csrf->getToken('forum_reply_' . $threadId)->getValue(),
        ]);
        $replyRequest->attributes->set('current_user', $member);

        $replyResponse = $controller->reply($replyRequest, $threadId);
        self::assertSame(302, $replyResponse->getStatusCode());

        $posts = $em->getRepository(\App\Module\Core\Domain\Entity\ForumPost::class)
            ->findBy(['thread' => $threadId], ['createdAt' => 'ASC']);

        self::assertCount(2, $posts);
        self::assertSame('First post content', $posts[0]->getContent());
        self::assertSame('Second post reply', $posts[1]->getContent());
    }

    public function testReplyOnClosedThreadIsBlocked(): void
    {
        self::bootKernel();
        [$board] = $this->seedForumBoard();

        /** @var PublicForumController $controller */
        $controller = self::getContainer()->get(PublicForumController::class);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var CsrfTokenManagerInterface $csrf */
        $csrf = self::getContainer()->get(CsrfTokenManagerInterface::class);

        $member = new User('member-closed@example.test', UserType::Customer);
        $member->setMemberAccessEnabled(true);
        $member->setPasswordHash('test-hash');
        $member->setEmailVerifiedAt(new \DateTimeImmutable());
        $em->persist($member);
        $em->flush();

        $threadRequest = Request::create('http://demo.local/forum/board/general/new-thread', 'POST', [
            'title' => 'Closed Topic',
            'content' => 'Initial',
            '_token' => $csrf->getToken('forum_new_thread_' . $board->getId())->getValue(),
        ]);
        $threadRequest->attributes->set('current_user', $member);

        $createResponse = $controller->newThread($threadRequest, 'general');
        self::assertSame(302, $createResponse->getStatusCode());

        $location = (string) $createResponse->headers->get('Location');
        preg_match('#/forum/thread/(\d+)$#', $location, $matches);
        self::assertNotEmpty($matches);

        $threadId = (int) $matches[1];

        $thread = $em->getRepository(\App\Module\Core\Domain\Entity\ForumThread::class)->find($threadId);
        self::assertNotNull($thread);
        $thread->setClosed(true);
        $em->flush();

        $replyRequest = Request::create(sprintf('http://demo.local/forum/thread/%d/reply', $threadId), 'POST', [
            'content' => 'Should be blocked',
            '_token' => $csrf->getToken('forum_reply_' . $threadId)->getValue(),
        ]);
        $replyRequest->attributes->set('current_user', $member);
        $replyResponse = $controller->reply($replyRequest, $threadId);

        self::assertSame(403, $replyResponse->getStatusCode());
    }

    /** @return array{ForumBoard} */
    private function seedForumBoard(): array
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema($em);

        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM forum_posts');
        $conn->executeStatement('DELETE FROM forum_threads');
        $conn->executeStatement('DELETE FROM forum_boards');
        $conn->executeStatement('DELETE FROM forum_categories');
        $conn->executeStatement('DELETE FROM cms_site_settings');
        $conn->executeStatement('DELETE FROM sites');

        $site = new Site('Demo', 'demo.local');
        $settings = new CmsSiteSettings($site);
        $settings->setModuleTogglesJson(['blog' => true, 'events' => true, 'team' => true, 'forum' => true, 'media' => true]);

        $category = new \App\Module\Core\Domain\Entity\ForumCategory($site, 'General', 'general-cat');
        $board = new ForumBoard($site, $category, 'General Board', 'general');

        $em->persist($site);
        $em->persist($settings);
        $em->persist($category);
        $em->persist($board);
        $em->flush();

        return [$board];
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
