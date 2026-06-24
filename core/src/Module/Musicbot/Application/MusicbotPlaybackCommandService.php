<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotRepeatMode;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotPlaybackCommandService
{
    private const PLAYBACK_ACTIONS = ['play', 'pause', 'resume', 'stop', 'skip', 'volume', 'shuffle', 'repeat'];

    public function __construct(
        private readonly AgentJobDispatcherInterface $jobDispatcher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed> $extraPayload */
    public function dispatchPlaybackAction(User $customer, MusicbotInstance $instance, string $action, array $extraPayload = []): AgentJob
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        $normalizedAction = strtolower(trim($action));
        if (!in_array($normalizedAction, self::PLAYBACK_ACTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported musicbot playback action.');
        }

        return $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.playback.action', array_merge([
            'instance_id' => (string) $instance->getId(),
            'action' => $normalizedAction,
            'service_name' => $instance->getServiceName(),
            'install_path' => $instance->getInstallPath(),
        ], $extraPayload));
    }

    public function prepareSkip(User $customer, MusicbotInstance $instance): AgentJob
    {
        return $this->dispatchPlaybackAction($customer, $instance, 'skip');
    }

    public function storeRepeatMode(User $customer, MusicbotInstance $instance, MusicbotRepeatMode $repeatMode): void
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        $payload = $instance->getRuntimePayload() ?? [];
        $payload['playback'] = array_merge($payload['playback'] ?? [], ['repeat_mode' => $repeatMode->value]);
        $instance->setRuntimePayload($payload);
        $this->entityManager->flush();
    }

    public function storeShuffle(User $customer, MusicbotInstance $instance, bool $shuffle): void
    {
        $this->assertCustomerOwnsInstance($customer, $instance);
        $payload = $instance->getRuntimePayload() ?? [];
        $payload['playback'] = array_merge($payload['playback'] ?? [], ['shuffle' => $shuffle]);
        $instance->setRuntimePayload($payload);
        $this->entityManager->flush();
    }

    private function assertCustomerOwnsInstance(User $customer, MusicbotInstance $instance): void
    {
        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Musicbot instance does not belong to the current customer.');
        }
    }
}
