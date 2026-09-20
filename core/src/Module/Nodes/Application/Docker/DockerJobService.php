<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Docker;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Domain\Entity\Agent;

final readonly class DockerJobService
{
    public function __construct(private AgentJobDispatcherInterface $dispatcher) {}

    public function list(Agent $node, string $resource): AgentJob
    {
        if (!in_array($resource, ['container', 'image', 'volume', 'network'], true)) {
            throw new \InvalidArgumentException('Unsupported Docker resource.');
        }
        return $this->dispatcher->dispatch($node, 'docker.'.$resource.'.list', []);
    }

    public function containerAction(Agent $node, string $name, string $action): AgentJob
    {
        if (!in_array($action, ['start', 'stop', 'restart', 'pause', 'unpause', 'remove'], true)) {
            throw new \InvalidArgumentException('Unsupported Docker container action.');
        }
        return $this->dispatcher->dispatch($node, 'docker.container.action', ['name' => $name, 'action' => $action]);
    }

    public function logs(Agent $node, string $name, int $tail = 200): AgentJob
    {
        if ($tail < 1 || $tail > 5000) {
            throw new \InvalidArgumentException('Docker log tail must be between 1 and 5000.');
        }
        return $this->dispatcher->dispatch($node, 'docker.container.logs', ['name' => $name, 'tail' => $tail]);
    }

    public function updateContainerImage(Agent $node, string $name): AgentJob
    {
        return $this->dispatcher->dispatch($node, 'docker.container.update', ['name' => $name]);
    }

    public function remove(Agent $node, string $resource, string $name): AgentJob
    {
        if (!in_array($resource, ['image', 'volume', 'network'], true)) {
            throw new \InvalidArgumentException('Unsupported removable Docker resource.');
        }
        return $this->dispatcher->dispatch($node, 'docker.'.$resource.'.remove', ['name' => $name]);
    }

    public function composeAction(Agent $node, string $project, string $action): AgentJob
    {
        if (!in_array($action, ['up', 'down', 'start', 'stop', 'restart', 'pull', 'update'], true)) {
            throw new \InvalidArgumentException('Unsupported Docker Compose action.');
        }
        return $this->dispatcher->dispatch($node, 'docker.compose.action', ['project' => $project, 'action' => $action]);
    }

    public function backupVolume(Agent $node, string $volume): AgentJob
    {
        return $this->dispatcher->dispatch($node, 'docker.volume.backup', ['name' => $volume]);
    }

    public function restoreVolume(Agent $node, string $volume, string $backup): AgentJob
    {
        return $this->dispatcher->dispatch($node, 'docker.volume.restore', ['name' => $volume, 'backup' => $backup]);
    }
}
