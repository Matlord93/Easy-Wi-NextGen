<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Infrastructure\Doctrine\EntityIdentifier;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Nodes\Application\Proxmox\ProxmoxManager;
use App\Module\Nodes\Application\Proxmox\ProxmoxRentalLifecycle;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use App\Module\Nodes\Domain\Proxmox\ProxmoxApiException;
use Doctrine\ORM\EntityManagerInterface;

/** @psalm-import-type RentalResources from ProxmoxTask */
final readonly class ProxmoxContractProvider implements ContractResourceProvider
{
    public function __construct(private EntityManagerInterface $em, private ProxmoxManager $manager, private ProxmoxRentalLifecycle $lifecycle, private ContractJobs $jobs, private RentalCapacity $capacity)
    {
    }

    #[\Override]
    public function kinds(): array
    {
        return [ShopProductKind::Proxmox];
    }

    #[\Override]
    public function provision(ShopContract $contract): void
    {
        $config = $contract->getConfiguration();
        $cluster = $this->cluster($config);
        $contractId = EntityIdentifier::require($contract->getId());
        try {
            $task = $this->manager->queueInstallation($cluster, (int) ($config['proxmox_template_id'] ?? 0), [
                'name' => (string) $config['hostname'], 'username' => 'easywi', 'ssh_keys' => (string) $config['ssh_keys'],
                'address_mode' => (string) ($config['address_mode'] ?? 'dhcp'), 'customer_id' => $contract->getCustomer()->getId(),
            ], substr(hash('sha256', 'rental-proxmox:'.$contractId), 0, 32), null, $contractId, self::resources($config), (string) ($config['proxmox_node'] ?? ''));
        } catch (ProxmoxApiException $error) {
            throw new \DomainException('PROXMOX_TEMPORARILY_UNAVAILABLE', previous: $error);
        }
        $contract->bind((string) EntityIdentifier::require($task->getTargetVmId()), (string) EntityIdentifier::require($task->getId()), 'proxmox');
    }

    /** @param array<string,int|string|bool> $quote */
    public function validateQuote(array $quote): void
    {
        try {
            $this->manager->validateRentalTemplate($this->cluster($quote), (int) ($quote['proxmox_template_id'] ?? 0), (string) ($quote['proxmox_node'] ?? ''), (string) ($quote['address_mode'] ?? 'dhcp'), self::resources($quote));
        } catch (\RuntimeException|\InvalidArgumentException $error) {
            throw new \DomainException('Das Proxmox-Ziel ist derzeit nicht erreichbar oder seine Vorlage passt nicht zum Tarif.', previous: $error);
        }
    }

    /** @param array<string,int|string|bool> $config */
    private function cluster(array $config): ProxmoxCluster
    {
        $cluster = $this->em->find(ProxmoxCluster::class, (int) ($config['proxmox_cluster_id'] ?? 0));
        if (null === $cluster || !$cluster->isEnabled()) {
            throw new \DomainException('PROXMOX_CLUSTER_UNAVAILABLE');
        }

        return $cluster;
    }

    /** @param array<string,int|string|bool> $config
     * @return RentalResources
     */
    private static function resources(array $config): array
    {
        return ['cores' => (int) $config['cpu'], 'memory_mib' => (int) $config['ram_mib'], 'disk_gib' => (int) $config['disk']];
    }

    #[\Override]
    public function readyAt(ShopContract $contract): ?\DateTimeImmutable
    {
        return $this->jobs->readyAt($contract);
    }

    #[\Override]
    public function stop(ShopContract $contract): bool
    {
        if (null === $contract->getJobId()) {
            $this->markWithoutGuest($contract);

            return true;
        }
        $installation = $this->installation($contract);
        $this->lifecycle->revokeAccess($installation);
        if ($this->lifecycle->discardUnsubmitted($installation)) {
            $this->markWithoutGuest($contract);

            return true;
        }
        if ($installation->isActive()) {
            return false;
        }
        if (null !== $contract->getStopJobId()) {
            return null !== $this->jobs->readyAt($contract, true);
        }
        $job = $this->lifecycle->queue($installation, 'rental-stop');
        $contract->stoppingJob((string) EntityIdentifier::require($job->getId()), 'proxmox');

        return false;
    }

    public function installation(ShopContract $contract): ProxmoxTask
    {
        $task = $this->em->find(ProxmoxTask::class, (int) $contract->getJobId());
        if (ShopProductKind::Proxmox !== $contract->getKind() || 'proxmox' !== $contract->getJobSystem() || null === $task
            || ($task->getInstallation()['rental_id'] ?? null) !== $contract->getId() || (string) $task->getTargetVmId() !== $contract->getResourceId()) {
            throw new \DomainException('PROXMOX_INSTALLATION_IDENTITY_UNKNOWN');
        }

        return $task;
    }

    private function markWithoutGuest(ShopContract $contract): void
    {
        $at = $contract->getEndedAt() ?? new \DateTimeImmutable();
        $this->capacity->release($contract, $at);
        $contract->purged($at);
        $contract->backupsPurged($at);
    }
}
