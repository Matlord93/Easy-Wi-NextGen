<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Application\FileServiceClient;
use App\Module\Core\Application\GameTemplateSeedCatalog;
use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Application\Configuration\GameConfigDocument;
use App\Module\Gameserver\Application\Configuration\StructuredGameConfiguration;
use App\Module\Gameserver\Application\GameServerPathResolver;
use App\Module\Gameserver\Application\InstanceJobPayloadBuilder;
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

final class SourceServerSetupTest extends AbstractWebTestCase
{
    private function seedSource(array $data): Instance
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $template = $instance->getTemplate();
        $template->setGameKey($data['game_key']);
        $template->setStartParams($data['start_params']);
        $template->setEnvVars($data['env_vars']);
        $template->setConfigFiles($data['config_files']);
        $template->setInstallResolver([]);
        $template->setRequirementVars([]);
        $template->setRequirementSecrets([]);
        $instance->setMaxSlots(20);
        $instance->setCurrentSlots(20);
        $instance->setInstallPath('/srv/games/source');
        $instance->getNode()->setServiceBaseUrl('https://agent.example.test');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $instance;
    }

    #[DataProvider('sourceTemplates')]
    public function testSourceSettingsReachRuntimeAndNativeCfg(array $data): void
    {
        $instance = $this->seedSource($data);
        $id = $instance->getId();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        $page = $client->request('GET', '/instances/'.$id.'?tab=setup');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filterXPath('//*[@id="source-startup-heading"]'));
        self::assertStringContainsString(str_starts_with($data['game_key'], 'cs2') ? 'Source 2' : 'Source 1', $page->filterXPath('//*[@id="source-startup-heading"]')->text());
        self::assertCount(1, $page->filterXPath('//*[@name="vars[MAP]"]'));
        self::assertCount(1, $page->filterXPath('//*[@name="vars[MAX_PLAYERS]"]'));
        if (str_starts_with($data['game_key'], 'cs2')) {
            self::assertCount(0, $page->filterXPath('//*[@name="vars[TICKRATE]"]'));
        }
        $token = $page->filterXPath('//*[@id="source-startup-heading"]/../..//input[@name="_token"]')->first()->attr('value');
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['vars' => ['MAP' => 'de_nuke']]);
        self::assertResponseStatusCodeSame(403);
        foreach ([['MAP' => 'bad map; quit'], ['MAX_PLAYERS' => '21']] as $invalid) {
            $client->request('POST', '/instances/'.$id.'/setup/vars', ['_token' => $token, 'vars' => $invalid]);
            self::assertResponseStatusCodeSame(400);
        }
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['_token' => $token, 'vars' => ['MAP' => 'workshop/123/custom_map', 'MAX_PLAYERS' => '12']]);
        self::assertResponseIsSuccessful();
        $instance = self::getContainer()->get(InstanceRepositoryInterface::class)->find($id);
        self::assertSame(12, $instance->getCurrentSlots());
        self::assertSame(20, $instance->getMaxSlots());
        $path = $data['config_files'][0]['path'];
        $content = "// keep\nhostname \"Old\"\nsv_password \"\"\nrcon_password \"secret\"\nmp_timelimit 10\nsv_maxplayers 20\nexec extra.cfg\n";
        $counterstrike = str_starts_with($data['game_key'], 'cs2') || str_starts_with($data['game_key'], 'csgo');
        if ($counterstrike) {
            $content .= "game_type 0\ngame_mode 0\n";
        }
        $writes = 0;
        $controller = $this->controller(new MockHttpClient(static function (string $method, string $url, array $options) use (&$content, &$writes, $path): MockResponse {
            if ('PUT' === $method) {
                $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(hash('sha256', $content), $body['etag']);
                $content = $body['content'];
                ++$writes;

                return new MockResponse(json_encode(['saved' => true, 'new_etag' => hash('sha256', $content), 'path' => $path, 'size' => strlen($content)], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['content' => $content, 'etag' => hash('sha256', $content), 'path' => $path, 'is_binary' => false, 'size' => strlen($content)], JSON_THROW_ON_ERROR));
        }));
        $get = Request::create('/configuration?file='.rawurlencode($path));
        $get->attributes->set('current_user', $instance->getCustomer());
        $response = $controller->configuration($get, $id);
        self::assertSame(200, $response->getStatusCode());
        $fields = array_column(json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['fields'], null, 'key');
        self::assertArrayNotHasKey('sv_maxplayers', $fields);
        self::assertArrayNotHasKey('exec', $fields);
        $values = ['hostname' => 'New server', 'sv_password' => 'new-secret', 'mp_timelimit' => '30'];
        if ($counterstrike) {
            $values += ['game_type' => '0', 'game_mode' => '1'];
        }
        $put = Request::create('/configuration', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['file' => $path, 'etag' => hash('sha256', $content), 'values' => $values], JSON_THROW_ON_ERROR));
        $put->attributes->set('current_user', $instance->getCustomer());
        $put->headers->set('X-CSRF-TOKEN', 'valid');
        $saved = $controller->configuration($put, $id);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getContent());
        self::assertSame(1, $writes);
        self::assertSame('New server', GameConfigDocument::values($content, 'convar')['hostname']);
        self::assertStringContainsString('exec extra.cfg', $content);
        $payload = self::getContainer()->get(InstanceJobPayloadBuilder::class)->buildRuntimePayload($instance);
        $environment = array_column($payload['env_vars'], 'value', 'key');
        self::assertSame('12', $environment['MAX_PLAYERS']);
        self::assertSame('workshop/123/custom_map', $environment['MAP']);
        self::assertSame('New server', $environment['SERVER_NAME']);
        self::assertSame('new-secret', $environment['SERVER_PASSWORD']);
        self::assertStringContainsString('+map {{MAP}}', $payload['start_params']);
        self::assertStringContainsString('+exec server.cfg', $payload['start_params']);
        self::assertStringContainsString('+ip 0.0.0.0', $payload['start_params']);
        self::assertStringNotContainsString('-authkey', $payload['start_params']);
        self::assertStringNotContainsString('+host_workshop_map', $payload['start_params']);
        $slotFlag = str_starts_with($data['game_key'], 'cs2') ? '-maxplayers' : (str_starts_with($data['game_key'], 'csgo') ? '-maxplayers_override' : '+maxplayers');
        self::assertStringContainsString($slotFlag.' {{MAX_PLAYERS}}', $payload['start_params']);
        if ($counterstrike) {
            self::assertSame('1', $environment['GAME_MODE']);
            self::assertStringContainsString('+game_mode {{GAME_MODE}}', $payload['start_params']);
        }
        if (str_starts_with($data['game_key'], 'cs2')) {
            self::assertStringNotContainsString('-tickrate', $payload['start_params']);
        }
    }

    #[DataProvider('steamSecretKeys')]
    public function testEncryptedSteamCredentialsKeepNativeAndOperatorValidation(string $key): void
    {
        $data = iterator_to_array(self::sourceTemplates())['cs2'][0];
        $instance = $this->seedSource($data);
        $instance->getTemplate()->setRequirementSecrets([['key' => $key, 'type' => 'password', 'scope' => 'customer_allowed', 'validation' => ['pattern' => '/^a/']]]);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $id = $instance->getId();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->disableReboot();
        $encryption = new \App\Module\Core\Application\EncryptionService('test', 'test:'.base64_encode(str_repeat('k', 32)));
        self::getContainer()->set(EncryptionServiceInterface::class, $encryption);
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        $page = $client->request('GET', '/instances/'.$id.'?tab=setup');
        self::assertResponseIsSuccessful();
        $token = $page->filterXPath('//*[@id="source-startup-heading"]/../..//input[@name="_token"]')->first()->attr('value');
        $client->request('POST', '/instances/'.$id.'/setup/secrets', ['secrets' => [$key => str_repeat('a', 32)]]);
        self::assertResponseStatusCodeSame(403);
        foreach (['a; quit', str_repeat('b', 32)] as $invalid) {
            $client->request('POST', '/instances/'.$id.'/setup/secrets', ['_token' => $token, 'secrets' => [$key => $invalid]]);
            self::assertResponseStatusCodeSame(400);
            $saved = self::getContainer()->get(InstanceRepositoryInterface::class)->find($id);
            self::assertFalse($saved->hasSetupSecret($key));
        }
        $value = str_repeat('a', 32);
        $client->request('POST', '/instances/'.$id.'/setup/secrets', ['_token' => $token, 'secrets' => [$key => $value]]);
        self::assertResponseIsSuccessful();
        $saved = self::getContainer()->get(InstanceRepositoryInterface::class)->find($id);
        $encrypted = $saved->getSetupSecrets()[$key];
        self::assertNotSame($value, $encrypted['ciphertext']);
        self::assertSame($value, $encryption->decrypt($encrypted));
    }

    public static function steamSecretKeys(): iterable
    {
        yield 'Steam login' => ['STEAM_GSLT'];
        yield 'Workshop API' => ['AUTHKEY'];
    }

    #[DataProvider('counterStrikeTemplates')]
    public function testLiteralSteamDefaultsReachTheAgentAndCanBeExplicitlyDisabled(array $data): void
    {
        $instance = $this->seedSource($data);
        $source2 = str_starts_with($data['game_key'], 'cs2');
        $template = $instance->getTemplate();
        $token = str_repeat('a', 32);
        $apiKey = str_repeat('c', 32);
        $template->setStartParams(($source2 ? 'cs2.sh' : 'srcds_run').' +map de_dust2 +sv_setsteamaccount '.$token.' -authkey '.$apiKey.' +host_workshop_collection 123456 +host_workshop_map 234567 +ip 192.0.2.25 +exec server.cfg');
        $template->setEnvVars([['key' => 'STEAM_GSLT', 'value' => ''], ['key' => 'AUTHKEY', 'value' => ''], ['key' => 'WORKSHOP_MAP', 'value' => ''], ['key' => 'WORKSHOP_COLLECTION', 'value' => '']]);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $id = $instance->getId();
        $payload = self::getContainer()->get(InstanceJobPayloadBuilder::class)->buildRuntimePayload($instance);
        $environment = array_column($payload['env_vars'], 'value', 'key');
        self::assertSame($token, $environment['STEAM_GSLT']);
        self::assertSame($apiKey, $environment['AUTHKEY']);
        self::assertSame('234567', $environment['WORKSHOP_MAP']);
        self::assertSame('123456', $environment['WORKSHOP_COLLECTION']);
        self::assertStringContainsString('+ip 192.0.2.25', $payload['start_params']);
        self::assertStringNotContainsString('+ip 0.0.0.0', $payload['start_params']);
        self::assertSame(1, substr_count($payload['start_params'], '+exec server.cfg'));
        self::assertStringContainsString(($source2 ? '+host_workshop_map' : '+workshop_start_map').' {{WORKSHOP_MAP}}', $payload['start_params']);
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        $page = $client->request('GET', '/instances/'.$id.'?tab=setup');
        self::assertResponseIsSuccessful();
        $csrf = $page->filterXPath('//*[@id="source-startup-heading"]/../..//input[@name="_token"]')->first()->attr('value');
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['_token' => $csrf, 'vars' => ['STEAM_GSLT' => str_repeat('b', 32)]]);
        self::assertResponseIsSuccessful();
        $saved = self::getContainer()->get(InstanceRepositoryInterface::class)->find($id);
        self::assertSame(str_repeat('b', 32), $saved->getGslKey());
        $payload = self::getContainer()->get(InstanceJobPayloadBuilder::class)->buildRuntimePayload($saved);
        self::assertSame(str_repeat('b', 32), array_column($payload['env_vars'], 'value', 'key')['STEAM_GSLT']);
        $client->request('POST', '/instances/'.$id.'/setup/vars', ['_token' => $csrf, 'vars' => ['STEAM_GSLT' => '', 'AUTHKEY' => '', 'WORKSHOP_COLLECTION' => '', 'WORKSHOP_MAP' => '']]);
        self::assertResponseIsSuccessful();
        $saved = self::getContainer()->get(InstanceRepositoryInterface::class)->find($id);
        self::assertNull($saved->getGslKey());
        $payload = self::getContainer()->get(InstanceJobPayloadBuilder::class)->buildRuntimePayload($saved);
        self::assertStringContainsString('+map {{MAP}}', $payload['start_params']);
        foreach (['+sv_setsteamaccount', '-authkey', '+host_workshop_collection', '+host_workshop_map', '+workshop_start_map'] as $argument) {
            self::assertStringNotContainsString($argument, $payload['start_params']);
        }
        self::assertStringContainsString('+ip 192.0.2.25', $payload['start_params']);
        self::assertSame(20, $saved->getMaxSlots());
        $environment = array_column($payload['env_vars'], 'value', 'key');
        self::assertSame('', $environment['STEAM_GSLT']);
        self::assertSame('', $environment['AUTHKEY']);
        self::assertSame('', $environment['WORKSHOP_MAP']);
        self::assertSame('', $environment['WORKSHOP_COLLECTION']);
    }

    public static function counterStrikeTemplates(): iterable
    {
        foreach (self::sourceTemplates() as $key => $data) {
            if (str_starts_with($key, 'cs2') || str_starts_with($key, 'csgo_legacy')) {
                yield $key => $data;
            }
        }
    }

    public static function sourceTemplates(): iterable
    {
        foreach ((new GameTemplateSeedCatalog())->listTemplates() as $data) {
            if (preg_match('/^(cs2|csgo_legacy|css|tf2|hl2dm|l4d2?|dods|garrys_mod)(_windows)?$/D', $data['game_key'])) {
                yield $data['game_key'] => [$data];
            }
        }
    }

    public function testSourceConfigCanBeInitializedWithoutOverwritingExistingFile(): void
    {
        $data = iterator_to_array(self::sourceTemplates())['cs2'][0];
        $instance = $this->seedSource($data);
        $path = $data['config_files'][0]['path'];
        $content = null;
        $controller = $this->controller(new MockHttpClient(static function (string $method, string $url, array $options) use (&$content, $path): MockResponse {
            if ('PUT' === $method) {
                $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertTrue($body['create_only']);
                self::assertSame('*', $body['etag']);
                self::assertSame($path, $body['path']);
                if (null !== $content) {
                    return new MockResponse('{"error":{"code":"ETAG_MISMATCH","message":"exists"}}', ['http_code' => 409]);
                }
                $content = $body['content'];

                return new MockResponse(json_encode(['saved' => true, 'new_etag' => hash('sha256', $content)], JSON_THROW_ON_ERROR), ['http_code' => 201]);
            }

            return new MockResponse('{"error":{"code":"NOT_FOUND","message":"missing"}}', ['http_code' => 404]);
        }));
        $get = Request::create('/configuration?file='.rawurlencode($path));
        $get->attributes->set('current_user', $instance->getCustomer());
        $response = $controller->configuration($get, $instance->getId());
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['can_initialize']);
        $post = Request::create('/configuration', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['file' => $path], JSON_THROW_ON_ERROR));
        $post->attributes->set('current_user', $instance->getCustomer());
        self::assertSame(403, $controller->configuration($post, $instance->getId())->getStatusCode());
        $post->headers->set('X-CSRF-TOKEN', 'valid');
        self::assertSame(201, $controller->configuration($post, $instance->getId())->getStatusCode());
        $original = $content;
        self::assertStringContainsString('hostname "Easy-Wi CS2"', $content);
        self::assertSame(409, $controller->configuration($post, $instance->getId())->getStatusCode());
        self::assertSame($original, $content);
    }

    private function controller(MockHttpClient $http): CustomerGameConfigurationController
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

    public function testMissingDocumentedOptionsCanBeAddedWithNormalWriteProtection(): void
    {
        $instance = $this->seedSource(iterator_to_array(self::sourceTemplates())['cs2'][0]);
        $path = 'game/csgo/cfg/server.cfg';
        $content = "// preserve\nhostname \"Existing\"\nexec extra.cfg\n";
        $writes = 0;
        $controller = $this->controller(new MockHttpClient(static function (string $method, string $url, array $options) use (&$content, &$writes): MockResponse {
            if ('PUT' === $method) {
                $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(hash('sha256', $content), $body['etag']);
                $content = $body['content'];
                ++$writes;

                return new MockResponse(json_encode(['saved' => true, 'new_etag' => hash('sha256', $content)], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['content' => $content, 'etag' => hash('sha256', $content), 'is_binary' => false], JSON_THROW_ON_ERROR));
        }));
        $request = Request::create('/configuration?file='.rawurlencode($path));
        $request->attributes->set('current_user', $instance->getCustomer());
        $data = json_decode($controller->configuration($request, $instance->getId())->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertContains('mp_roundtime', array_column($data['available_fields'], 'key'));
        self::assertNotContains('mp_roundtime', array_column($data['fields'], 'key'));
        self::assertSame(0, $writes);
        $put = static function (array $values, string $etag) use ($controller, $instance, $path) {
            $request = Request::create('/configuration', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['file' => $path, 'etag' => $etag, 'values' => $values], JSON_THROW_ON_ERROR));
            $request->headers->set('X-CSRF-TOKEN', 'valid');
            $request->attributes->set('current_user', $instance->getCustomer());

            return $controller->configuration($request, $instance->getId());
        };
        foreach (['exec', 'ip', 'sv_maxplayers', 'sbox_maxprops'] as $forged) {
            self::assertSame(422, $put([$forged => '1'], $data['etag'])->getStatusCode());
        }
        self::assertSame(409, $put(['mp_roundtime' => '3.5'], 'stale')->getStatusCode());
        self::assertSame(0, $writes);
        self::assertSame(200, $put(['mp_roundtime' => '3.5'], $data['etag'])->getStatusCode());
        self::assertSame('3.5', GameConfigDocument::values($content, 'convar')['mp_roundtime']);
        self::assertStringContainsString('exec extra.cfg', $content);
        self::assertSame(1, $writes);
    }
}
