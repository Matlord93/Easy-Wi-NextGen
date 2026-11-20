<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Notification;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AccountWorkspaceHttpTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $connection->executeStatement("DELETE FROM notifications WHERE event_key LIKE 'account-workspace.%'");
            $connection->executeStatement("DELETE FROM consent_logs WHERE version LIKE 'workspace.%'");
        }
        parent::tearDown();
    }

    public function testProfileValidationKeepsTheRelevantEditorAndNeverEchoesPasswords(): void
    {
        $client = self::createClient();
        $actor = $this->loginAsRole($client, 'admin');
        $view = $client->request('POST', '/admin/profile', ['name' => 'Account editor', 'email' => $actor->getEmail(), 'password' => 'short', 'password_confirm' => 'mismatch']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('security', $view->filterXPath('//*[@data-panel-tabs="admin-profile"]')->attr('data-panel-active'));
        self::assertCount(0, $view->filterXPath('//input[@type="password" and @value]'));
        $view = $client->request('POST', '/admin/profile', ['name' => 'Kept name', 'email' => 'invalid', 'password' => '', 'password_confirm' => '']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('profile', $view->filterXPath('//*[@data-panel-tabs="admin-profile"]')->attr('data-panel-active'));
        self::assertSame('Kept name', $view->filterXPath('//*[@data-panel-section="profile"]//input[@name="name"]')->attr('value'));
        $view = $client->request('GET', '/admin/profile?tab=security');
        self::assertResponseIsSuccessful();
        self::assertSame('security', $view->filterXPath('//*[@data-panel-tabs="admin-profile"]')->attr('data-panel-active'));
    }

    public static function notificationRoles(): iterable
    {
        yield 'admin' => ['admin', '/admin/notifications'];
        yield 'customer' => ['customer', '/notifications'];
    }

    #[DataProvider('notificationRoles')]
    public function testNotificationFragmentsKeepReadStateAndRecipientBoundaries(string $role, string $prefix): void
    {
        $client = self::createClient();
        $actor = 'admin' === $role ? $this->loginAsRole($client, 'admin') : $this->loginCustomer($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $actor = $em->find(User::class, $actor->getId());
        $other = $this->createUser('foreign-notification@example.test', UserType::Customer);
        $own = new Notification($actor, 'system', 'Own workspace notice', 'Owned message', 'account-workspace.own');
        $foreign = new Notification($other, 'system', 'FOREIGN-WORKSPACE-NOTICE', 'Foreign message', 'account-workspace.foreign');
        $em->persist($own);
        $em->persist($foreign);
        $em->flush();
        $view = $client->request('GET', $prefix);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//*[@data-notifications]'));
        self::assertCount(1, $view->filterXPath('//*[@id="notification-'.$own->getId().'" and @data-notification-state="unread"]'));
        self::assertStringNotContainsString('FOREIGN-WORKSPACE-NOTICE', (string) $client->getResponse()->getContent());
        for ($i = 0; $i < 2; ++$i) {
            $view = $client->request('POST', $prefix.'/'.$own->getId().'/read');
            self::assertResponseIsSuccessful();
            self::assertCount(1, $view->filterXPath('//*[@id="notification-'.$own->getId().'" and @data-notification-state="read"]'));
            self::assertCount(0, $view->filterXPath('//button[contains(@hx-post,"/read")]'));
        }
        $client->request('POST', $prefix.'/'.$foreign->getId().'/read');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $prefix.'/read-all');
        self::assertResponseStatusCodeSame(302);
        $saved = self::getContainer()->get(EntityManagerInterface::class)->find(Notification::class, $foreign->getId());
        self::assertFalse($saved->isRead());
    }

    public function testPrivacyFragmentsRetainUniqueTargetsAndConsentHistoryAfterRepeatedUpdates(): void
    {
        $client = self::createClient();
        $this->loginCustomer($client);
        foreach ([['/gdpr/exports', 'gdpr-export-table'], ['/gdpr/delete', 'gdpr-delete-status'], ['/gdpr/consents', 'consent-table']] as [$url, $target]) {
            $view = $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $view->filterXPath('//*[@id="'.$target.'"]'));
            $fragment = $view->filterXPath('//*[@id="'.$target.'"]')->attr('hx-get');
            for ($i = 0; $i < 2; ++$i) {
                $view = $client->request('GET', $fragment);
                self::assertResponseIsSuccessful();
                self::assertCount(1, $view->filterXPath('//*[@id="'.$target.'"]'));
                self::assertSame($fragment, $view->filterXPath('//*[@id="'.$target.'"]')->attr('hx-get'));
            }
        }
        foreach (['workspace.first', 'workspace.second'] as $version) {
            $view = $client->request('POST', '/gdpr/consents', ['type' => 'privacy', 'version' => $version]);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $view->filterXPath('//*[@id="consent-table" and starts-with(@hx-trigger,"consent-log-updated[") and contains(@hx-trigger,"#consent-table")]'));
            self::assertStringContainsString($version, (string) $client->getResponse()->getContent());
        }
        $client->request('GET', '/gdpr/consents/table');
        self::assertStringContainsString('workspace.first', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('workspace.second', (string) $client->getResponse()->getContent());
    }

    public function testMailTemplateEditorsAreIndependentOfTheGlobalSettingsForm(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        foreach (['general', 'logging', 'security'] as $tab) {
            $view = $client->request('GET', '/admin/settings?tab='.$tab);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $view->filterXPath('//form[@action="/admin/settings"]//input[@name="tab" and @value="'.$tab.'"]'));
        }
        foreach (['de', 'en'] as $language) {
            $view = $client->request('GET', '/admin/settings?tab=mail_templates&lang='.$language);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $view->filterXPath('//form[@action="/admin/settings"]'));
            self::assertGreaterThan(1, $view->filterXPath('//form[starts-with(@action,"/admin/settings/mail-templates/")]')->count());
            self::assertGreaterThan(0, $view->filterXPath('//form[starts-with(@action,"/admin/settings/mail-templates/'.$language.'/")]//textarea')->count());
        }
    }

    public function testSecurityCentreKeepsFiltersAndValidationInTheirSection(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        foreach (['firewall=node', 'fail2ban=node', 'ruleset=applied', 'ddos=updated', 'event_ip=192.0.2.3'] as $query) {
            $view = $client->request('GET', '/admin/security?'.$query);
            self::assertResponseIsSuccessful();
            $section = match (explode('=', $query)[0]) {
                'ddos' => 'providers',
                'event_ip' => 'events',
                default => explode('=', $query)[0],
            };
            self::assertSame($section, $view->filterXPath('//*[@data-panel-tabs="security-centre"]')->attr('data-panel-active'));
        }
        foreach (['/admin/security/ruleset/apply' => 'ruleset', '/admin/security/ddos' => 'providers'] as $url => $section) {
            $view = $client->request('POST', $url);
            self::assertResponseStatusCodeSame(400);
            self::assertSame($section, $view->filterXPath('//*[@data-panel-tabs="security-centre"]')->attr('data-panel-active'));
        }
    }

    public function testCustomerCannotReadAdminSecurityAndConfiguration(): void
    {
        $client = self::createClient();
        $this->loginCustomer($client);
        foreach (['/admin/profile', '/admin/security', '/admin/settings', '/admin/gdpr'] as $url) {
            $client->request('GET', $url);
            self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
        }
    }

    private function loginCustomer(KernelBrowser $client): User
    {
        $customer = $this->createUser('account-customer@example.test', UserType::Customer);
        $customer->setCustomerAccessEnabled(true);
        $customer->setMemberAccessEnabled(false);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->request('POST', '/login', ['email' => $customer->getEmail(), 'password' => 'P@ssw0rd!']);

        return $customer;
    }
}
