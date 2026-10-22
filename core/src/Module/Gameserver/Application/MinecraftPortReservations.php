<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Ports\Domain\Entity\PortAllocation;
use Doctrine\ORM\EntityManagerInterface;

final class MinecraftPortReservations
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function reserve(Instance $instance, array $reservations): void
    {
        $repository = $this->em->getRepository(PortAllocation::class);
        foreach (MinecraftPortPlan::withBedrockSignaling($instance->getTemplate(), $reservations) as $reservation) {
            $protocol = (string) $reservation['protocol'];
            $port = (int) $reservation['port'];
            $existing = $repository->findOneBy(['node' => $instance->getNode(), 'proto' => $protocol, 'port' => $port]);
            if ($existing instanceof PortAllocation) {
                if ($existing->getInstance()->getId() !== $instance->getId()) {
                    throw new \RuntimeException('Minecraft port already reserved on this node.');
                }
                continue;
            }
            $this->em->persist(new PortAllocation($instance, $instance->getNode(), (string) $reservation['role'], $protocol, $port, 'block', true));
        }
        // The DB's unique(node_id, proto, port) constraint is the final arbiter;
        // the caller commits reservations and install jobs in one transaction.
    }
}
