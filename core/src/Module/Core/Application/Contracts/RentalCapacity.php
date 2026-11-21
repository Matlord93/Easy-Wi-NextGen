<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\RentalCapacityLease;
use App\Module\Core\Domain\Entity\RentalCapacityPool;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Nodes\Application\Kvm\KvmPanelService;
use App\Module\Nodes\Application\Proxmox\ProxmoxIpPools;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @psalm-type Readiness = array{state:string,orderable:bool,label:string,detail:string,target:?string,required:array<string,int>,available:array<string,int>,missing:array<string,int>}
 */
final class RentalCapacity implements \Symfony\Contracts\Service\ResetInterface
{
    private const KVM_POOL_UNAVAILABLE = 'Der ausgewählte KVM-Agent hat für dieses Netz keinen IP-Pool gemeldet oder ist nicht erreichbar. Prüfe den Agent-Stand und rufe den KVM-Bestand erneut ab.';

    public function __construct(private readonly EntityManagerInterface $em, private readonly \App\Module\Core\Application\ModuleRegistry $modules, private readonly KvmPanelService $kvm, private readonly ProxmoxIpPools $proxmoxPools)
    {
    }

    /** @var array<string,array<string,int>> */
    private array $catalog = [];
    /** @var array<string,?RentalCapacityPool> */
    private array $pools = [];
    /** @var array<string, array<string, array<string, bool>>> */
    private array $kvmPools = [];

    #[\Override]
    public function reset(): void
    {
        $this->catalog = [];
        $this->pools = [];
        $this->kvmPools = [];
    }

    /** @param array<string,int|string|bool> $quote */
    public static function target(array $quote): ?string
    {
        $kind = (string) $quote['kind'];
        if ('domain' === $kind) {
            return null;
        }
        if ('proxmox' === $kind) {
            $cluster = (int) ($quote['proxmox_cluster_id'] ?? 0);
            $node = (string) ($quote['proxmox_node'] ?? '');

            return $cluster > 0 && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/D', $node) ? 'proxmox:'.$cluster.':'.$node : null;
        }

        return in_array($kind, ['teamspeak3', 'teamspeak6'], true) ? $kind.':'.(string) ($quote['voice_node_id'] ?? 0) : 'agent:'.(string) $quote['node_id'];
    }

    /** @param array<string,int|string|bool> $quote
     * @return array<string,int>
     */
    private function requirements(array $quote): array
    {
        $kind = (string) $quote['kind'];
        $game = 'gameserver' === $kind;
        $compute = $game || in_array($kind, ['kvm', 'proxmox'], true);

        return ['cpu' => $compute ? (int) $quote['cpu'] : 0, 'ram_mib' => $compute ? (int) ($quote['runtime_ram_mib'] ?? $quote['ram_mib']) : 0, 'disk_mib' => $compute || 'webspace' === $kind ? (int) $quote['disk_mib'] : 0, 'ports' => $game ? max(0, (int) ($quote['port_count'] ?? 0)) : 0, 'ipv4' => 'kvm' === $kind ? 1 : 0, 'slots' => in_array($kind, ['teamspeak3', 'teamspeak6'], true) ? (int) $quote['slots'] : 0, 'systems' => 1];
    }

    /** @return array<string,int> */
    public function available(RentalCapacityPool $pool): array
    {
        $available = $pool->getLimits();
        $used = $this->em->getConnection()->fetchAssociative('SELECT COALESCE(SUM(cpu),0) AS cpu, COALESCE(SUM(ram_mib),0) AS ram_mib, COALESCE(SUM(disk_mib),0) AS disk_mib, COALESCE(SUM(ports),0) AS ports, COALESCE(SUM(ipv4),0) AS ipv4, COALESCE(SUM(slots),0) AS slots, COALESCE(SUM(systems),0) AS systems FROM rental_capacity_leases WHERE pool_id = ? AND released_at IS NULL', [$pool->getId()]);
        if (false === $used) { $used = []; }
        $extraRam = 0;
        foreach ($this->em->getConnection()->iterateAssociative('SELECT l.ram_mib AS reserved_ram, i.ram_limit AS booked_ram, t.install_resolver FROM rental_capacity_leases l JOIN shop_contracts c ON c.id=l.contract_id JOIN shop_orders o ON o.id=c.order_id JOIN instances i ON i.id=o.instance_id JOIN game_templates t ON t.id=i.template_id WHERE l.pool_id=? AND l.released_at IS NULL', [$pool->getId()]) as $row) {
            $resolver = is_string($row['install_resolver']) ? \App\Infrastructure\Config\JsonMap::decode($row['install_resolver']) : \App\Infrastructure\Config\JsonMap::fromValue($row['install_resolver']);
            $type = \App\Infrastructure\Config\JsonMap::strings($resolver)['type'] ?? '';
            $required = \App\Module\Gameserver\Application\GameserverMemoryBudget::runtimeMiB((int) $row['booked_ram'], $type);
            $extraRam += max(0, $required - (int) $row['reserved_ram']);
        }
        $used['ram_mib'] = (int) ($used['ram_mib'] ?? 0) + $extraRam;
        foreach ($available as $key => $limit) {
            $available[$key] = max(0, $limit - (int) ($used[$key] ?? 0));
        }

        return $available;
    }

