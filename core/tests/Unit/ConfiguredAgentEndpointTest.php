<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\Http\ConfiguredAgentEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Module\PanelAdmin\UI\Controller\Admin\AdminNodeController;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ConfiguredAgentEndpointTest extends TestCase
{
    public function testPrivateNodesAndIpv6AreIntentionalAdminTargets(): void
    {
        self::assertSame('tcp://192.168.178.42:7456', ConfiguredAgentEndpoint::socketAddress('http://192.168.178.42:7456'));
        self::assertSame('ssl://[fd00::42]:443', ConfiguredAgentEndpoint::socketAddress('https://[fd00::42]'));
        self::assertSame('ssl://node.example.test:443', ConfiguredAgentEndpoint::socketAddress('HTTPS://node.example.test'));
    }

    #[DataProvider('unsafeEndpoints')]
    public function testRejectsNonHttpTransportAndAmbiguousEndpoints(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConfiguredAgentEndpoint::socketAddress($url);
    }

    public static function unsafeEndpoints(): iterable
    {
        foreach (['file:///etc/passwd', 'gopher://localhost:70', 'tcp://127.0.0.1:22', '//localhost', 'https://user:password@node.test', 'https://node.test?token=secret', 'https://node.test/#fragment', "https://node.test\r\nHost: localhost", 'http://node.test:0', 'http://node.test:65536', 'http://node.test%2f@127.0.0.1', 'http://[::1]evil:80'] as $url) {
            yield [$url];
        }
    }

    public function testCsrfIsCheckedBeforeNodeLookupOrConnectionAttempts(): void
    {
        $controller = (new \ReflectionClass(AdminNodeController::class))->newInstanceWithoutConstructor();
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->expects(self::once())->method('isTokenValid')->willReturn(false);
        (new \ReflectionProperty(AdminNodeController::class, 'csrf'))->setValue($controller, $csrf);
        $request = Request::create('/', 'POST', ['agent_base_url' => 'http://127.0.0.1:22', '_token' => 'invalid']);
        $request->attributes->set('current_user', new User('admin@example.test', UserType::Admin));
        self::assertSame(403, $controller->updateAgentSettings($request, 'node')->getStatusCode());
    }

    public function testCustomerCannotReachTheConnectivityProbe(): void
    {
        $controller = (new \ReflectionClass(AdminNodeController::class))->newInstanceWithoutConstructor();
        $request = Request::create('/', 'POST', ['agent_base_url' => 'http://127.0.0.1:22']);
        $request->attributes->set('current_user', new User('customer@example.test', UserType::Customer));
        // The unauthorized branch only needs its translator, never node/network services.
        $translator = $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturn('Forbidden');
        (new \ReflectionProperty(AdminNodeController::class, 'translator'))->setValue($controller, $translator);
        self::assertSame(403, $controller->updateAgentSettings($request, 'node')->getStatusCode());
    }
}
