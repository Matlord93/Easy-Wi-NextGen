<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\GameTemplateSeedCatalog;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Application\InstanceJobPayloadBuilder;
use App\Repository\InstanceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class ValheimServerSetupTest extends AbstractWebTestCase
{
    private function seedValheim(string $game): Instance
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $data = array_column((new GameTemplateSeedCatalog())->listTemplates(), null, 'game_key')[$game];
        $template = $instance->getTemplate();
        $template->setGameKey($game);
        $template->setStartParams($data['start_params'].' -savedir "world saves" -logFile "server.log"');
        $template->setEnvVars($data['env_vars']);
        $template->setConfigFiles($data['config_files']);
        $template->setInstallResolver([]);
        $template->setRequirementVars([]);
        $template->setRequirementSecrets([]);
        $instance->setMaxSlots(10);
        $instance->setCurrentSlots(10);
        $instance->setInstallPath('/srv/games/valheim');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $instance;
    }

    #[DataProvider('games')]
    public function testSettingsAreSeparateValidatedAndAppliedToRuntime(string $game): void
    {
        $instance = $this->seedValheim($game);
        $id = $instance->getId();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        $page = $client->request('GET', '/instances/'.$id.'?tab=setup');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filterXPath('//*[@id="valheim-startup-heading"]'));
        self::assertCount(0, $page->filterXPath('//*[@id="source-startup-heading"]'));
        self::assertCount(1, $page->filterXPath('//*[@name="vars[WORLD_NAME]"]'));
        self::assertCount(1, $page->filterXPath('//*[@name="vars[SERVER_PASSWORD]"]'));
        self::assertCount(1, $page->filterXPath('//select[@name="vars[VALHEIM_PRESET]"]/option[@value=""]'));
        self::assertCount(0, $page->filterXPath('//*[@name="vars[PORT_GAME]" or @name="vars[MAX_PLAYERS]"]'));
        $csrf = $page->filterXPath('//*[@id="valheim-startup-heading"]/../..//input[@name="_token"]')->first()->attr('value');
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['vars' => ['VALHEIM_RAIDS' => 'none']]);
        self::assertResponseStatusCodeSame(403);
        foreach ([['VALHEIM_PRESET' => 'hard; quit'], ['VALHEIM_SAVE_INTERVAL' => '0'], ['VALHEIM_SAVE_INTERVAL' => '2147483648'], ['VALHEIM_RAIDS' => ['none']], ['VALHEIM_PORTALS' => 'invalid']] as $invalid) {
            $client->request('POST', '/instances/'.$id.'/setup/vars', ['_token' => $csrf, 'vars' => $invalid]);
            self::assertResponseStatusCodeSame(400);
        }
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['_token' => $csrf, 'vars' => ['PORT_GAME' => '9999']]);
        self::assertResponseStatusCodeSame(403);
        $values = ['VALHEIM_PUBLIC' => '0', 'VALHEIM_CROSSPLAY' => '1', 'VALHEIM_SAVE_INTERVAL' => '600', 'VALHEIM_BACKUP_COUNT' => '4', 'VALHEIM_BACKUP_SHORT' => '3600', 'VALHEIM_BACKUP_LONG' => '7200', 'VALHEIM_PRESET' => 'normal', 'VALHEIM_COMBAT' => 'veryhard', 'VALHEIM_RAIDS' => 'none', 'VALHEIM_PORTALS' => 'hard', 'VALHEIM_DEATH_PENALTY' => 'easy', 'VALHEIM_RESOURCES' => 'most'];
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['_token' => $csrf, 'vars' => $values]);
        self::assertResponseIsSuccessful();
        $saved = self::getContainer()->get(InstanceRepositoryInterface::class)->find($id);
        foreach ($values as $key => $value) {
            self::assertSame($value, $saved->getSetupVars()[$key]);
        }
        $payload = self::getContainer()->get(InstanceJobPayloadBuilder::class)->buildRuntimePayload($saved);
        $environment = array_column($payload['env_vars'], 'value', 'key');
        foreach ($values as $key => $value) {
            self::assertSame($value, $environment[$key]);
        }
        self::assertStringContainsString('-crossplay', $payload['start_params']);
        self::assertStringContainsString('-port {{PORT_GAME}}', $payload['start_params']);
        self::assertStringContainsString('-savedir "world saves"', $payload['start_params']);
        self::assertStringContainsString('-logFile "server.log"', $payload['start_params']);
        self::assertLessThan(strpos($payload['start_params'], '-modifier raids'), strpos($payload['start_params'], '-preset '));
        self::assertSame(10, $saved->getMaxSlots());
        self::assertSame(10, $saved->getCurrentSlots());
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['_token' => $csrf, 'vars' => array_fill_keys(array_keys($values), '')]);
        self::assertResponseIsSuccessful();
        $saved = self::getContainer()->get(InstanceRepositoryInterface::class)->find($id);
        $payload = self::getContainer()->get(InstanceJobPayloadBuilder::class)->buildRuntimePayload($saved);
        foreach (['-crossplay', '-public', '-preset', '-modifier', '-saveinterval', '-backups', '-backupshort', '-backuplong'] as $flag) {
            self::assertStringNotContainsString($flag, $payload['start_params']);
        }
        self::assertStringContainsString('-port {{PORT_GAME}}', $payload['start_params']);
        self::assertStringContainsString('-savedir "world saves"', $payload['start_params']);
    }

    public function testOperatorRestrictionsKeepTheirScopeAndValidation(): void
    {
        $instance = $this->seedValheim('valheim');
        $instance->getTemplate()->setRequirementVars([
            ['key' => 'VALHEIM_RAIDS', 'scope' => 'admin_only'],
            ['key' => 'VALHEIM_SAVE_INTERVAL', 'type' => 'text', 'scope' => 'customer_allowed', 'validation' => ['pattern' => '/^(?:60|300)$/D']],
        ]);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $id = $instance->getId();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        $page = $client->request('GET', '/instances/'.$id.'?tab=setup');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filterXPath('//*[@name="vars[VALHEIM_RAIDS]"]'));
        $csrf = $page->filterXPath('//*[@id="valheim-startup-heading"]/../..//input[@name="_token"]')->first()->attr('value');
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['_token' => $csrf, 'vars' => ['VALHEIM_SAVE_INTERVAL' => '600']]);
        self::assertResponseStatusCodeSame(400);
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['_token' => $csrf, 'vars' => ['VALHEIM_SAVE_INTERVAL' => '60', 'VALHEIM_RAIDS' => 'none']]);
        self::assertResponseIsSuccessful();
        $saved = self::getContainer()->get(InstanceRepositoryInterface::class)->find($id);
        self::assertSame('60', $saved->getSetupVars()['VALHEIM_SAVE_INTERVAL']);
        self::assertArrayNotHasKey('VALHEIM_RAIDS', $saved->getSetupVars());
    }

    public static function games(): iterable
    {
        yield 'Linux' => ['valheim'];
        yield 'Windows' => ['valheim_windows'];
    }
}