    /** @param array<string,int|string|bool> $quote */
    public function reserve(ShopContract $contract, array $quote): void
    {
        if ('kvm' === $quote['kind'] && !$this->modules->isEnabled('kvm')) {
            throw new \DomainException('KVM ist derzeit für neue Bestellungen deaktiviert.');
        }
        if ('proxmox' === $quote['kind']) {
            $error = $this->proxmoxOfferError($quote);
            if (null !== $error) {
                throw new \DomainException($error);
            }
        }
        if ('kvm' === $quote['kind'] && 'pool' === ($quote['address_mode'] ?? null)) {
            $node = $contract->getOrder()->getProduct()->getProvisioningNode();
            if (null === $node || !$this->kvm->hasAddressPool($node, (string) ($quote['network_type'] ?? 'network'), (string) ($quote['network'] ?? 'default'))) {
                throw new \DomainException(self::KVM_POOL_UNAVAILABLE);
            }
        }
        $target = self::target($quote);
        if (null === $target) {
            return;
        }
        $pool = $this->em->find(RentalCapacityPool::class, $target, LockMode::PESSIMISTIC_WRITE);
        if (null === $pool || !$pool->isEnabled()) {
            throw new \DomainException('Für dieses Produkt ist derzeit keine Verkaufskapazität freigegeben.');
        }
        $available = $this->available($pool);
        $resources = $this->requirements($quote);
        foreach ($resources as $key => $amount) {
            if ($amount > ($available[$key] ?? 0)) {
                throw new \DomainException('Dieses Produkt ist derzeit ausverkauft. Bitte später erneut versuchen.');
            }
        }
        $this->em->persist(new RentalCapacityLease($pool, $contract, $resources));
    }

    public function adoptExisting(RentalCapacityPool $pool): void
    {
        foreach ($this->em->getConnection()->iterateAssociative("SELECT c.id, c.configuration FROM shop_contracts c LEFT JOIN rental_capacity_leases l ON l.contract_id=c.id WHERE c.purged_at IS NULL AND l.id IS NULL AND (c.status <> 'ended' OR c.job_id IS NOT NULL) ORDER BY c.id") as $row) {
            $raw = is_string($row['configuration']) ? \App\Infrastructure\Config\JsonMap::decode($row['configuration']) : \App\Infrastructure\Config\JsonMap::fromValue($row['configuration']);
            if (null === $raw) {
                throw new \DomainException('Invalid stored contract configuration.');
            }
            $quote = [];
            foreach (array_keys($raw) as $key) {
                if (is_int($raw[$key]) || is_string($raw[$key]) || is_bool($raw[$key])) {
                    $quote[$key] = $raw[$key];
                }
            }
            if (self::target($quote) !== $pool->getId()) {
                continue;
            }
            $resources = $this->requirements($quote);
            $this->em->getConnection()->insert('rental_capacity_leases', ['pool_id' => $pool->getId(), 'contract_id' => (int) $row['id'], 'resources' => json_encode($resources, JSON_THROW_ON_ERROR)] + $resources);
        }
    }

    public function release(ShopContract $contract, \DateTimeImmutable $at): void
    {
        $lease = $this->em->getRepository(RentalCapacityLease::class)->findOneBy(['contract' => $contract, 'releasedAt' => null]);
        if (null !== $lease) {
            $this->em->lock($lease->getPool(), LockMode::PESSIMISTIC_WRITE);
            $lease->release($at);
        }
    }

    public function status(ShopProduct $product): string
    {
        return $this->inspect($product)['label'];
    }

