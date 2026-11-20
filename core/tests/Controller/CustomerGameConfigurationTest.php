<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Application\FileServiceClient;
use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\Configuration\StructuredGameConfiguration;
use App\Module\Gameserver\Application\GameServerPathResolver;
use App\Module\Gameserver\Application\MinecraftConfigurationPolicy;
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

final class CustomerGameConfigurationTest extends AbstractWebTestCase
{
    public function testEditsActualFileAndKeepsBookedSlotsAndNetworkFixed(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->setMaxSlots(20);
        $instance->setCurrentSlots(20);
        $instance->setConfigOverride('server.properties', 'old snapshot');
        $instance->getTemplate()->setInstallResolver(['type' => 'minecraft_vanilla']);
        $instance->getNode()->setServiceBaseUrl('https://agent.example.test');
        $instance->setInstallPath('/srv/games/example');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $content = "# keep\nmax-players=20\nserver-port=25565\nserver-ip=203.0.113.1\npvp=true\nmotd=Example\nmanagement-server-port=25585\nmanagement-server-enabled=false\n";
        $writes = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$content, &$writes): MockResponse {
            if ('PUT' === $method) {
                $data = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                if ($data['etag'] !== hash('sha256', $content)) {
                    return new MockResponse('{"error":{"code":"etag_mismatch","message":"conflict"}}', ['http_code' => 409]);
                }
                $content = $data['content'];
                ++$writes;

                return new MockResponse(json_encode(['saved' => true, 'new_etag' => hash('sha256', $content), 'path' => 'server.properties', 'size' => strlen($content)], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['path' => 'server.properties', 'content' => $content, 'encoding' => 'utf-8', 'is_binary' => false, 'size' => strlen($content), 'etag' => hash('sha256', $content)], JSON_THROW_ON_ERROR));
        });
        $controller = $this->controllerFor($http);
        $call = static function (string $method, array $values = [], ?string $etag = null, string $token = 'valid', ?User $actor = null) use ($controller, $instance) {
            $request = Request::create('/configuration'.('GET' === $method ? '?file=server.properties' : ''), $method, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['file' => 'server.properties', 'etag' => $etag, 'values' => $values], JSON_THROW_ON_ERROR));
            $request->attributes->set('current_user', $actor ?? $instance->getCustomer());
            $request->headers->set('X-CSRF-TOKEN', $token);

