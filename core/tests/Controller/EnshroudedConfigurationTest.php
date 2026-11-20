<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Application\FileServiceClient;
use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Gameserver\Application\Configuration\StructuredGameConfiguration;
use App\Module\Gameserver\Application\GameServerPathResolver;
use App\Module\Gameserver\UI\Controller\Customer\CustomerGameConfigurationController;
use App\Repository\InstanceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class EnshroudedConfigurationTest extends AbstractWebTestCase
{
    #[DataProvider('games')]
    public function testSetupUsesMinutesKeepsRoleNamesSeparateAndRequiresExplicitCustomPreset(string $game): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $path = 'enshrouded_server.json';
        $template = $instance->getTemplate();
        $template->setGameKey($game);
        $template->setInstallResolver([]);
        $template->setConfigFiles([['path' => $path]]);
        $template->setEnvVars([['key' => 'SERVER_NAME', 'value' => '']]);
        $instance->setMaxSlots(20);
        $instance->setCurrentSlots(16);
        $instance->setServerName('Original world');
        $instance->setSetupVars(['SERVER_NAME' => 'Original world']);
        $instance->setInstallPath('/srv/games/enshrouded');
        $instance->getNode()->setServiceBaseUrl('https://agent.example.test');
        $instance->setConfigOverride($path, 'old panel copy');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $data = json_decode(file_get_contents(__DIR__.'/../Fixtures/gameserver/enshrouded-official-default.json'), false, flags: JSON_THROW_ON_ERROR);
        $data->name = 'Original world';
        $data->tags = ['BaseBuilding', 'FutureTag', 'English'];
        $data->bannedAccounts = [(object) [
            'displayName' => 'Blocked test account',
            'characterName' => 'The Traveler',
            'banDate' => 1760000000,
            'accountIDHash' => 'test-hash',
            'FutureField' => 'preserve-me',
        ]];
        $data->bans = [(object) ['displayName' => 'Legacy account'], null];
        $data->userGroups[0]->reservedSlots = 5;
        $data->userGroups[0]->ModData = (object) ['Keep' => [1, null]];
        $data->Extra = (object) ['name' => 'Mod name', 'empty' => new \stdClass(), 'list' => []];
        $content = json_encode($data, JSON_THROW_ON_ERROR);
        $writes = 0;
        $controller = $this->controller(new MockHttpClient(static function (string $method, string $url, array $options) use (&$content, &$writes): MockResponse {
            if ('PUT' === $method) {
                $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(hash('sha256', $content), $body['etag']);
                $content = $body['content'];
                ++$writes;

                return new MockResponse(json_encode(['saved' => true, 'new_etag' => hash('sha256', $content)], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['content' => $content, 'etag' => hash('sha256', $content), 'is_binary' => false, 'size' => strlen($content)], JSON_THROW_ON_ERROR));
        }));
        $get = Request::create('/configuration?file='.$path);
        $get->attributes->set('current_user', $instance->getCustomer());
        $response = $controller->configuration($get, $instance->getId());
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $fields = array_column($payload['fields'], null, 'key');
        self::assertArrayNotHasKey('tags.0', $fields);
        self::assertArrayNotHasKey('bannedAccounts.0.accountIDHash', $fields);
        self::assertArrayNotHasKey('bans.0.displayName', $fields);
        $banLists = array_column($payload['ban_lists'], null, 'key');
        self::assertSame([[
            'display_name' => 'Blocked test account',
            'character_name' => 'The Traveler',
            'ban_date' => 1760000000,
        ]], $banLists['bannedAccounts']['entries']);
        self::assertSame('Legacy account', $banLists['bans']['entries'][0]['display_name']);
        self::assertSame(1, $banLists['bans']['unreadable']);
        self::assertStringNotContainsString('test-hash', (string) $response->getContent());
        $tags = $payload['server_tags'];
        self::assertCount(19, array_merge(...array_values($tags['groups'])));
        self::assertSame(1, $tags['unknown_count']);
        self::assertTrue(array_column($tags['groups']['Spielstil'], 'selected', 'key')['BaseBuilding']);
        self::assertSame('30', $fields['gameSettings.dayTimeDuration']['value']);
        self::assertSame('Rolle: Admin', $fields['userGroups.0.name']['category']);
        self::assertSame(16, $fields['slotCount']['max']);
        self::assertSame(['Few', 'Normal', 'Automatic', 'Many', 'Extreme'], $fields['gameSettings.randomSpawnerAmount']['options']);
        $available = array_column($payload['available_fields'], null, 'key');
        self::assertSame(0, $available['gameSettings.flameLevelFogRemoval']['min']);
        self::assertSame(20, $available['gameSettings.flameLevelFogRemoval']['max']);
        $put = static function (array $values, string $etag = '', string $token = 'valid', array $roleChanges = [], mixed $tagChanges = []) use ($controller, $instance, $path, &$content) {
            $request = Request::create('/configuration', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['file' => $path, 'etag' => '' === $etag ? hash('sha256', $content) : $etag, 'values' => $values, 'role_changes' => $roleChanges, 'tag_changes' => $tagChanges], JSON_THROW_ON_ERROR));
            $request->attributes->set('current_user', $instance->getCustomer());
            $request->headers->set('X-CSRF-TOKEN', $token);

            return $controller->configuration($request, $instance->getId());
        };
        foreach ([['gameSettings.playerBodyHeatFactor' => '1.5'], ['gameSettingsPreset' => 'Custom', 'gameSettings.dayTimeDuration' => '1.5'], ['gameSettingsPreset' => 'Custom', 'gameSettings.nightTimeDuration' => '61'], ['slotCount' => '17'], ['userGroups.0.reservedSlots' => '17'], ['slotCount' => '4'], ['ip' => '192.0.2.10'], ['queryPort' => '12345']] as $invalid) {
            self::assertSame(422, $put($invalid)->getStatusCode());
        }
        self::assertSame(422, $put(['gameSettings.randomSpawnerAmount' => 'Automatic'])->getStatusCode());
        self::assertSame(422, $put(['gameSettings.flameLevelFogRemoval' => '11'])->getStatusCode());
        foreach (['-1', '21', '1.5'] as $invalidLevel) {
            self::assertSame(422, $put(['gameSettingsPreset' => 'Custom', 'gameSettings.flameLevelFogRemoval' => $invalidLevel])->getStatusCode());
        }
        self::assertSame(403, $put(['voiceChatMode' => 'Global'], token: 'bad')->getStatusCode());
        self::assertSame(409, $put(['voiceChatMode' => 'Global'], etag: 'stale')->getStatusCode());
        self::assertSame(422, $put(['bannedAccounts.0.displayName' => 'Replacement'])->getStatusCode());
        self::assertSame(0, $writes);
        $response = $put(['userGroups.0.name' => 'Owner']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('Original world', $instance->getServerName());
        self::assertSame('Original world', $instance->getSetupVars()['SERVER_NAME']);
        $saved = json_decode($content, false, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Original world', $saved->name);
        self::assertSame('Owner', $saved->userGroups[0]->name);
        self::assertSame('Default', $saved->gameSettingsPreset);
        $refreshed = array_column(json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['fields'], null, 'key');
        self::assertSame('Rolle: Owner', $refreshed['userGroups.0.name']['category']);
        $response = $put([
            'Extra.name' => 'Changed mod name',
            'gameSettingsPreset' => 'Custom',
            'gameSettings.dayTimeDuration' => '2.5',
            'gameSettings.nightTimeDuration' => '59.75',
            'gameSettings.fromHungerToStarving' => '6.25',
            'gameSettings.playerBodyHeatFactor' => '1.5',
            'gameSettings.perkUpgradeRecyclingFactor' => '0.75',
            'gameSettings.perkCostFactor' => '0.5',
            'gameSettings.experienceExplorationQuestsFactor' => '2',
            'gameSettings.aggroPoolAmount' => 'Many',
            'gameSettings.randomSpawnerAmount' => 'Automatic',
            'gameSettings.flameLevelFogRemoval' => '0',
            'gameSettings.enemyStaminaFactor' => '1.5',
            'gameSettings.enemyPerceptionRangeFactor' => '0.5',
            'gameSettings.threatBonus' => '2',
            'gameSettings.tamingStartleRepercussion' => 'KeepProgress',
            'gameSettings.curseModifier' => 'Easy',
            'voiceChatMode' => 'Global',
            'slotCount' => '12',
            'userGroups.0.reservedSlots' => '8',
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $saved = json_decode($content, false, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Custom', $saved->gameSettingsPreset);
        self::assertSame(150000000000, $saved->gameSettings->dayTimeDuration);
        self::assertSame(3585000000000, $saved->gameSettings->nightTimeDuration);
        self::assertSame(375000000000, $saved->gameSettings->fromHungerToStarving);
        self::assertSame(1.5, $saved->gameSettings->playerBodyHeatFactor);
        self::assertSame(0.75, $saved->gameSettings->perkUpgradeRecyclingFactor);
        self::assertSame('Many', $saved->gameSettings->aggroPoolAmount);
        self::assertSame('Automatic', $saved->gameSettings->randomSpawnerAmount);
        self::assertSame(0, $saved->gameSettings->flameLevelFogRemoval);
        self::assertSame('KeepProgress', $saved->gameSettings->tamingStartleRepercussion);
        self::assertSame('Global', $saved->voiceChatMode);
        self::assertSame(12, $saved->slotCount);
        self::assertSame(8, $saved->userGroups[0]->reservedSlots);
        self::assertSame('Owner', $saved->userGroups[0]->name);
        self::assertSame([1, null], $saved->userGroups[0]->ModData->Keep);
        self::assertInstanceOf(\stdClass::class, $saved->Extra->empty);
        self::assertSame([], $saved->Extra->list);
        self::assertSame('0.0.0.0', $saved->ip);
        self::assertSame(15637, $saved->queryPort);
        self::assertSame('./savegame', $saved->saveDirectory);
        self::assertSame(20, $instance->getMaxSlots());
        self::assertSame(12, $instance->getCurrentSlots());
        self::assertSame('Original world', $instance->getServerName());
        self::assertSame('Original world', $instance->getSetupVars()['SERVER_NAME']);
        self::assertSame('Changed mod name', $saved->Extra->name);
        self::assertSame(2, $writes);
        self::assertArrayNotHasKey($path, $instance->getConfigOverrides());
        $refreshed = array_column(json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['fields'], null, 'key');
        self::assertSame('2.5', $refreshed['gameSettings.dayTimeDuration']['value']);
        self::assertSame(12, $refreshed['userGroups.0.reservedSlots']['max']);
        $manager = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['role_manager'];
        self::assertTrue($manager['editable']);
        self::assertCount(4, $manager['entries']);
        self::assertArrayNotHasKey('password', $manager['entries'][0]);
        $roles = ['remove' => [1], 'add' => [['name' => 'Builders', 'password' => 'synthetic-builder-password', 'canEditBase' => true, 'reservedSlots' => 2]]];
        self::assertSame(403, $put([], token: 'bad', roleChanges: $roles)->getStatusCode());
        self::assertSame(409, $put([], etag: 'stale', roleChanges: $roles)->getStatusCode());
        self::assertSame(422, $put([], roleChanges: ['remove' => [0, 1, 2, 3]])->getStatusCode());
        self::assertSame(422, $put([], roleChanges: ['add' => [['name' => 'Public', 'password' => '']]])->getStatusCode());
        self::assertSame(2, $writes);
        $response = $put(['userGroups.2.name' => 'Guests renamed', 'userGroups.0.password' => 'changed admin access'], roleChanges: $roles);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $saved = json_decode($content, false, flags: JSON_THROW_ON_ERROR);
        self::assertCount(4, $saved->userGroups);
        self::assertSame('Owner', $saved->userGroups[0]->name);
        self::assertSame('changed admin access', $saved->userGroups[0]->password);
        self::assertSame([1, null], $saved->userGroups[0]->ModData->Keep);
        self::assertSame('Guests renamed', $saved->userGroups[1]->name);
        self::assertSame('Builders', $saved->userGroups[3]->name);
        self::assertTrue($saved->userGroups[3]->canEditBase);
        self::assertFalse($saved->userGroups[3]->canKickBan);
        self::assertObjectNotHasProperty('allowPublicAccess', $saved->userGroups[3]);
        self::assertSame('Original world', $instance->getServerName());
        self::assertSame(20, $instance->getMaxSlots());
        self::assertSame(12, $instance->getCurrentSlots());
        $result = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Guests renamed', $result['role_manager']['entries'][1]['name']);
        self::assertSame('Builders', $result['role_manager']['entries'][3]['name']);
        $refreshed = array_column($result['fields'], null, 'key');
        self::assertSame('Rolle: Guests renamed', $refreshed['userGroups.1.name']['category']);
        self::assertSame('Rolle: Builders', $refreshed['userGroups.3.password']['category']);
        self::assertSame(3, $writes);
        foreach ([null, ['UnknownTag' => true], ['German' => 'true'], ['German' => 1], ['German']] as $invalidTags) {
            self::assertSame(422, $put([], tagChanges: $invalidTags)->getStatusCode());
        }
        self::assertSame(422, $put(['tags.1' => 'ChangedUnknownTag'])->getStatusCode());
        $tags = ['BaseBuilding' => false, 'German' => true];
        self::assertSame(403, $put([], token: 'bad', tagChanges: $tags)->getStatusCode());
        self::assertSame(409, $put([], etag: 'stale', tagChanges: $tags)->getStatusCode());
        self::assertSame(3, $writes);
        $response = $put(['voiceChatMode' => 'Proximity', 'gameSettings.flameLevelFogRemoval' => '20'], tagChanges: $tags);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $updated = json_decode($content, false, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['FutureTag', 'English', 'German'], $updated->tags);
        self::assertSame('Proximity', $updated->voiceChatMode);
        self::assertSame(20, $updated->gameSettings->flameLevelFogRemoval);
        self::assertEquals($saved->userGroups, $updated->userGroups);
        self::assertEquals($data->bannedAccounts, $updated->bannedAccounts);
        self::assertEquals($data->bans, $updated->bans);
        self::assertSame($banLists, array_column(json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['ban_lists'], null, 'key'));
        $selected = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['server_tags'];
        self::assertFalse(array_column($selected['groups']['Spielstil'], 'selected', 'key')['BaseBuilding']);
        self::assertTrue(array_column($selected['groups']['Sprachen'], 'selected', 'key')['German']);
    }

    private function controller(MockHttpClient $http): CustomerGameConfigurationController
    {
        $encryption = $this->createStub(EncryptionServiceInterface::class);
        $encryption->method('decrypt')->willReturn('synthetic-agent-secret');
        $logger = new NullLogger();
        $paths = new GameServerPathResolver(new InstanceFilesystemResolver($this->createStub(AppSettingsServiceInterface::class)), $logger);
        $files = new FileServiceClient($http, $encryption, 10, 7456, 'https', $logger, new RequestStack(), $paths);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn ($token): bool => 'valid' === $token->getValue());

        return new CustomerGameConfigurationController(self::getContainer()->get(InstanceRepositoryInterface::class), self::getContainer()->get(StructuredGameConfiguration::class), $files, $csrf, self::getContainer()->get(EntityManagerInterface::class));
    }

    public static function games(): iterable
    {
        yield 'Linux template' => ['enshrouded'];
        yield 'Windows template' => ['enshrouded_windows'];
    }
}
