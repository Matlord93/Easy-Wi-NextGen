<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\AuditLog;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;

final class AdminOperationsWorkspaceTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
    }

    public function testJobPaginationSurvivesRepeatedRefreshAndKeepsDetailsAvailable(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(AppSettingsService::class)->updateSettings(['job_history_mode' => 'all']);
        for ($i = 0; $i < 26; ++$i) {
            $job = new Job('instance.start', ['instance_id' => $i + 1]);
            $em->persist($job);
        }
        $em->flush();
        foreach (['/admin/jobs?page=2', '/admin/jobs/table?page=2', '/admin/jobs/table?page=2'] as $url) {
            $view = $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $view->filterXPath('//*[@id="jobs-table"]'));
            self::assertSame('/admin/jobs/table?page=2', $view->filterXPath('//*[@id="jobs-table"]')->attr('hx-get'));
            self::assertSame('/admin/jobs/table?page=2', $view->filterXPath('//*[@id="jobs-table"]//button[@hx-target="#jobs-table"]')->attr('hx-get'));
            self::assertGreaterThan(0, $view->filterXPath('//tbody/tr/td/a')->count());
        }
        $view = $client->request('GET', '/admin/jobs/'.$job->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//*[@data-panel-section="payload"]//pre'));
        self::assertCount(1, $view->filterXPath('//*[@data-panel-section="logs"]//*[@id="job-log-tail"]'));
        self::assertStringContainsString('26', $view->filterXPath('//*[@data-panel-section="payload"]//pre')->text());
    }

    public function testAuditRefreshAndCsvKeepTheSameFilters(): void
    {
        $client = self::createClient();
        $actor = $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $actor = $em->find(User::class, $actor->getId());
        foreach (['keep', 'drop'] as $suffix) {
            $log = new AuditLog($actor, 'workspace.fixture.'.$suffix, ['marker' => 'entry-'.$suffix]);
            $log->setHashCurrent(hash('sha256', 'entry-'.$suffix));
            $em->persist($log);
        }
        $em->flush();
        $query = http_build_query(['action' => 'workspace.fixture.keep', 'actor' => $actor->getEmail(), 'since' => '2020-01-01T00:00:00+00:00']);
        $view = $client->request('GET', '/admin/audit-logs?'.$query);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//*[@id="audit-logs-table"]'));
        $export = $view->filterXPath('//a[contains(@href,"/admin/audit-logs/export.csv?")]')->attr('href');
        for ($i = 0; $i < 2; ++$i) {
            $refresh = $view->filterXPath('//*[@id="audit-logs-table"]')->attr('hx-get');
            parse_str((string) parse_url($refresh, PHP_URL_QUERY), $filters);
            self::assertSame('workspace.fixture.keep', $filters['action']);
            self::assertSame($actor->getEmail(), $filters['actor']);
            self::assertSame('2020-01-01T00:00:00+00:00', $filters['since']);
            $view = $client->request('GET', $refresh);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $view->filterXPath('//*[@id="audit-logs-table"]'));
            self::assertStringContainsString('entry-keep', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('entry-drop', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('audit_event_workspace_fixture_keep', (string) $client->getResponse()->getContent());
        }
        $client->request('GET', $export);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('entry-keep', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('entry-drop', (string) $client->getResponse()->getContent());
    }

    public function testInvalidUserCreationKeepsInputsAndAllowsCorrection(): void
    {
        $client = self::createClient();
        $actor = $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->find(User::class, $actor->getId())->setType(UserType::Superadmin);
        $em->flush();
        $payload = ['email' => 'new-workspace@example.test', 'type' => 'customer', 'database_limit' => '7', 'password' => 'P@ssw0rd!', 'password_confirm' => 'different', 'member_access_enabled' => '1', 'customer_access_enabled' => '1'];
        $view = $client->request('POST', '/admin/users', $payload);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('create', $view->filterXPath('//*[@data-panel-tabs="users"]')->attr('data-panel-active'));
        self::assertSame($payload['email'], $view->filterXPath('//*[@id="create-user-section"]//input[@name="email"]')->attr('value'));
        self::assertSame('7', $view->filterXPath('//*[@id="create-user-section"]//input[@name="database_limit"]')->attr('value'));
        self::assertCount(2, $view->filterXPath('//*[@id="create-user-section"]//input[@type="checkbox" and @checked]'));
        self::assertCount(0, $view->filterXPath('//*[@id="create-user-section"]//input[@type="password" and @value]'));
        $payload['password_confirm'] = $payload['password'];
        $client->request('POST', '/admin/users', $payload);
        self::assertResponseStatusCodeSame(302);
        $created = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => $payload['email']]);
        self::assertInstanceOf(User::class, $created);
        self::assertSame(7, $created->getDatabaseLimit());
        self::assertTrue($created->isMemberAccessEnabled());
        self::assertTrue($created->isCustomerAccessEnabled());
    }

    public function testRegularAdminKeepsReadOnlyUserAccess(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $view = $client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $view->filterXPath('//*[@id="create-user-section"]'));
        self::assertCount(0, $view->filterXPath('//form[contains(@action,"/admin/users")]'));
        $client->request('POST', '/admin/users', ['email' => 'forbidden@example.test']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testScheduledTasksKeepQueueActionsAndHistoryReachable(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $view = $client->request('GET', '/admin/schedules');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//nav[contains(@class,"pm-module-nav")]/a[@href="/admin/jobs"]'));
        self::assertGreaterThan(0, $view->filterXPath('//form[contains(@action,"/admin/schedules/run-now")]//input[@name="source" and @value!=""]')->count());
        self::assertGreaterThan(0, $view->filterXPath('//form[contains(@action,"/admin/schedules/toggle")]//input[@name="enabled"]')->count());
        $history = $view->filterXPath('//a[contains(@href,"/admin/schedules/history/")]')->first()->attr('href');
        $view = $client->request('GET', $history);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//a[@href="/admin/schedules" and contains(@class,"ui-button")]'));
        $view = $client->request('GET', '/admin/jobs');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//nav[contains(@class,"pm-module-nav")]/a[@href="/admin/schedules"]'));
        self::assertCount(1, $view->filterXPath('//aside//a[@aria-current="page" and @href="/admin/schedules"]'));
    }

    public function testCustomersCannotReadOperationsWorkspacesOrFragments(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'customer');
        foreach (['/admin/users', '/admin/jobs', '/admin/jobs/table?page=2', '/admin/schedules', '/admin/schedules/history/system/fixture', '/admin/audit-logs', '/admin/audit-logs/table', '/admin/audit-logs/export.csv', '/admin/backups', '/admin/backups/targets', '/admin/backups/settings'] as $url) {
            $client->request('GET', $url);
            self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
        }
    }
}
