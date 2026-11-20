<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\Ts3Node;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\Ts6Node;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Voice\Application\Ts3\Ts3VirtualServerService;
use App\Module\Voice\Application\Ts6\Ts6VirtualServerService;
use Doctrine\ORM\EntityManagerInterface;

final readonly class VoiceContractProvider implements ContractResourceProvider
{
    public function __construct(private EntityManagerInterface $em, private SecretsCryptoInterface $crypto, private AgentJobDispatcher $dispatcher, private Ts3VirtualServerService $ts3, private Ts6VirtualServerService $ts6, private ContractJobs $jobs)
    {
    }

    #[\Override]
    public function kinds(): array
    {
        return [ShopProductKind::Teamspeak3, ShopProductKind::Teamspeak6];
    }

    #[\Override]
    public function provision(ShopContract $contract): void
    {
        $config = $contract->getConfiguration();
        $is6 = ShopProductKind::Teamspeak6 === $contract->getKind();
        $node = $is6 ? $this->em->find(Ts6Node::class, (int) ($config['voice_node_id'] ?? 0)) : $this->em->find(Ts3Node::class, (int) ($config['voice_node_id'] ?? 0));
        if (null === $node) {
            throw new \DomainException('VOICE_NODE_MISSING');
        }
        $name = mb_substr((string) $config['name'].' #'.($contract->getId() ?? 0), 0, 120);
        $slots = (int) ($config['slots'] ?? 32);
        $server = $is6 && $node instanceof Ts6Node ? new Ts6VirtualServer($node, $contract->getCustomer()->getId() ?? 0, 0, $name, $slots) : ($node instanceof Ts3Node ? new Ts3VirtualServer($node, $contract->getCustomer()->getId() ?? 0, 0, $name) : throw new \LogicException('Voice type mismatch.'));
        $server->setStatus('provisioning');
        $this->em->persist($server);
        $this->em->flush();
        $payload = ['virtual_server_id' => $server->getId(), 'node_id' => $node->getId(), 'name' => $name, 'params' => ['slots' => $slots], 'query_bind_ip' => $node->getQueryConnectIp(), 'admin_password' => $node->getAdminPassword($this->crypto)];
        if ($node instanceof Ts3Node) {
            $payload['query_port'] = $node->getQueryPort();
            $payload['admin_username'] = $node->getAdminUsername();
            $payload['params']['filetransfer_port'] = $node->getFiletransferPort();
        } else {
            $payload['query_https_port'] = $node->getQueryHttpsPort();
            $payload['install_dir'] = $node->getInstallPath();
        }
        $job = $this->dispatcher->dispatch($node->getAgent(), $is6 ? 'ts6.virtual.create' : 'ts3.virtual.create', $payload);
        $contract->bind((string) $server->getId(), $job->getId(), 'agent');
    }

    #[\Override]
    public function readyAt(ShopContract $contract): ?\DateTimeImmutable
    {
        return $this->jobs->readyAt($contract);
    }

    #[\Override]
    public function stop(ShopContract $contract): bool
    {
        if (null === $contract->getResourceId()) {
            return true;
        }
        if (null !== $contract->getStopJobId()) {
            return null !== $this->jobs->readyAt($contract, true);
        }
        if (ShopProductKind::Teamspeak6 === $contract->getKind()) {
            $server = $this->em->find(Ts6VirtualServer::class, (int) $contract->getResourceId());
            if (null === $server) {
                return true;
            } $server->archive();
            if ($server->getSid() <= 0) {
                return false;
            } $job = $this->ts6->stop($server);
        } else {
            $server = $this->em->find(Ts3VirtualServer::class, (int) $contract->getResourceId());
            if (null === $server) {
                return true;
            } $server->archive();
            if ($server->getSid() <= 0) {
                return false;
            } $job = $this->ts3->stop($server);
        }
        $contract->stoppingJob($job->getId(), 'agent');

        return false;
    }
}
