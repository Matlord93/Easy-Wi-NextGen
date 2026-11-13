<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\UI\Controller\Admin\AdminInstanceController;
use App\Module\Ports\Application\PortCheckUnavailable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdminInstancePortFailureTest extends TestCase
{
    public function testPortFailureEscapesUntrustedHtmlAndPreservesServiceUnavailableStatus(): void
    {
        $message = '<script>alert("node")</script> & unavailable';
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')
            ->willThrowException(new PortCheckUnavailable($message));

        $reflection = new \ReflectionClass(AdminInstanceController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('entityManager')->setValue($controller, $entityManager);
        $request = Request::create('/admin/instances', 'POST', ['node_id' => 'node']);
        $request->attributes->set('current_user', new User('admin@example.test', UserType::Admin));

        $response = $controller->create($request);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('&lt;script&gt;alert(&quot;node&quot;)&lt;/script&gt; &amp; unavailable', $response->getContent());
    }
}
