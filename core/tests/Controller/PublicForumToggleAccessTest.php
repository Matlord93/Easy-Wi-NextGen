<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\UI\Controller\Public\PublicForumController;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class PublicForumToggleAccessTest extends KernelTestCase
{
    private static bool $schemaBootstrapped = false;

    public function testForumToggleOffReturns404(): void
    {
        self::bootKernel();
        $this->seedForumContext(false);

        /** @var PublicForumController $controller */
        $controller = self::getContainer()->get(PublicForumController::class);

        $request = Request::create('http://demo.local/forum', 'GET');
        $response = $controller->index($request);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testForumToggleOnGuestCanView(): void
    {
        self::bootKernel();
        $this->seedForumContext(true);

        /** @var PublicForumController $controller */
        $controller = self::getContainer()->get(PublicForumController::class);

        $request = Request::create('http://demo.local/forum', 'GET');
        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testForumToggleOnNonMemberCannotPost(): void
    {
        self::bootKernel();
        $this->seedForumContext(true);

        /** @var PublicForumController $controller */
        $controller = self::getContainer()->get(PublicForumController::class);

        $request = Request::create('http://demo.local/forum/board/general/new-thread', 'POST', [
            'title' => 'X',
            'content' => 'Y',
            '_token' => 'invalid',
        ]);
        $request->attributes->set('current_user', new User('user@example.test', UserType::Customer));
        $response = $controller->newThread($request, 'general');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testForumToggleOnMemberCanView(): void
    {
        self::bootKernel();
        $this->seedForumContext(true);

        /** @var PublicForumController $controller */
        $controller = self::getContainer()->get(PublicForumController::class);

        $request = Request::create('http://demo.local/forum', 'GET');
        $request->attributes->set('current_user', $this->memberUser('member-on@example.test'));
        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
    }

    private function seedForumContext(bool $forumEnabled): void
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
        $settings->setModuleTogglesJson(['blog' => true, 'events' => true, 'team' => true, 'forum' => $forumEnabled, 'media' => true]);

        $category = new \App\Module\Core\Domain\Entity\ForumCategory($site, 'General', 'general-cat');
        $board = new \App\Module\Core\Domain\Entity\ForumBoard($site, $category, 'General Board', 'general');

        $em->persist($site);
        $em->persist($settings);
        $em->persist($category);
        $em->persist($board);
        $em->flush();
    }

    private function memberUser(string $email): User
    {
        $user = new User($email, UserType::Customer);
        $user->setMemberAccessEnabled(true);

        return $user;
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
