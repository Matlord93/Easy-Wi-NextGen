<?php

declare(strict_types=1);

namespace App\Tests\HostingPanel;

use App\Module\HostingPanel\Application\Security\AgentTokenAuthenticator;
use App\Module\HostingPanel\Domain\Entity\Agent;
use App\Module\HostingPanel\Domain\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class AgentTokenAuthenticatorTest extends TestCase
{
    public function testAuthenticateAcceptsValidToken(): void
    {
        $node = new Node('n1', 'n1.example.test', '127.0.0.1');
        $agent = new Agent($node, 'agent-1', '1.0.0', 'linux', hash('sha256', 'secret-token'));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($agent);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $auth = new AgentTokenAuthenticator($em);

        self::assertInstanceOf(Agent::class, $auth->authenticate('agent-1', 'secret-token'));
        self::assertNull($auth->authenticate('agent-1', 'invalid'));
    }
}
