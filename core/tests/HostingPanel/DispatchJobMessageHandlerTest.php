<?php

declare(strict_types=1);

namespace App\Tests\HostingPanel;

use App\Module\HostingPanel\Application\Job\DispatchJobMessage;
use App\Module\HostingPanel\Application\Job\DispatchJobMessageHandler;
use App\Module\HostingPanel\Domain\Entity\Job;
use App\Module\HostingPanel\Domain\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class DispatchJobMessageHandlerTest extends TestCase
{
    public function testIdempotencySkipsDuplicate(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(new Job(new Node('n', 'n.example', '127.0.0.1'), 'backup', 'idem-1', []));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(new Node('n', 'n.example', '127.0.0.1'));
        $em->method('getRepository')->willReturn($repo);
        $em->expects(self::never())->method('persist');

        $handler = new DispatchJobMessageHandler($em);
        $handler(new DispatchJobMessage(1, 'backup', 'idem-1', []));
    }
}
