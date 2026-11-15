<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\AgentOrchestrator\Application\AgentJobResultApplier;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\Ts3Token;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\Ts6Token;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentJobResultApplierTokenTest extends TestCase
{
    public static function versions(): iterable
    {
        yield 'TS3' => [Ts3VirtualServer::class, Ts3Token::class];
        yield 'TS6' => [Ts6VirtualServer::class, Ts6Token::class];
    }

    #[DataProvider('versions')]
    public function testTokenRotationUsesMatchingServerAndRevokesExistingToken(string $serverClass, string $tokenClass): void
    {
        $server = (new \ReflectionClass($serverClass))->newInstanceWithoutConstructor();
        $crypto = new SecretsCrypto('token-rotation-test');
        $old = new $tokenClass($server, $crypto->encrypt('old-token'), 'owner');
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('findOneBy')->with([
            'virtualServer' => $server,
            'active' => true,
        ])->willReturn($old);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())->method('getRepository')->with($tokenClass)->willReturn($repository);
        $manager->expects(self::once())->method('persist')->with(self::callback(
            static fn (object $token): bool => $token instanceof $tokenClass
                && $token->getVirtualServer() === $server
                && $token->getToken($crypto) === 'new-token'
                && $token->getType() === 'owner'
                && $token->isActive(),
        ));
        $applier = (new \ReflectionClass(AgentJobResultApplier::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($applier, 'entityManager'))->setValue($applier, $manager);
        (new \ReflectionProperty($applier, 'crypto'))->setValue($applier, $crypto);

        (new \ReflectionMethod($applier, 'applyVirtualToken'))->invoke($applier, $server, 'new-token', null);

        self::assertFalse($old->isActive());
    }
}
