<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\ModuleSetting;
use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotCustomerLimits;
use App\Module\Musicbot\Domain\Entity\MusicbotQueueItem;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotWorkspaceHttpTest extends AbstractWebTestCase
{
    /** @var array<string, bool|null> */
    private array $originalModules = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->clearMusicFixtures($em);
        foreach (['musicbot', 'sinusbot'] as $key) {
            $module = $em->getRepository(ModuleSetting::class)->findOneBy(['moduleKey' => $key]);
            $this->originalModules[$key] = $module?->isEnabled();
            if ($module === null) {
                $em->persist(new ModuleSetting($key, '1', true));
            } else {
                $module->setEnabled(true);
            }
        }
        $em->flush();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $this->clearMusicFixtures($em);
            $em->clear();
            foreach ($this->originalModules as $key => $enabled) {
                $module = $em->getRepository(ModuleSetting::class)->findOneBy(['moduleKey' => $key]);
                if ($module !== null) {
                    if ($enabled === null) {
                        $em->remove($module);
                    } else {
                        $module->setEnabled($enabled);
                    }
                }
            }
            $em->flush();
        }
        parent::tearDown();
    }

    private function clearMusicFixtures(EntityManagerInterface $em): void
    {
        $connection = $em->getConnection();
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        foreach ($connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' AND (name LIKE 'musicbot_%' OR name LIKE 'sinusbot_%')") as $table) {
            $connection->executeStatement('DELETE FROM "'.str_replace('"', '""', $table).'"');
        }
        $connection->executeStatement('PRAGMA foreign_keys = ON');
    }

    public function testAdminWorkspacesRetainPanelNavigationAndNativeOperations(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $customer = $this->createUser('music-workspace@example.test', UserType::Customer);
        [$bot, $multi, $solo] = $this->fixtures($customer);
        foreach (['/admin/musicbots', '/admin/musicbots/new', '/admin/musicbots/limits', '/admin/musicbots/plugins', '/admin/musicbots/teamspeak-backend', '/admin/musicbots/'.$bot->getId(), '/admin/sinusbot/nodes', '/admin/sinusbot/nodes/new'] as $url) {
            $view = $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $view->filterXPath('//aside[contains(@class,"ng-sidebar")]'));
            self::assertCount(1, $view->filterXPath('//header[contains(@class,"pm-page-head")]/div/h1'));
        }
        $view = $client->request('GET', '/admin/musicbots/'.$bot->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//*[@data-panel-section="connections"]//form[@action="/admin/musicbots/'.$bot->getId().'/connections/teamspeak"]'));
        self::assertCount(1, $view->filterXPath('//*[@data-panel-section="manage"]//form[contains(@action,"/delete")]'));
        foreach ([$multi, $solo] as $node) {
            $view = $client->request('GET', '/admin/sinusbot/nodes/'.$node->getId());
            self::assertResponseIsSuccessful();
            self::assertCount($node === $multi ? 1 : 0, $view->filterXPath('//form[@action="/admin/sinusbot/instances/create"]'));
            self::assertCount(1, $view->filterXPath('//*[@data-panel-section="manage"]//form[contains(@action,"/reveal-credentials")]//input[@name="_token" and @value!=""]'));
            self::assertCount(1, $view->filterXPath('//*[@data-panel-section="manage"]//form[contains(@action,"/delete")]//input[@name="_token" and @value!=""]'));
        }
    }

    public function testCustomerQueueAndAllExistingSectionsRemainAvailableAndForeignBotIsDenied(): void
    {
        $client = self::createClient();
        $customer = $this->createUser('owner-music-workspace@example.test', UserType::Customer);
        $customer->setCustomerAccessEnabled(true);
        $customer->setMemberAccessEnabled(false);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        [$own] = $this->fixtures($customer);
        $other = $this->createUser('foreign-music-workspace@example.test', UserType::Customer);
        [$foreign] = $this->fixtures($other);
        $client->request('POST', '/login', ['email' => $customer->getEmail(), 'password' => 'P@ssw0rd!']);
        $view = $client->request('GET', '/musicbots/'.$own->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//header[contains(@class,"pm-page-head")]//h1'));
        self::assertCount(1, $view->filterXPath('//*[@data-live-region="now-playing"]//form[@data-volume-form]'));
        self::assertCount(16, $view->filterXPath('//button[@data-mb-tab]'));
        self::assertCount(6, $view->filterXPath('//button[@data-mb-group-tab]'));
        self::assertCount(0, $view->filterXPath('//*[@id="panel-monitoring"]//*[@data-state="healthy"]'));
        self::assertStringNotContainsString('Live status ready', (string) $client->getResponse()->getContent());
        foreach (['queue', 'playlists', 'autodj', 'plugins', 'teamspeak', 'discord', 'einstellungen', 'backup', 'logs'] as $section) {
            self::assertCount(1, $view->filterXPath('//*[@id="panel-'.$section.'"]'));
        }
        self::assertStringContainsString('Workspace track', (string) $client->getResponse()->getContent());
        self::assertCount(1, $view->filterXPath('//form[contains(@action,"/queue/clear")]'));
        self::assertCount(1, $view->filterXPath('//a[@href="/musicbots/'.$own->getId().'/radio"]'));
        $view = $client->request('GET', '/musicbots/'.$own->getId().'/radio');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//*[@id="radio-app"]//form[@id="form-radio-add"]'));
        $client->request('GET', '/musicbots/'.$foreign->getId().'/radio');
        self::assertResponseStatusCodeSame(404);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = $em->find(User::class, $customer->getId());
        $limits = $em->getRepository(MusicbotCustomerLimits::class)->findOneBy(['customer' => $owner]) ?? new MusicbotCustomerLimits($owner);
        $limits->setAllowWebradio(false);
        $em->persist($limits);
        $em->flush();
        $client->request('GET', '/musicbots/'.$own->getId().'/radio');
        self::assertResponseStatusCodeSame(403);
        $view = $client->request('GET', '/musicbots/'.$foreign->getId());
        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $view->filterXPath('//*[@id="panel-queue"]'));
        $view = $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $view->filterXPath('//*[contains(@class,"cust-server-card--placeholder")]'));
        $client->request('GET', '/customer/infrastructure/sinusbot');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/admin/musicbots/plugins');
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
    }

    /** @return array{MusicbotInstance, SinusbotNode, SinusbotNode} */
    private function fixtures(User $customer): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $agent = new Agent('music-ui-agent-'.(string) $customer->getId(), [], 'Music UI fixture');
        $bot = new MusicbotInstance($customer, $agent, 'Workspace musicbot', 'workspace-musicbot', '/srv/workspace-musicbot');
        $track = new MusicbotTrack($customer, 'Workspace track', MusicbotTrackSourceType::Upload, 'audio/mpeg', hash('sha256', 'workspace-track'));
        $track->setInstance($bot);
        $multi = new SinusbotNode('Multi fixture', $agent, 'https://agent.invalid', 'fixture', 'https://download.invalid', '/srv/sinusbot', '/srv/sinusbot/instances');
        $multi->setInstanceMode('multi');
        $solo = new SinusbotNode('Solo fixture', $agent, 'https://agent.invalid', 'fixture', 'https://download.invalid', '/srv/sinusbot-solo', '/srv/sinusbot-solo/instances');
        $solo->setInstanceMode('solo');
        $solo->setCustomer($customer);
        foreach ([$agent, $bot, $track, $multi, $solo, new MusicbotQueueItem($bot, $track, 0, $customer)] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$bot, $multi, $solo];
    }
}
