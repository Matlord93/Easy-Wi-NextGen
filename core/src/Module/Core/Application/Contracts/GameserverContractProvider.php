<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Gameserver\Application\GameserverInstallDirectory;
use App\Module\Gameserver\Application\GameServerInstallPathManager;
use App\Module\Gameserver\Application\InstanceInstallService;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GameserverContractProvider implements ContractResourceProvider, ContractResourceReservation
{
    public function __construct(private EntityManagerInterface $em, private GameserverInstallDirectory $directories, private DiskEnforcementService $disk, private GameServerInstallPathManager $paths, private InstanceInstallService $installer, private ContractJobs $jobs, private \App\Repository\JobRepositoryInterface $gameJobs)
    {
    }

    #[\Override]
    public function kinds(): array
    {
        return [ShopProductKind::Gameserver];
    }

    #[\Override]
    public function provision(ShopContract $contract): void
    {
        $config = $contract->getConfiguration();
        $node = $this->em->find(Agent::class, (string) $config['node_id']);
        $template = $this->em->find(Template::class, (int) $config['template_id']);
        if (null === $node || null === $template) {
            throw new \DomainException('PROVISIONING_TARGET_MISSING');
        }
        if (null !== $this->disk->guardNodeProvisioning($node, new \DateTimeImmutable())) {
            throw new \DomainException('NODE_DISK_CAPACITY');
        }
        $instance = $contract->getOrder()->getInstance();
        if (null === $instance) {
            $instance = new Instance($contract->getCustomer(), $template, $node, (int) $config['cpu_percent'], (int) $config['ram_mib'], (int) $config['disk_mib'], null, InstanceStatus::PendingSetup, InstanceUpdatePolicy::Manual, initialSlots: (int) ($config['game_slots'] ?? Instance::DEFAULT_SLOTS));
            $instance->setInstanceBaseDir($this->directories->resolve($node, (string) ($config['instance_base_dir'] ?? '')));
            $this->em->persist($instance);
            $contract->getOrder()->setInstance($instance);
            $this->em->flush();
        }
        $this->paths->ensureInstallPath($instance);
        $existing = $this->gameJobs->findLatestByTypeAndInstanceId('sniper.install', $instance->getId() ?? 0);
        if (null !== $existing && !in_array($existing->getStatus(), [\App\Module\Core\Domain\Enum\JobStatus::Failed, \App\Module\Core\Domain\Enum\JobStatus::Cancelled], true)) {
            $contract->bind((string) $instance->getId(), $existing->getId(), 'core');

            return;
        }
        $install = $this->installer->prepareInstall($instance);
        if (!$install['ok']) {
            $contract->fail($install['error_code'] ?? 'INSTALL_PRECONDITION');

            return;
        }
        $job = new Job('sniper.install', array_replace($install['payload'] ?? [], ['agent_id' => $node->getId(), 'node_id' => $node->getId(), 'instance_id' => (string) $instance->getId(), 'customer_id' => (string) $contract->getCustomer()->getId(), 'cpu_limit' => (string) $instance->getCpuLimit(), 'ram_limit' => (string) ($install['payload']['ram_limit'] ?? $instance->getRamLimit()), 'disk_limit' => (string) $instance->getDiskLimit(), 'base_dir' => $instance->getInstanceBaseDir(), 'install_path' => $instance->getInstallPath()]));
        $this->em->persist($job);
        $instance->setStatus(InstanceStatus::Provisioning);
        $contract->bind((string) $instance->getId(), $job->getId(), 'core');
    }

    #[\Override]
    public function reserveResource(ShopContract $contract): void
    {
        $config = $contract->getConfiguration();
        $node = $this->em->find(Agent::class, (string) $config['node_id']);
        $template = $this->em->find(Template::class, (int) $config['template_id']);
        if (null === $node || null === $template || null !== $this->disk->guardNodeProvisioning($node, new \DateTimeImmutable())) {
            throw new \DomainException('Gameserver-Ziel oder freier Speicher nicht verfügbar.');
        }
        $instance = new Instance($contract->getCustomer(), $template, $node, (int) $config['cpu_percent'], (int) $config['ram_mib'], (int) $config['disk_mib'], null, InstanceStatus::PendingSetup, InstanceUpdatePolicy::Manual, initialSlots: (int) ($config['game_slots'] ?? Instance::DEFAULT_SLOTS));
        $instance->setInstanceBaseDir($this->directories->resolve($node, (string) ($config['instance_base_dir'] ?? '')));
        $this->em->persist($instance);
        $contract->getOrder()->setInstance($instance);
        $this->em->flush();
        $this->paths->ensureInstallPath($instance);
        $this->installer->reservePorts($instance);
    }

    #[\Override]
    public function readyAt(ShopContract $contract): ?\DateTimeImmutable
    {
        return $this->jobs->readyAt($contract);
    }

    #[\Override]
    public function stop(ShopContract $contract): bool
    {
        $instance = $contract->getOrder()->getInstance();
        if (null === $instance) {
            return true;
        }
        $instance->setStatus(InstanceStatus::Suspended);
        foreach ($this->em->getRepository(\App\Module\Core\Domain\Entity\InstanceSchedule::class)->findBy(['instance' => $instance, 'enabled' => true]) as $schedule) {
            $schedule->update($schedule->getAction(), $schedule->getCronExpression(), $schedule->getTimeZone(), false);
        }
        foreach ($this->em->getRepository(\App\Module\Core\Domain\Entity\BackupDefinition::class)->findBy(['targetType' => \App\Module\Core\Domain\Enum\BackupTargetType::Game, 'targetId' => (string) $instance->getId(), 'customer' => $contract->getCustomer()]) as $definition) {
            $schedule = $definition->getSchedule();
            if (null !== $schedule && $schedule->isEnabled()) {
                $schedule->update($schedule->getCronExpression(), $schedule->getRetentionDays(), $schedule->getRetentionCount(), false, $schedule->getTimeZone(), $schedule->getCompression(), $schedule->isStopBefore());
            }
        }
        if (null === $contract->getJobId()) {
            $latest = $this->gameJobs->findLatestByTypeAndInstanceId('sniper.install', $instance->getId() ?? 0);
            if (null !== $latest) {
                $contract->trackProvisioning($latest->getId(), 'core');
            } else {
                if (null !== $instance->getPortBlockId()) {
                    $block = $this->em->find(\App\Module\Ports\Domain\Entity\PortBlock::class, $instance->getPortBlockId());
                    if (null !== $block && $block->getInstance()?->getId() === $instance->getId()) {
                        $this->em->remove($block);
                    }
                    $instance->setPortBlockId(null);
                }

                return true;
            }
        }
        if (null === $this->jobs->readyAt($contract) && 'PROVISION_FAILED' !== $contract->getError()) {
            return false;
        }
        if (null !== $contract->getStopJobId()) {
            return null !== $this->jobs->readyAt($contract, true);
        }
        $job = new Job('instance.stop', ['agent_id' => $instance->getNode()->getId(), 'node_id' => $instance->getNode()->getId(), 'instance_id' => (string) $instance->getId(), 'customer_id' => (string) $contract->getCustomer()->getId(), 'action' => 'stop']);
        $this->em->persist($job);
        $contract->stoppingJob($job->getId(), 'core');

        return false;
    }
}
