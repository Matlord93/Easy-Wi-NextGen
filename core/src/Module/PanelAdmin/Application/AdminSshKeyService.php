<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
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

        $agent = $this->resolveAgent();
        if ($agent === null) {
            return false;
        }

        $this->jobDispatcher->dispatch($agent, 'admin.ssh_key.store', [
            'user_id' => $admin->getId(),
            'admin_email' => $admin->getEmail(),
            'authorized_keys_path' => $this->authorizedKeysPath,
            'public_key' => $publicKey,
        ]);

        return true;
    }

    private function resolveAgent(): ?\App\Module\Core\Domain\Entity\Agent
    {
        $agents = $this->agentRepository->findBy([], ['lastSeenAt' => 'DESC', 'updatedAt' => 'DESC']);
        $agent = $agents[0] ?? null;
        if (!$agent instanceof \App\Module\Core\Domain\Entity\Agent) {
            return null;
        }

        return $agent;
    }
}
