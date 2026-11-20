<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Application\FileServiceClient;
use App\Module\Core\Application\GameTemplateSeedCatalog;
use App\Module\Core\Application\InstanceFilesystemResolver;
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

final class VRisingHostConfigurationTest extends AbstractWebTestCase
{
    #[DataProvider('games')]
    public function testNativeHostSettingsUseConditionalWritesAndProtectTotalCapacity(string $game): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $data = array_column((new GameTemplateSeedCatalog())->listTemplates(), null, 'game_key')[$game];
        $template = $instance->getTemplate();
        $template->setGameKey($game);
        $template->setStartParams($data['start_params']);
        $template->setEnvVars(array_merge($data['env_vars'], [['key' => 'VR_ADDRESS', 'value' => '0.0.0.0'], ['key' => 'VR_RCON_ENABLED', 'value' => 'true'], ['key' => 'VR_MAX_ADMINS', 'value' => '4']]));
        $template->setRequiredPorts($data['required_ports']);
        $template->setConfigFiles($data['config_files']);
        $template->setInstallResolver([]);
        $template->setRequirementVars([]);
        $template->setRequirementSecrets([]);
        $instance->setMaxSlots(20);
        $instance->setCurrentSlots(16);
        $instance->setInstallPath('/srv/games/rising');
        $instance->getNode()->setServiceBaseUrl('https://agent.example.test');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $id = $instance->getId();
        $payload = self::getContainer()->get(InstanceJobPayloadBuilder::class)->buildRuntimePayload($instance);
        self::assertStringContainsString('-gamePort {{PORT_GAME}}', $payload['start_params']);
        self::assertStringContainsString('-queryPort {{PORT_QUERY}}', $payload['start_params']);
        self::assertSame(1, substr_count($payload['start_params'], '-gamePort '));
        $path = 'save-data/Settings/ServerHostSettings.json';
        $content = '{"Name":"Original","MaxConnectedUsers":16,"MaxConnectedAdmins":4,"Port":9876,"QueryPort":9877,"Address":"0.0.0.0","Rcon":{"Enabled":true,"Port":25575,"BindAddress":"127.0.0.1","Password":"operator-secret"},"Custom":[1,2]}';
        $writes = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$content, &$writes): MockResponse {
            if ('PUT' === $method) {
                $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(hash('sha256', $content), $body['etag']);
                $content = $body['content'];
                ++$writes;

                return new MockResponse(json_encode(['saved' => true, 'new_etag' => hash('sha256', $content)], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['content' => $content, 'etag' => hash('sha256', $content), 'is_binary' => false, 'size' => strlen($content)], JSON_THROW_ON_ERROR));
        });
        $controller = $this->controller($http);
        $put = static function (array $values, string $etag = '', string $token = 'valid') use ($controller, $instance, $path, &$content) {
            $request = Request::create('/configuration', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['file' => $path, 'etag' => '' === $etag ? hash('sha256', $content) : $etag, 'values' => $values], JSON_THROW_ON_ERROR));
            $request->attributes->set('current_user', $instance->getCustomer());
            $request->headers->set('X-CSRF-TOKEN', $token);

            return $controller->configuration($request, $instance->getId());
        };
        foreach ([['MaxConnectedUsers' => '20'], ['MaxConnectedAdmins' => '5'], ['ServerFps' => '0'], ['DayOfReset' => 'Never'], ['Address' => '192.0.2.20'], ['Rcon.Enabled' => 'false'], ['AutoSaveSmartKeep' => '10:2']] as $invalid) {
            self::assertSame(422, $put($invalid)->getStatusCode());
        }
        self::assertSame(403, $put(['ServerFps' => '30'], token: 'invalid')->getStatusCode());
        self::assertSame(409, $put(['ServerFps' => '30'], 'stale')->getStatusCode());
        self::assertSame(0, $writes);
        $response = $put(['MaxConnectedUsers' => '12', 'MaxConnectedAdmins' => '8', 'ServerFps' => '30', 'LowerFPSWhenEmpty' => 'true', 'LowerFPSWhenEmptyValue' => '5', 'ResetDaysInterval' => '0', 'DayOfReset' => 'Any', 'GameDifficultyPreset' => 'CustomDifficulty', 'SafeReconnectTime' => '300', 'AutoSaveSmartKeep' => '10:2:1,60:0:1']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(1, $writes);
        $saved = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(12, $saved['MaxConnectedUsers']);
        self::assertSame(8, $saved['MaxConnectedAdmins']);
        self::assertSame(30, $saved['ServerFps']);
        self::assertTrue($saved['LowerFPSWhenEmpty']);
        self::assertSame(5, $saved['LowerFPSWhenEmptyValue']);
        self::assertSame(0, $saved['ResetDaysInterval']);
        self::assertSame([1, 2], $saved['Custom']);
        self::assertSame('operator-secret', $saved['Rcon']['Password']);
        self::assertSame(20, $instance->getMaxSlots());
        self::assertSame(12, $instance->getCurrentSlots());
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        $page = $client->request('GET', '/instances/'.$id.'?tab=setup');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filterXPath('//*[@name="vars[VR_ADDRESS]" or @name="vars[VR_RCON_ENABLED]" or @name="vars[VR_MAX_ADMINS]"]'));
    }

    public function testMissingHostFileRequiresConfirmationAndIsCreatedWithoutOverwriting(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->getTemplate()->setGameKey('v_rising_windows');
        $instance->getTemplate()->setInstallResolver([]);
        $path = 'save-data/Settings/ServerHostSettings.json';
        $instance->getTemplate()->setConfigFiles([['path' => $path]]);
        $instance->setConfigOverride($path, 'old host snapshot');
        $instance->setConfigOverride('other.json', 'keep other snapshot');
        $otherSnapshot = $instance->getConfigOverrides()['other.json'];
        $instance->setMaxSlots(256);
        $instance->setCurrentSlots(256);
        $instance->setLockSlots(true);
        $instance->setInstallPath('/srv/games/rising');
        $instance->getNode()->setServiceBaseUrl('https://agent.example.test');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $content = null;
        $directories = 0;
        $writes = 0;
        $controller = $this->controller(new MockHttpClient(static function (string $method, string $url, array $options) use (&$content, &$directories, &$writes): MockResponse {
            if ('POST' === $method) {
                $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertSame('save-data/Settings', $body['path']);
                ++$directories;

                return new MockResponse('{"success":true}');
            }
            if ('PUT' === $method) {
                $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertTrue($body['create_only']);
                self::assertSame('*', $body['etag']);
                ++$writes;
                if (null !== $content) {
                    return new MockResponse('{"error":{"code":"ETAG_MISMATCH","message":"exists"}}', ['http_code' => 409]);
                }
                $content = $body['content'];

                return new MockResponse(json_encode(['saved' => true, 'new_etag' => hash('sha256', $content)], JSON_THROW_ON_ERROR));
            }

            return new MockResponse('{"error":{"code":"NOT_FOUND","message":"missing"}}', ['http_code' => 404]);
        }));
        $get = Request::create('/configuration?file='.rawurlencode($path));
        $get->attributes->set('current_user', $instance->getCustomer());
        $response = $controller->configuration($get, $instance->getId());
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['can_initialize']);
        self::assertFalse($data['initialize_automatically']);
        self::assertSame(0, $directories);
        foreach ([false, true] as $confirmed) {
            $post = Request::create('/configuration', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['file' => $path, 'initialize_confirmed' => $confirmed], JSON_THROW_ON_ERROR));
            $post->attributes->set('current_user', $instance->getCustomer());
            if ($confirmed) {
                self::assertSame(403, $controller->configuration($post, $instance->getId())->getStatusCode());
            }
            $post->headers->set('X-CSRF-TOKEN', 'valid');
            $created = $controller->configuration($post, $instance->getId());
            self::assertSame($confirmed ? 201 : 422, $created->getStatusCode(), (string) $created->getContent());
        }
        self::assertSame(1, $writes);
        self::assertSame(1, $directories);
        self::assertSame(['MaxConnectedUsers' => 128, 'MaxConnectedAdmins' => 0], json_decode($content, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(128, $instance->getCurrentSlots());
        self::assertArrayNotHasKey($path, $instance->getConfigOverrides());
        self::assertSame($otherSnapshot, $instance->getConfigOverrides()['other.json']);
        self::assertSame(256, $instance->getMaxSlots());
        $original = $content;
        self::assertSame(409, $controller->configuration($post, $instance->getId())->getStatusCode());
        self::assertSame($original, $content);
        self::assertSame(2, $writes);
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
        yield 'Windows template' => ['v_rising_windows'];
        yield 'other existing template' => ['v_rising'];
    }
}
