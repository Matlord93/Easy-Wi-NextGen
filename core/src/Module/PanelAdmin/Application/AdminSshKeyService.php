<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\Application;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
use App\Module\Core\Domain\Entity\User;
use App\Repository\AgentRepository;

final class AdminSshKeyService
{
    public function __construct(
        private readonly string $authorizedKeysPath,
        private readonly AgentRepository $agentRepository,
        private readonly AgentJobDispatcher $jobDispatcher,
    ) {
    }

    public function storeKey(User $admin, string $publicKey): bool
    {
        $publicKey = trim($publicKey);
        if ($publicKey === '') {
            return false;
        }

        $agents = $this->resolveAgents();
        if ($agents === []) {
            return false;
        }

        foreach ($agents as $agent) {
            $this->jobDispatcher->dispatch($agent, 'admin.ssh_key.store', [
                'user_id' => $admin->getId(),
                'admin_email' => $admin->getEmail(),
                'authorized_keys_path' => $this->authorizedKeysPath,
                'public_key' => $publicKey,
            ]);
        }

        return true;
    }

    /**
     * @return \App\Module\Core\Domain\Entity\Agent[]
     */
    private function resolveAgents(): array
    {
        $agents = $this->agentRepository->findBy([], ['lastSeenAt' => 'DESC', 'updatedAt' => 'DESC']);
        if ($agents === []) {
            return [];
        }

        $coreAgents = array_values(array_filter($agents, static function ($agent): bool {
            return $agent instanceof \App\Module\Core\Domain\Entity\Agent
                && in_array('Core', $agent->getRoles(), true);
        }));

        if ($coreAgents !== []) {
            return $coreAgents;
        }

        return array_values(array_filter($agents, static fn ($agent): bool => $agent instanceof \App\Module\Core\Domain\Entity\Agent));
    }
}
