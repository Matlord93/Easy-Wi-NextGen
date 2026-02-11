<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\UI\Controller\Admin\AdminCmsEventsController;
use App\Module\Cms\UI\Controller\Admin\AdminCmsTeamController;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdminCmsEventsTeamSmokeTest extends KernelTestCase
{
    public function testAdminCanCreateEventAndTeamMember(): void
    {
        self::bootKernel();
        $this->seedSite();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('admin-events-team@example.test', UserType::Admin);
        $admin->setPasswordHash('hash');
        $em->persist($admin);
        $em->flush();

        /** @var AdminCmsEventsController $events */
        $events = self::getContainer()->get(AdminCmsEventsController::class);
        /** @var AdminCmsTeamController $team */
        $team = self::getContainer()->get(AdminCmsTeamController::class);

        $eventReq = Request::create('http://demo.local/admin/cms/events', 'POST', [
            'title' => 'Summer Cup',
            'slug' => 'summer-cup',
            'description' => 'Tournament',
            'start_at' => '2030-07-01 12:00:00',
            'status' => 'planned',
            'is_published' => 'on',
        ]);
        $eventReq->attributes->set('current_user', $admin);
        self::assertSame(302, $events->create($eventReq)->getStatusCode());

        $teamReq = Request::create('http://demo.local/admin/cms/team', 'POST', [
            'name' => 'Bob',
            'role_title' => 'Captain',
            'bio' => 'Bio',
            'sort_order' => '1',
            'is_active' => '1',
            'socials_json' => '{"x":"https://x.example/bob"}',
        ]);
        $teamReq->attributes->set('current_user', $admin);
        self::assertSame(302, $team->create($teamReq)->getStatusCode());

        self::assertSame(1, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM cms_events WHERE slug='summer-cup'"));
        self::assertSame(1, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM team_members WHERE name='Bob'"));
    }

    private function seedSite(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM cms_events');
        $conn->executeStatement('DELETE FROM team_members');
        $conn->executeStatement('DELETE FROM users');
        $conn->executeStatement('DELETE FROM sites');
        $site = new Site('Demo', 'demo.local');
        $em->persist($site);
        $em->flush();
    }
}