            return $controller->configuration($request, $instance->getId());
        };
        $response = $call('GET');
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $fields = array_column($data['fields'], null, 'key');
        self::assertTrue($fields['server-port']['readonly']);
        self::assertTrue($fields['server-ip']['readonly']);
        self::assertTrue($fields['management-server-port']['readonly']);
        self::assertTrue($fields['management-server-enabled']['readonly']);
        self::assertSame(20, $fields['max-players']['max']);
        self::assertFalse($fields['max-players']['readonly']);
        foreach ([['max-players' => '21'], ['server-port' => '1234'], ['server-ip' => '0.0.0.0'], ['booked_slots' => '99'], ['management-server-port' => '1234'], ['management-server-enabled' => 'true'], ['pvp' => "false\nserver-port=1234"]] as $values) {
            self::assertSame(422, $call('PUT', $values, $data['etag'])->getStatusCode());
        }
        self::assertSame(403, $call('PUT', ['max-players' => '12'], $data['etag'], 'invalid')->getStatusCode());
        $other = new User('other@example.test', UserType::Customer);
        $other->setId(99999);
        self::assertSame(404, $call('PUT', ['max-players' => '12'], $data['etag'], actor: $other)->getStatusCode());
        self::assertSame(409, $call('PUT', ['max-players' => '12'], 'old-etag')->getStatusCode());
        self::assertSame(0, $writes);
        self::assertSame(200, $call('PUT', ['max-players' => '12', 'pvp' => 'false'], $data['etag'])->getStatusCode());
        self::assertSame(1, $writes);
        self::assertStringContainsString("max-players=12\nserver-port=25565\nserver-ip=203.0.113.1\npvp=false", $content);
        self::assertStringStartsWith('# keep', $content);
        self::assertSame(20, $instance->getMaxSlots());
        self::assertSame(12, $instance->getCurrentSlots());
        self::assertArrayNotHasKey('server.properties', $instance->getConfigOverrides());
        self::assertSame(409, $call('PUT', ['max-players' => '10'], $data['etag'])->getStatusCode());
        self::assertSame(1, $writes);
        $new = json_decode($call('GET')->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, $call('PUT', ['max-players' => '20'], $new['etag'])->getStatusCode());
        self::assertSame(20, $instance->getCurrentSlots());
    }

    public function testCustomerPagesUseOneWorkspaceAndLegacyFilesRedirect(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $id = $instance->getId();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        foreach (['/instances/'.$id.'?tab=setup', '/instances/'.$id.'/files', '/instances/'.$id.'/overview', '/instances/'.$id.'?tab=configs'] as $url) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filterXPath('//*[@data-gameserver-workspace]'));
            self::assertCount(1, $crawler->filterXPath('//main//h1'));
            if (str_contains($url, 'tab=')) {
                self::assertCount(1, $crawler->filterXPath('//*[@id="gameserver-configuration"]'));
                self::assertCount(0, $crawler->filterXPath('//*[@id="gs-settings-content"]'));
            }
        }
        $client->request('GET', '/instances/'.$id.'/files/legacy?path=config');
        self::assertResponseRedirects('/instances/'.$id.'/files?path=config', 303);
    }

    public function testNumericSchemasAcceptDecimalsAndEnforceTheirBounds(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $configuration = self::getContainer()->get(StructuredGameConfiguration::class);
        $file = ['label' => 'settings.json', 'format' => 'json', 'schema' => [
            'rate' => ['type' => 'number', 'min' => 0.25, 'max' => '1.75'],
            'count' => ['type' => 'number', 'min' => '0.5', 'max' => 2.5],
        ]];
        $fields = $configuration->fields($instance, $file, '{"rate":1.0,"count":1}');
        $byKey = array_column($fields, null, 'key');
        self::assertSame('decimal', $byKey['rate']['type']);
        self::assertSame('1.0', $byKey['rate']['value']);
        self::assertSame(0.25, $byKey['rate']['min']);
        self::assertSame(1.75, $byKey['rate']['max']);
        foreach (['0.25', '1.25', '1.75'] as $value) {
            self::assertSame(['rate' => $value], $configuration->validate($fields, ['rate' => $value]));
        }
        foreach (['0.24', '1.76', '1e99', '1e9999', 'NaN', 'INF', 'abc'] as $value) {
            try {
                $configuration->validate($fields, ['rate' => $value]);
                self::fail('Invalid decimal was accepted: '.$value);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $changes = $configuration->validate($fields, ['count' => '1.5']);
        $saved = \App\Module\Gameserver\Application\Configuration\GameConfigDocument::patch('{"rate":1.0,"count":1}', 'json', $changes);
        self::assertSame(1.5, json_decode($saved, true, flags: JSON_THROW_ON_ERROR)['count']);
        self::assertSame(1.0, json_decode($saved, true, flags: JSON_THROW_ON_ERROR)['rate']);
        $integerFields = $configuration->fields($instance, ['label' => 'settings.json', 'format' => 'json', 'schema' => ['count' => ['type' => 'integer', 'min' => 0.25, 'max' => 2.75]]], '{"count":1}');
        self::assertSame(1.0, $integerFields[0]['min']);
        self::assertSame(2.0, $integerFields[0]['max']);
        self::assertSame(['count' => '2'], $configuration->validate($integerFields, ['count' => '2']));
        $inferred = array_column($configuration->fields($instance, ['label' => 'settings.json', 'format' => 'json', 'schema' => []], '{"rate":1.0}'), null, 'key');
        self::assertSame('decimal', $inferred['rate']['type']);
    }

    #[DataProvider('structuredFiles')]
    public function testStructuredFileWritesKeepTypesAndContractLimits(string $path, string $content): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->setMaxSlots(20);
        $instance->setCurrentSlots(20);
        $instance->getTemplate()->setConfigFiles([['path' => $path]]);
        $instance->getNode()->setServiceBaseUrl('https://agent.example.test');
        $instance->setInstallPath('/srv/games/example');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $writes = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$content, &$writes, $path): MockResponse {
            if ('PUT' === $method) {
                $payload = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($path, $payload['path']);
                self::assertSame(hash('sha256', $content), $payload['etag']);
                $content = $payload['content'];
                ++$writes;

                return new MockResponse(json_encode(['saved' => true, 'new_etag' => hash('sha256', $content), 'path' => $path, 'size' => strlen($content)], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['path' => $path, 'content' => $content, 'encoding' => 'utf-8', 'is_binary' => false, 'size' => strlen($content), 'etag' => hash('sha256', $content)], JSON_THROW_ON_ERROR));
        });
        $controller = $this->controllerFor($http);
        $read = Request::create('/configuration?file='.rawurlencode($path));
        $read->attributes->set('current_user', $instance->getCustomer());
        $response = $controller->configuration($read, $instance->getId());
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $fields = array_column($payload['fields'], null, 'key');
        self::assertSame('decimal', $fields['game.rate']['type']);
        self::assertSame('1.0', $fields['game.rate']['value']);
        self::assertTrue($fields['game.port']['readonly']);
        self::assertSame(20, $fields['game.max-players']['max']);
        $write = Request::create('/configuration', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['file' => $path, 'etag' => $payload['etag'], 'values' => ['game.rate' => '1.25', 'game.max-players' => '12', 'alias.enabled' => 'false']], JSON_THROW_ON_ERROR));
        $write->headers->set('X-CSRF-TOKEN', 'valid');
        $write->attributes->set('current_user', $instance->getCustomer());
        self::assertSame(200, $controller->configuration($write, $instance->getId())->getStatusCode());
        self::assertSame(1, $writes);
        self::assertSame(20, $instance->getMaxSlots());
        self::assertSame(12, $instance->getCurrentSlots());
        $data = str_ends_with($path, '.json') ? json_decode($content, false, flags: JSON_THROW_ON_ERROR) : \Symfony\Component\Yaml\Yaml::parse($content, \Symfony\Component\Yaml\Yaml::PARSE_OBJECT_FOR_MAP);
        self::assertSame(1.25, $data->game->rate);
        self::assertSame(12, $data->game->{'max-players'});
        self::assertSame(27015, $data->game->port);
        self::assertSame([], $data->empty_list);
        self::assertInstanceOf(\stdClass::class, $data->empty_map);
        self::assertTrue($data->defaults->enabled);
        self::assertFalse($data->alias->enabled);
    }

    public static function structuredFiles(): iterable
    {
        yield 'json' => ['settings.json', '{"game":{"rate":1.0,"max-players":20,"port":27015},"empty_list":[],"empty_map":{},"defaults":{"enabled":true},"alias":{"enabled":true}}'];
        yield 'yaml' => ['settings.yml', "game:\n  rate: 1.0\n  max-players: 20\n  port: 27015\nempty_list: []\nempty_map: {}\ndefaults: &base\n  enabled: true\nalias: *base\n"];
    }

    public function testProxyListenerAssignmentsAreReadOnlyAndPlayerLimitIsBounded(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->setMaxSlots(20);
        $instance->getTemplate()->setInstallResolver(['type' => 'minecraft_bungeecord']);
        $configuration = self::getContainer()->get(StructuredGameConfiguration::class);
        $before = "player_limit: 20\nlisteners:\n- host: 0.0.0.0:25577\n  max_players: 20\n  query_port: 25578\n  query_enabled: true\nservers:\n  lobby:\n    address: 10.0.0.5:25565\n";
        $definition = ['label' => 'config.yml', 'format' => 'yaml', 'schema' => []];
        $fields = $configuration->fields($instance, $definition, $before);
        $byKey = array_column($fields, null, 'key');
        foreach (['listeners.0.host', 'listeners.0.query_port', 'listeners.0.query_enabled'] as $key) {
            self::assertTrue($byKey[$key]['readonly']);
        }
        self::assertSame(20, $byKey['player_limit']['max']);
        self::assertSame(1, $byKey['player_limit']['min']);
        self::assertFalse($byKey['servers.lobby.address']['readonly']);
        $changes = $configuration->validate($fields, ['player_limit' => '12', 'listeners.0.max_players' => '12']);
        $saved = \App\Module\Gameserver\Application\Configuration\GameConfigDocument::patch($before, 'yaml', $changes);
        MinecraftConfigurationPolicy::assertEditable($instance, 'config.yml', $before, $saved, true);
        $data = \Symfony\Component\Yaml\Yaml::parse($saved);
        self::assertSame(12, $data['player_limit']);
        self::assertSame('0.0.0.0:25577', $data['listeners'][0]['host']);
        foreach (['-1', '21'] as $value) {
            try {
                $configuration->validate($fields, ['player_limit' => $value]);
                self::fail('Proxy limit accepted: '.$value);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testNormalGameSchemaAndRawEditsRespectReservedNetwork(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->setMaxSlots(24);
        $configuration = self::getContainer()->get(StructuredGameConfiguration::class);
        $definition = ['label' => 'Game.ini', 'format' => 'ini', 'schema' => ['Server.MaxPlayers' => ['label' => 'Spielerzahl', 'type' => 'number']]];
        $before = "[Server]\nMaxPlayers=24\nPort=27015\nServerIP=203.0.113.1\nName=Example\n";
        $fields = $configuration->fields($instance, $definition, $before);
        self::assertSame(['Server.MaxPlayers' => '10'], $configuration->validate($fields, ['Server.MaxPlayers' => '10']));
        MinecraftConfigurationPolicy::assertEditable($instance, 'Game.ini', $before, str_replace('Name=Example', 'Name=Changed', $before));
        MinecraftConfigurationPolicy::assertEditable($instance, 'server.properties', "max-players=99\nserver-port=25565", "max-players=12\nserver-port=25565", true);
        $this->expectException(\InvalidArgumentException::class);
        MinecraftConfigurationPolicy::assertEditable($instance, 'Game.ini', $before, str_replace('Port=27015', 'Port=1234', $before));
    }

    public function testContinuedPropertiesCannotChangeReservedValues(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->setMaxSlots(20);
        $instance->setCurrentSlots(20);
        $instance->getTemplate()->setInstallResolver(['type' => 'minecraft_vanilla']);
        $before = "server-port=25\\\n  565\nserver-\\\n ip=203.0.113.10\nmax-players=2\\\n  0\nmotd=Old\n";
        MinecraftConfigurationPolicy::assertEditable($instance, 'server.properties', $before, str_replace('motd=Old', 'motd=New', $before));
        $forbidden = [str_replace('  565', '  566', $before), str_replace('203.0.113.10', '0.0.0.0', $before), str_replace('  0', '  1', $before)];
        foreach ([false, true] as $allowSlots) {
            foreach ($forbidden as $after) {
                try {
                    MinecraftConfigurationPolicy::assertEditable($instance, 'server.properties', $before, $after, $allowSlots);
                    self::fail('Reserved continued value was editable.');
                } catch (\InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
        $limited = str_replace("max-players=2\\\n  0", "max-players=1\\\n  2", $before);
        MinecraftConfigurationPolicy::assertEditable($instance, 'server.properties', $before, $limited, true);
        self::assertTrue(true);
        $plain = "server-port=25565\nserver-ip=203.0.113.10\nmax-players=20\nmotd=Old\n";
        MinecraftConfigurationPolicy::assertEditable($instance, 'server.properties', $before, $plain);
        self::assertTrue(true);
    }

    #[DataProvider('gameProfiles')]
    public function testGameSpecificApiWritesAndProtectsNativeFiles(string $game, string $path, string $content, string $nameKey, string $slotsKey, string $fixedKey): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->setMaxSlots(20);
        $instance->setCurrentSlots(20);
        $instance->getTemplate()->setGameKey($game);
        $instance->getTemplate()->setInstallResolver([]);
        $instance->getTemplate()->setEnvVars([['key' => 'SERVER_NAME', 'value' => 'Old']]);
        $instance->getTemplate()->setConfigFiles([['path' => $path]]);
        $instance->getNode()->setServiceBaseUrl('https://agent.example.test');
        $instance->setInstallPath('/srv/games/example');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $original = $content;
        $writes = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$content, &$writes, $path): MockResponse {
            if ('PUT' === $method) {
                $data = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($path, $data['path']);
                self::assertSame(hash('sha256', $content), $data['etag']);
                $content = $data['content'];
                ++$writes;

                return new MockResponse(json_encode(['saved' => true, 'new_etag' => hash('sha256', $content), 'path' => $path, 'size' => strlen($content)], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['path' => $path, 'content' => $content, 'encoding' => 'utf-8', 'is_binary' => false, 'size' => strlen($content), 'etag' => hash('sha256', $content)], JSON_THROW_ON_ERROR));
        });
        $controller = $this->controllerFor($http);
        $get = Request::create('/configuration?file='.rawurlencode($path));
        $get->attributes->set('current_user', $instance->getCustomer());
        $response = $controller->configuration($get, $instance->getId());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $fields = array_column($data['fields'], null, 'key');
        self::assertTrue($fields[$fixedKey]['readonly'], $fixedKey);
        self::assertSame('enshrouded' === $game ? 16 : 20, $fields[$slotsKey]['max']);
        self::assertNotSame($nameKey, $fields[$nameKey]['label']);
        $put = static function (array $values) use ($instance, $path, &$content, $controller) {
            $request = Request::create('/configuration', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['file' => $path, 'etag' => hash('sha256', $content), 'values' => $values], JSON_THROW_ON_ERROR));
            $request->headers->set('X-CSRF-TOKEN', 'valid');
            $request->attributes->set('current_user', $instance->getCustomer());

            return $controller->configuration($request, $instance->getId());
        };
        self::assertSame(422, $put([$slotsKey => '21'])->getStatusCode());
        self::assertSame(422, $put([$fixedKey => '1'])->getStatusCode());
        self::assertSame(0, $writes);
        $saved = $put([$nameKey => 'New name', $slotsKey => '12']);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getContent());
        self::assertSame(1, $writes);
        self::assertSame(20, $instance->getMaxSlots());
        self::assertSame(12, $instance->getCurrentSlots());
        $format = \App\Module\Gameserver\Application\Configuration\GameSetupProfiles::format($game, $path);
        $beforeValues = \App\Module\Gameserver\Application\Configuration\GameConfigDocument::values($original, $format);
        $afterValues = \App\Module\Gameserver\Application\Configuration\GameConfigDocument::values($content, $format);
        self::assertSame('New name', $afterValues[$nameKey]);
        self::assertSame('12', $afterValues[$slotsKey]);
        self::assertSame($beforeValues[$fixedKey], $afterValues[$fixedKey]);
        $meta = \App\Module\Gameserver\Application\Configuration\GameSetupProfiles::field($game, $path, $nameKey);
        if ('SERVER_NAME' === ($meta['startup_var'] ?? null)) {
            self::assertSame('New name', $instance->getSetupVars()['SERVER_NAME']);
        }
        try {
            $tampered = \App\Module\Gameserver\Application\Configuration\GameConfigDocument::patch($content, $format, [$fixedKey => '1']);
            MinecraftConfigurationPolicy::assertEditable($instance, $path, $content, $tampered);
            self::fail('Native fixed field was editable via raw editor.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public static function gameProfiles(): iterable
    {
        yield 'Palworld' => ['palworld', 'Pal/Saved/Config/LinuxServer/PalWorldSettings.ini', "[/Script/Pal.PalGameWorldSettings]\nOptionSettings=(ServerName=\"Old\",ServerPlayerMaxNum=20,PublicPort=8211)\n", '/Script/Pal.PalGameWorldSettings.OptionSettings.ServerName', '/Script/Pal.PalGameWorldSettings.OptionSettings.ServerPlayerMaxNum', '/Script/Pal.PalGameWorldSettings.OptionSettings.PublicPort'];
        yield '7 Days' => ['seven_days_to_die', 'serverconfig.xml', '<ServerSettings><property name="ServerName" value="Old"/><property name="ServerMaxPlayerCount" value="20"/><property name="ServerPort" value="26900"/></ServerSettings>', 'ServerName', 'ServerMaxPlayerCount', 'ServerPort'];
        foreach (['arma3' => 'server.cfg', 'dayz' => 'serverDZ.cfg'] as $game => $path) {
            yield $game => [$game, $path, "hostname=\"Old\";\nmaxPlayers=20;\nsteamQueryPort=27016;\n", 'hostname', 'maxPlayers', 'steamQueryPort'];
        }
        yield 'Terraria' => ['terraria', 'serverconfig.txt', "worldname=Old\nmaxplayers=20\nport=7777\n", 'worldname', 'maxplayers', 'port'];
        yield 'Zomboid' => ['project_zomboid', 'Zomboid/Server/server.ini', "PublicName=Old\nMaxPlayers=20\nDefaultPort=16261\n", 'PublicName', 'MaxPlayers', 'DefaultPort'];
        yield 'Squad' => ['squad', 'SquadGame/ServerConfig/Server.cfg', "ServerName=\"Old\"\nMaxPlayers=20\nQueryPort=27165\n", 'ServerName', 'MaxPlayers', 'QueryPort'];
        yield 'CS2' => ['cs2', 'game/csgo/cfg/server.cfg', "hostname \"Old\"\nsv_maxplayers 20\nrcon_port 27015\n", 'hostname', 'sv_maxplayers', 'rcon_port'];
        yield 'Rust' => ['rust', 'server/cfg/server.cfg', "server.hostname \"Old\"\nserver.maxplayers 20\nserver.port 28015\n", 'server.hostname', 'server.maxplayers', 'server.port'];
        yield 'FiveM' => ['fivem', 'server.cfg', "sv_hostname \"Old\"\nsv_maxclients 20\nendpoint_add_tcp \"0.0.0.0:30120\"\n", 'sv_hostname', 'sv_maxclients', 'endpoint_add_tcp'];
        yield 'V Rising' => ['v_rising', 'save-data/Settings/ServerHostSettings.json', '{"Name":"Old","MaxConnectedUsers":20,"MaxConnectedAdmins":0,"Port":9876}', 'Name', 'MaxConnectedUsers', 'Port'];
        yield 'Enshrouded' => ['enshrouded', 'enshrouded_server.json', '{"name":"Old","slotCount":20,"gamePort":15636}', 'name', 'slotCount', 'gamePort'];
        yield 'Windrose' => ['windrose', 'R5/ServerDescription.json', '{"ServerName":"Old","MaxPlayerCount":20,"DirectConnectionServerPort":27015}', 'ServerName', 'MaxPlayerCount', 'DirectConnectionServerPort'];
        yield 'Factorio' => ['factorio', 'server-settings.json', '{"name":"Old","max_players":20,"port":34197}', 'name', 'max_players', 'port'];
        foreach (['ark' => 'GameUserSettings.ini', 'conan_exiles' => 'ServerSettings.ini'] as $game => $path) {
            $name = 'ark' === $game ? 'SessionName' : 'ServerName';
            yield $game => [$game, $path, "[ServerSettings]\n$name=\"Old\"\nMaxPlayers=20\nPort=27015\n", 'ServerSettings.'.$name, 'ServerSettings.MaxPlayers', 'ServerSettings.Port'];
        }
    }

    #[DataProvider('startupGames')]
    public function testStartupOnlyGamesExposeAndValidateTheirOwnSettings(string $game, array $invalid, array $valid, string $selector, ?array $templateValidation = null, ?string $operatorType = null): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        foreach ((new \App\Module\Core\Application\GameTemplateSeedCatalog())->listTemplates() as $template) {
            if ($template['game_key'] !== $game) {
                continue;
            }
            $instance->getTemplate()->setGameKey($game);
            $instance->getTemplate()->setConfigFiles($template['config_files']);
            $environment = $template['env_vars'];
            foreach ($environment as &$variable) {
                if (array_key_exists($variable['key'], $invalid) && null !== $templateValidation) {
                    $variable['validation'] = $templateValidation;
                    if (null !== $operatorType) {
                        $variable['type'] = $operatorType;
                    }
                }
            }
            unset($variable);
            $instance->getTemplate()->setEnvVars($environment);
            $instance->getTemplate()->setStartParams($template['start_params']);
        }
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $id = $instance->getId();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        $crawler = $client->request('GET', '/instances/'.$id.'?tab=setup');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath($selector));
        if ('hytale' === $game && '/^authenticated$/D' === ($templateValidation['pattern'] ?? null)) {
            self::assertCount(1, $crawler->filterXPath($selector.'/option'));
            self::assertSame('authenticated', $crawler->filterXPath($selector.'/option')->attr('value'));
        }
        if ('valheim' === $game) {
            self::assertSame('password', $crawler->filterXPath('//input[@name="vars[SERVER_PASSWORD]"]')->attr('type'));
        }
        self::assertCount(0, $crawler->filterXPath('//*[@name="vars[SERVER_MEMORY]"]'));
        $client->request('GET', '/api/v1/customer/instances/'.$id.'/configuration');
        self::assertResponseIsSuccessful();
        $catalog = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $catalog['files']);
        self::assertTrue($catalog['profile']['startup_only']);
        $token = $crawler->filterXPath('//input[@name="_token"]')->last()->attr('value');
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['_token' => $token, 'vars' => $invalid]);
        self::assertResponseStatusCodeSame(400);
        $unchanged = self::getContainer()->get(InstanceRepositoryInterface::class)->find($id);
        self::assertSame([], array_intersect_key($unchanged->getSetupVars(), $invalid));
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['_token' => $token, 'vars' => $valid]);
        self::assertResponseIsSuccessful();
        $saved = self::getContainer()->get(InstanceRepositoryInterface::class)->find($id);
        foreach ($valid as $key => $value) {
            self::assertSame($value, $saved->getSetupVars()[$key]);
        }
    }

    public static function startupGames(): iterable
    {
        yield 'Valheim' => ['valheim', ['SERVER_PASSWORD' => 'tiny'], ['WORLD_NAME' => 'New World', 'SERVER_PASSWORD' => 'long-secret'], '//input[@name="vars[WORLD_NAME]"]'];
        yield 'Hytale' => ['hytale', ['HYTALE_AUTH_MODE' => 'invalid'], ['HYTALE_AUTH_MODE' => 'offline'], '//select[@name="vars[HYTALE_AUTH_MODE]"]'];
        yield 'Valheim with permissive operator rule' => ['valheim', ['SERVER_PASSWORD' => 'tiny'], ['SERVER_PASSWORD' => 'long-secret'], '//input[@name="vars[WORLD_NAME]"]', ['pattern' => '/^.*$/D']];
        yield 'Hytale with permissive operator rule' => ['hytale', ['HYTALE_AUTH_MODE' => 'invalid'], ['HYTALE_AUTH_MODE' => 'offline'], '//select[@name="vars[HYTALE_AUTH_MODE]"]', ['pattern' => '/^.*$/D']];
        yield 'Hytale with stricter operator rule' => ['hytale', ['HYTALE_AUTH_MODE' => 'offline'], ['HYTALE_AUTH_MODE' => 'authenticated'], '//select[@name="vars[HYTALE_AUTH_MODE]"]', ['pattern' => '/^authenticated$/D']];
        yield 'Valheim with numeric operator password' => ['valheim', ['SERVER_PASSWORD' => 'letters-long'], ['SERVER_PASSWORD' => '123456'], '//input[@name="vars[WORLD_NAME]"]', ['pattern' => '/^.*$/D'], 'number'];
    }

    public function testStructuredStartupInputCannotEraseExistingOptionalValue(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->getTemplate()->setGameKey('hytale');
        $instance->getTemplate()->setEnvVars([['key' => 'HYTALE_AUTH_MODE', 'value' => 'authenticated']]);
        $instance->setSetupVars(['HYTALE_AUTH_MODE' => 'offline']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $id = $instance->getId();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['vars' => ['HYTALE_AUTH_MODE' => ['unexpected' => 'array']]]);
        self::assertResponseStatusCodeSame(400);
        $saved = self::getContainer()->get(InstanceRepositoryInterface::class)->find($id);
        self::assertSame('offline', $saved->getSetupVars()['HYTALE_AUTH_MODE']);
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['vars' => ['HYTALE_AUTH_MODE' => '']]);
        self::assertResponseIsSuccessful();
        $cleared = self::getContainer()->get(InstanceRepositoryInterface::class)->find($id);
        self::assertArrayNotHasKey('HYTALE_AUTH_MODE', $cleared->getSetupVars());
    }

    #[DataProvider('adminOnlyScopes')]
    public function testAdminOnlyTemplateValuesStayHiddenAndUneditable(string $scope): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $template = $instance->getTemplate();
        $template->setEnvVars([['key' => 'SERVER_PASSWORD', 'value' => 'synthetic-operator-only-secret']]);
        $requirement = [['key' => 'SERVER_PASSWORD', 'type' => 'password', 'scope' => 'admin_only', 'required' => false]];
        $template->setRequirementVars('vars' === $scope ? $requirement : ('duplicate' === $scope ? array_merge($requirement, [['key' => 'SERVER_PASSWORD', 'type' => 'password', 'scope' => 'customer_allowed']]) : []));
        $template->setRequirementSecrets('secrets' === $scope ? $requirement : []);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $id = $instance->getId();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        $crawler = $client->request('GET', '/instances/'.$id.'?tab=setup');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filterXPath('//*[@name="vars[SERVER_PASSWORD]" or @name="secrets[SERVER_PASSWORD]"]'));
        self::assertStringNotContainsString('synthetic-operator-only-secret', (string) $client->getResponse()->getContent());
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['vars' => ['SERVER_PASSWORD' => 'customer-change']]);
        self::assertResponseIsSuccessful();
        $saved = self::getContainer()->get(InstanceRepositoryInterface::class)->find($id);
        self::assertArrayNotHasKey('SERVER_PASSWORD', $saved->getSetupVars());
    }

    public static function adminOnlyScopes(): iterable
    {
        yield 'admin-only variable' => ['vars'];
        yield 'admin-only secret' => ['secrets'];
        yield 'conflicting duplicate scopes' => ['duplicate'];
    }

    public function testBoundAdminOnlyValuesAreProtectedInTheFileSetup(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $template = $instance->getTemplate();
        $template->setGameKey('cs2');
        $template->setInstallResolver([]);
        $template->setConfigFiles([['path' => 'server.cfg']]);
        $template->setEnvVars([['key' => 'SERVER_NAME', 'value' => 'Old'], ['key' => 'SERVER_PASSWORD', 'value' => 'synthetic-secret'], ['key' => 'MAX_PLAYERS', 'value' => '20']]);
        $template->setRequirementVars([['key' => 'SERVER_NAME', 'scope' => 'admin_only'], ['key' => 'MAX_PLAYERS', 'scope' => 'admin_only']]);
        $template->setRequirementSecrets([['key' => 'SERVER_PASSWORD', 'scope' => 'admin_only']]);
        $instance->getNode()->setServiceBaseUrl('https://agent.example.test');
        $instance->setInstallPath('/srv/games/example');
        $instance->setMaxSlots(20);
        $instance->setCurrentSlots(20);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $content = "hostname \"Old\"\nsv_password \"synthetic-secret\"\nsv_maxplayers 20\nmp_timelimit 10\n";
        $writes = 0;
        $controller = $this->controllerFor(new MockHttpClient(static function (string $method, string $url, array $options) use (&$content, &$writes): MockResponse {
            if ('PUT' === $method) {
                $payload = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(hash('sha256', $content), $payload['etag']);
                $content = $payload['content'];
                ++$writes;

                return new MockResponse(json_encode(['saved' => true, 'new_etag' => hash('sha256', $content), 'path' => 'server.cfg', 'size' => strlen($content)], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['path' => 'server.cfg', 'content' => $content, 'encoding' => 'utf-8', 'is_binary' => false, 'size' => strlen($content), 'etag' => hash('sha256', $content)], JSON_THROW_ON_ERROR));
        }));
        $get = Request::create('/configuration?file=server.cfg');
        $get->attributes->set('current_user', $instance->getCustomer());
        $response = $controller->configuration($get, $instance->getId());
        self::assertSame(200, $response->getStatusCode());
        $fields = array_column(json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['fields'], null, 'key');
        foreach (['hostname', 'sv_password', 'sv_maxplayers'] as $key) {
            self::assertTrue($fields[$key]['readonly']);
        }
        self::assertSame('', $fields['sv_password']['value']);
        foreach ([['hostname' => 'New'], ['sv_password' => 'new-secret'], ['sv_maxplayers' => '12'], ['mp_timelimit' => '5']] as $values) {
            $request = Request::create('/configuration', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['file' => 'server.cfg', 'etag' => hash('sha256', $content), 'values' => $values], JSON_THROW_ON_ERROR));
            $request->headers->set('X-CSRF-TOKEN', 'valid');
            $request->attributes->set('current_user', $instance->getCustomer());
            self::assertSame(isset($values['mp_timelimit']) ? 200 : 422, $controller->configuration($request, $instance->getId())->getStatusCode());
        }
        self::assertSame(1, $writes);
        self::assertSame(20, $instance->getCurrentSlots());
        self::assertSame([], $instance->getSetupVars());
        self::assertSame('5', \App\Module\Gameserver\Application\Configuration\GameConfigDocument::values($content, 'convar')['mp_timelimit']);
        $tampered = \App\Module\Gameserver\Application\Configuration\GameConfigDocument::patch($content, 'convar', ['sv_maxplayers' => '12']);
        $this->expectException(\InvalidArgumentException::class);
        MinecraftConfigurationPolicy::assertEditable($instance, 'server.cfg', $content, $tampered, true);
    }

    private function controllerFor(MockHttpClient $http): CustomerGameConfigurationController
    {
        $encryption = $this->createStub(EncryptionServiceInterface::class);
        $encryption->method('decrypt')->willReturn('synthetic-secret');
        $logger = new NullLogger();
        $paths = new GameServerPathResolver(new InstanceFilesystemResolver($this->createStub(AppSettingsServiceInterface::class)), $logger);
        $files = new FileServiceClient($http, $encryption, 10, 7456, 'https', $logger, new RequestStack(), $paths);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn ($token): bool => 'valid' === $token->getValue());

        return new CustomerGameConfigurationController(self::getContainer()->get(InstanceRepositoryInterface::class), self::getContainer()->get(StructuredGameConfiguration::class), $files, $csrf, self::getContainer()->get(EntityManagerInterface::class));
    }
}