    /**
     * Read-only preview. The authoritative reservation always rechecks under a database lock.
     *
     * @param array<string,int|string|bool>|null $quote selected variant resources, when available
     *
     * @return Readiness
     */
    public function inspect(ShopProduct $product, ?array $quote = null): array
    {
        $kind = $product->getKind();
        $options = $product->getProvisioningOptions();
        $quote ??= array_replace($options, ['kind' => $kind->value, 'node_id' => $product->getProvisioningNode()?->getId() ?? '', 'cpu' => $product->getCpuLimit(), 'ram_mib' => $product->getRamLimit() * 1024, 'runtime_ram_mib' => \App\Module\Gameserver\Application\GameserverMemoryBudget::runtimeMiB($product->getRamLimit() * 1024, ShopProductKind::Gameserver === $product->getKind() ? (string) ($product->getGameTemplate()?->getInstallResolver()['type'] ?? '') : ''), 'disk_mib' => $product->getDiskLimit() * 1024, 'port_count' => count($product->getGameTemplate()?->getRequiredPorts() ?? []), 'slots' => (int) ($options['slots'] ?? 32)]);
        $target = self::target($quote);
        $required = ShopProductKind::Domain === $kind ? [] : $this->requirements($quote);
        $result = ['state' => 'available', 'orderable' => true, 'label' => 'Kapazität verfügbar', 'detail' => 'Das freigegebene Restbudget deckt diesen Tarif. Die endgültige Reservierung erfolgt bei der Bestellung.', 'target' => $target, 'required' => $required, 'available' => [], 'missing' => []];
        if (ShopProductKind::Kvm === $kind && !$this->modules->isEnabled('kvm')) {
            return array_replace($result, ['state' => 'module_disabled', 'orderable' => false, 'label' => 'Derzeit nicht zur Bestellung freigegeben', 'detail' => 'Das KVM-Modul ist deaktiviert.']);
        }
        if (ShopProductKind::Proxmox === $kind && null !== ($error = $this->proxmoxOfferError($quote))) {
            return array_replace($result, ['state' => 'proxmox_unavailable', 'orderable' => false, 'label' => 'Derzeit nicht zur Bestellung freigegeben', 'detail' => $error]);
        }
        if (ShopProductKind::Domain === $kind) {
            return array_replace($result, ['label' => 'Verfügbarkeit wird beim Registrar geprüft', 'detail' => 'Für Domains ist kein Kapazitätspool erforderlich. Registrar, Domainname und feste Laufzeit werden separat geprüft.']);
        }
        if (null === $target || 'agent:' === $target || in_array($target, ['teamspeak3:0', 'teamspeak6:0'], true)) {
            return array_replace($result, ['state' => 'target_missing', 'orderable' => false, 'label' => 'Derzeit nicht zur Bestellung freigegeben', 'detail' => 'Dem Produkt fehlt ein Bereitstellungsziel. Bitte zuerst einen Agenten beziehungsweise Voice-Node zuordnen.', 'target' => null]);
        }
        if (ShopProductKind::Kvm === $kind && 'pool' === ($quote['address_mode'] ?? null)) {
            $node = $product->getProvisioningNode();
            if (null === $node || !$this->observedKvmPool($node, (string) ($quote['network_type'] ?? 'network'), (string) ($quote['network'] ?? 'default'))) {
                return array_replace($result, ['state' => 'address_pool_missing', 'orderable' => false, 'label' => 'Derzeit nicht zur Bestellung freigegeben', 'detail' => self::KVM_POOL_UNAVAILABLE]);
            }
        }
        if (!array_key_exists($target, $this->pools)) {
            $this->pools[$target] = $this->em->find(RentalCapacityPool::class, $target);
        }
        $pool = $this->pools[$target];
        if (null === $pool) {
            return array_replace($result, ['state' => 'pool_missing', 'orderable' => false, 'label' => 'Derzeit nicht zur Bestellung freigegeben', 'detail' => 'Für dieses Ziel wurde noch kein Verkaufskapazitätspool eingerichtet. Produktaktivierung und Shop-Kapazitätsfreigabe sind getrennte Einstellungen.']);
        }
        if (!$pool->isEnabled()) {
            return array_replace($result, ['state' => 'pool_disabled', 'orderable' => false, 'label' => 'Derzeit nicht zur Bestellung freigegeben', 'detail' => 'Der Verkaufskapazitätspool dieses Ziels ist deaktiviert. Bestehende Verträge bleiben erhalten; neue Bestellungen sind gesperrt.']);
        }
        $available = $this->catalog[$target] ??= $this->available($pool);
        $missing = [];
        foreach ($required as $key => $amount) {
            if ($amount > ($available[$key] ?? 0)) {
                $missing[$key] = $amount - ($available[$key] ?? 0);
            }
        }
        $result['available'] = $available;
        $result['missing'] = $missing;
        if ([] !== $missing) {
            return array_replace($result, ['state' => 'exhausted', 'orderable' => false, 'label' => 'Derzeit ausverkauft', 'detail' => 'Das freigegebene Restbudget reicht für diesen Tarif nicht aus. Reservierte und noch aufbewahrte Systeme belegen weiterhin Kapazität.']);
        }

        return $result;
    }

    private function observedKvmPool(Agent $node, string $kind, string $network): bool
    {
        return $this->kvmPools[$node->getId()][$kind][$network] ??= $this->kvm->hasAddressPool($node, $kind, $network);
    }

    /** @param array<string,int|string|bool> $quote */
    private function proxmoxOfferError(array $quote): ?string
    {
        if (!$this->modules->isEnabled('proxmox')) {
            return 'Das Proxmox-Modul ist deaktiviert.';
        }
        $cluster = $this->em->find(ProxmoxCluster::class, (int) ($quote['proxmox_cluster_id'] ?? 0));
        if (null === $cluster || !$cluster->isEnabled() || null === self::target($quote) || (int) ($quote['proxmox_template_id'] ?? 0) < 100) {
            return 'Für diesen Tarif fehlen ein aktiver Proxmox-Cluster, ein Node oder eine VM-Vorlage.';
        }
        if ('pool' === ($quote['address_mode'] ?? null)) {
            foreach ($this->proxmoxPools->pools($cluster, (string) $quote['proxmox_node']) as $pool) {
                if ($pool->isEnabled()) {
                    return null;
                }
            }

            return 'Für das Proxmox-Ziel ist kein fester IP-Pool freigegeben.';
        }

        return null;
    }
}
