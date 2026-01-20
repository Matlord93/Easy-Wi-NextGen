<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use App\Module\Gameserver\Infrastructure\Repository\GameProfileRepository;
use App\Module\Ports\Application\PortAllocatorService;
use Doctrine\ORM\EntityManagerInterface;

final class InstanceStartService
{
    public function __construct(
        private readonly GameProfileRepository $gameProfileRepository,
        private readonly PortAllocatorService $portAllocatorService,
        private readonly InstanceSlotService $instanceSlotService,
        private readonly InstanceConfigService $instanceConfigService,
        private readonly AgentGameServerClient $agentClient,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function startInstance(Instance $instance): array
    {
        $profile = $this->gameProfileRepository->findOneByGameKey($instance->getTemplate()->getGameKey());
        if ($profile === null) {
            throw new \RuntimeException('Game profile not found for instance.');
        }

        $slots = $this->instanceSlotService->enforceSlots($instance, null);
        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        $allocations = $this->portAllocatorService->allocatePorts($instance, $profile);

        $checks = array_map(static fn ($allocation) => [
            'proto' => $allocation->getProto(),
            'port' => $allocation->getPort(),
        ], $allocations);
        $results = $this->agentClient->checkFreePorts($instance, $checks);
        $now = new \DateTimeImmutable();
        foreach ($allocations as $index => $allocation) {
            $isFree = (bool) ($results[$index]['free'] ?? false);
            $allocation->setLastCheck($now, $isFree);
            if (!$isFree) {
                $this->entityManager->persist($allocation);
                $this->entityManager->flush();
                throw new \RuntimeException('Allocated port is not free on host.');
            }
            $this->entityManager->persist($allocation);
        }
        $this->entityManager->flush();

        $payload = $this->instanceConfigService->buildStartPayload($instance, $profile, $allocations);
        $payload['slots'] = $slots;

        if (!empty($payload['config'])) {
            $renderResult = $this->agentClient->renderConfig($instance, $payload);
            if (!($renderResult['ok'] ?? false)) {
                throw new \RuntimeException('Config rendering failed.');
            }
        }

        $startResult = $this->agentClient->startInstance($instance, $payload);
        if (!($startResult['ok'] ?? false)) {
            throw new \RuntimeException('Instance start failed.');
        }

        return $startResult;
    }
}
