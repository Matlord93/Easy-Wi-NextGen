<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Application\FileServiceClient;
use App\Module\Core\Application\GameTemplateSeedCatalog;
use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Gameserver\Application\Configuration\JsonGameConfigDocument;
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

final class VRisingGameConfigurationTest extends AbstractWebTestCase
{
    #[DataProvider('games')]
    public function testGameRulesAreRegisteredCreatedAndSavedWithoutChangingBookedResources(string $game): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $catalog = array_column((new GameTemplateSeedCatalog())->listTemplates(), null, 'game_key')[$game];
        $host = 'save-data/Settings/ServerHostSettings.json';
        $path = 'save-data/Settings/ServerGameSettings.json';
        self::assertContains($path, array_column($catalog['config_files'], 'path'));
        $instance->getTemplate()->setGameKey($game);
        $instance->getTemplate()->setInstallResolver([]);
        // An existing template declaring only the host file also gains the editor.
        $instance->getTemplate()->setConfigFiles([['path' => $host]]);
        $instance->setMaxSlots(20);
        $instance->setCurrentSlots(16);
        $instance->setLockSlots(true);
        $instance->setInstallPath('/srv/games/rising');
        $instance->setConfigOverride($path, 'old game snapshot');
        $instance->setConfigOverride($host, '{"GameSettingsPreset":"DuoPvP"}');
        $hostOverride = $instance->getConfigOverrides()[$host];
        $instance->getNode()->setServiceBaseUrl('https://agent.example.test');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $content = null;
        $writes = 0;
        $directories = 0;
        $controller = $this->controller(new MockHttpClient(static function (string $method, string $url, array $options) use (&$content, &$writes, &$directories, $path): MockResponse {
            if ('POST' === $method) {
                self::assertSame('save-data/Settings', json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR)['path']);
                ++$directories;

                return new MockResponse('{"success":true}');
            }
            if ('PUT' === $method) {
                $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($path, $body['path']);
                if (($body['create_only'] ?? false) && null !== $content) {
                    return new MockResponse('{"error":{"code":"ETAG_MISMATCH","message":"exists"}}', ['http_code' => 409]);
                }
                self::assertSame(null === $content ? '*' : hash('sha256', $content), $body['etag']);
                $content = $body['content'];
                ++$writes;

                return new MockResponse(json_encode(['saved' => true, 'new_etag' => hash('sha256', $content)], JSON_THROW_ON_ERROR));
            }
            if (null === $content) {
                return new MockResponse('{"error":{"code":"NOT_FOUND","message":"missing"}}', ['http_code' => 404]);
            }

            return new MockResponse(json_encode(['content' => $content, 'etag' => hash('sha256', $content), 'is_binary' => false, 'size' => strlen($content)], JSON_THROW_ON_ERROR));
        }));
        $request = static function (string $method, array $input = [], string $token = 'valid') use ($controller, $instance, $path) {
            $request = Request::create('/configuration'.('GET' === $method ? '?file='.rawurlencode($path) : ''), $method, server: ['CONTENT_TYPE' => 'application/json'], content: 'GET' === $method ? null : json_encode(['file' => $path] + $input, JSON_THROW_ON_ERROR));
            $request->attributes->set('current_user', $instance->getCustomer());
            $request->headers->set('X-CSRF-TOKEN', $token);

            return $controller->configuration($request, $instance->getId());
        };
        $response = $request('GET');
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['can_initialize']);
        self::assertFalse($data['initialize_automatically']);
        self::assertSame(0, $writes);
        self::assertSame(0, $directories);
        self::assertSame(422, $request('POST')->getStatusCode());
        self::assertSame(422, $request('POST', ['initialize_confirmed' => 'true'])->getStatusCode());
        self::assertSame(403, $request('POST', ['initialize_confirmed' => true], 'invalid')->getStatusCode());
        self::assertSame(201, $request('POST', ['initialize_confirmed' => true])->getStatusCode());
        self::assertSame("{}\n", $content);
        self::assertSame(1, $writes);
        self::assertSame(1, $directories);
        self::assertArrayNotHasKey($path, $instance->getConfigOverrides());
        self::assertSame($hostOverride, $instance->getConfigOverrides()[$host]);
        self::assertSame(409, $request('POST', ['initialize_confirmed' => true])->getStatusCode());
        self::assertSame("{}\n", $content);
        // Existing numeric enums, JSONC and extension data come from the agent.
        $content = "\xEF\xBB\xBF".'{/* native */"GameModeType":1,"Custom":[{},null],}';
        $response = $request('GET');
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $data['fields']);
        self::assertSame(['0', '1'], $data['fields'][0]['options']);
        self::assertSame('PvE', $data['fields'][0]['option_labels'][0]);
        self::assertGreaterThan(140, count($data['available_fields']));
        self::assertStringContainsString('vorlagen', $data['description']);
        foreach ([['WarEventGameSettings.Interval' => '8'], ['WarEventGameSettings.MajorDuration' => 'Long'], ['WarEventGameSettings.ScalingPlayers4.DropModifier' => '0'], ['CastleStatModifiers_Global.RoutingStationLimit' => '256'], ['EquipmentStatModifiers_Global.MovementSpeedModifier' => '2'], ['ClanSize' => '51'], ['GameModeType' => 'PvE'], ['GameTimeModifiers.BloodMoonFrequency_Min' => '10'], ['PlayerInteractionSettings.VSPlayerWeekdayTime.StartHour' => '24'], ['Port' => '12345']] as $invalid) {
            self::assertSame(422, $request('PUT', ['etag' => hash('sha256', $content), 'values' => $invalid])->getStatusCode());
        }
        self::assertSame(409, $request('PUT', ['etag' => 'stale', 'values' => ['ClanSize' => '8']])->getStatusCode());
        self::assertSame(1, $writes);
        $response = $request('PUT', ['etag' => hash('sha256', $content), 'values' => ['GameModeType' => '0', 'ClanSize' => '8', 'AllowGlobalChat' => 'false', 'GameTimeModifiers.BloodMoonFrequency_Min' => '10', 'GameTimeModifiers.BloodMoonFrequency_Max' => '20', 'PlayerInteractionSettings.VSPlayerWeekdayTime.StartHour' => '0', 'WarEventGameSettings.Interval' => '0', 'WarEventGameSettings.MajorDuration' => '7', 'WarEventGameSettings.MinorDuration' => '5', 'WarEventGameSettings.ScalingPlayers2.PointsModifier' => '2.5', 'WarEventGameSettings.ScalingPlayers4.DropModifier' => '3', 'CastleStatModifiers_Global.RoutingStationLimit' => '16']]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = JsonGameConfigDocument::decode($content);
        self::assertSame(0, $data->GameModeType);
        self::assertSame(8, $data->ClanSize);
        self::assertFalse($data->AllowGlobalChat);
        self::assertSame(10, $data->GameTimeModifiers->BloodMoonFrequency_Min);
        self::assertSame(20, $data->GameTimeModifiers->BloodMoonFrequency_Max);
        self::assertSame(0, $data->PlayerInteractionSettings->VSPlayerWeekdayTime->StartHour);
        self::assertInstanceOf(\stdClass::class, $data->Custom[0]);
        self::assertNull($data->Custom[1]);
        self::assertSame(0, $data->WarEventGameSettings->Interval);
        self::assertSame(7, $data->WarEventGameSettings->MajorDuration);
        self::assertSame(5, $data->WarEventGameSettings->MinorDuration);
        self::assertSame(2.5, $data->WarEventGameSettings->ScalingPlayers2->PointsModifier);
        self::assertSame(3.0, $data->WarEventGameSettings->ScalingPlayers4->DropModifier);
        self::assertSame(16, $data->CastleStatModifiers_Global->RoutingStationLimit);
        self::assertSame(2, $writes);
        self::assertSame(20, $instance->getCurrentSlots());
        self::assertSame(20, $instance->getMaxSlots());
        self::assertTrue($instance->isLockSlots());
        self::assertSame('/srv/games/rising', $instance->getInstallPath());
        self::assertSame($hostOverride, $instance->getConfigOverrides()[$host]);
        self::assertArrayNotHasKey($path, $instance->getConfigOverrides());
    }

    #[DataProvider('games')]
    public function testProgressionListsShareTheFileConflictChecksAndPreserveModData(string $game): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $path = 'save-data/Settings/ServerGameSettings.json';
        $instance->getTemplate()->setGameKey($game);
        $instance->getTemplate()->setInstallResolver([]);
        $instance->getTemplate()->setConfigFiles([['path' => 'save-data/Settings/ServerHostSettings.json']]);
        $instance->setMaxSlots(20);
        $instance->setCurrentSlots(16);
        $instance->setInstallPath('/srv/games/rising');
        $instance->setConfigOverride($path, 'stale panel copy');
        $instance->getNode()->setServiceBaseUrl('https://agent.example.test');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $content = '{"Name":"Keep","VBloodUnitSettings":[{"UnitId":-1905691330,"UnitLevel":20,"DefaultUnlocked":false,"ModData":{"Keep":[1,2]}},{"UnitId":123,"UnitLevel":3,"DefaultUnlocked":true}],"UnlockedResearchs":[123,-495424062],"UnlockedAchievements":[456]}';
        $writes = 0;
        $conflict = false;
        $controller = $this->controller(new MockHttpClient(static function (string $method, string $url, array $options) use (&$content, &$writes, &$conflict): MockResponse {
            if ('PUT' === $method) {
                $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(hash('sha256', $content), $body['etag']);
                if ($conflict) {
                    return new MockResponse('{"error":{"code":"ETAG_MISMATCH","message":"changed"}}', ['http_code' => 409]);
                }
                $content = $body['content'];
                ++$writes;

                return new MockResponse(json_encode(['saved' => true, 'new_etag' => hash('sha256', $content)], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['content' => $content, 'etag' => hash('sha256', $content), 'is_binary' => false, 'size' => strlen($content)], JSON_THROW_ON_ERROR));
        }));
        $get = Request::create('/configuration?file='.rawurlencode($path));
        $get->attributes->set('current_user', $instance->getCustomer());
        $response = $controller->configuration($get, $instance->getId());
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['Name'], array_column($data['fields'], 'key'));
        self::assertCount(3, $data['collections']);
        $request = static function (mixed $changes, array $values = [], string $etag = '', string $token = 'valid') use ($controller, $instance, $path, &$content) {
            $request = Request::create('/configuration', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['file' => $path, 'etag' => '' === $etag ? hash('sha256', $content) : $etag, 'values' => $values, 'list_changes' => $changes], JSON_THROW_ON_ERROR));
            $request->attributes->set('current_user', $instance->getCustomer());
            $request->headers->set('X-CSRF-TOKEN', $token);

            return $controller->configuration($request, $instance->getId());
        };
        $changes = ['VBloodUnitSettings' => ['upsert' => [['UnitId' => -1905691330, 'UnitLevel' => 255, 'DefaultUnlocked' => true]]], 'UnlockedResearchs' => ['add' => [-1292809886], 'remove' => [-495424062]], 'UnlockedAchievements' => ['add' => [-1770927128]]];
        self::assertSame(403, $request($changes, token: 'bad')->getStatusCode());
        self::assertSame(409, $request($changes, etag: 'stale')->getStatusCode());
        self::assertSame(422, $request(null)->getStatusCode());
        self::assertSame(422, $request([], ['UnlockedResearchs.0' => '999'])->getStatusCode());
        self::assertSame(422, $request(['UnlockedResearchs' => ['add' => [999]]])->getStatusCode());
        self::assertSame(422, $request(['VBloodUnitSettings' => ['upsert' => [['UnitId' => -1905691330, 'UnitLevel' => 256, 'DefaultUnlocked' => true]]]])->getStatusCode());
        self::assertSame(0, $writes);
        $conflict = true;
        self::assertSame(409, $request($changes)->getStatusCode());
        self::assertSame(0, $writes);
        self::assertArrayHasKey($path, $instance->getConfigOverrides());
        $conflict = false;
        $response = $request($changes, ['Name' => 'New']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $saved = JsonGameConfigDocument::decode($content);
        self::assertSame('New', $saved->Name);
        self::assertSame([123, -1292809886], $saved->UnlockedResearchs);
        self::assertSame([456, -1770927128], $saved->UnlockedAchievements);
        self::assertSame(255, $saved->VBloodUnitSettings[0]->UnitLevel);
        self::assertTrue($saved->VBloodUnitSettings[0]->DefaultUnlocked);
        self::assertSame([1, 2], $saved->VBloodUnitSettings[0]->ModData->Keep);
        self::assertSame(123, $saved->VBloodUnitSettings[1]->UnitId);
        self::assertSame(1, $writes);
        self::assertSame(16, $instance->getCurrentSlots());
        self::assertSame(20, $instance->getMaxSlots());
        self::assertArrayNotHasKey($path, $instance->getConfigOverrides());
        $responseData = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $groups = array_column($responseData['collections'], null, 'key');
        self::assertTrue(array_column($groups['UnlockedResearchs']['items'], null, 'id')[-1292809886]['selected']);
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
        yield 'Linux template' => ['v_rising'];
        yield 'Windows template' => ['v_rising_windows'];
    }
}
