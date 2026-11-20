<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Domain\Entity\RentalCapacityLease;
use App\Module\Core\Domain\Entity\RentalCapacityPool;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Enum\ShopProductKind;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class RentalCapacity implements \Symfony\Contracts\Service\ResetInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @var array<string,array<string,int>> */
    private array $catalog = [];
    /** @var array<string,?RentalCapacityPool> */
    private array $pools = [];

    #[\Override]
    public function reset(): void
    {
        $this->catalog = [];
        $this->pools = [];
    }

    /** @param array<string,int|string|bool> $quote */
    public static function target(array $quote): ?string
    {
        $kind = (string) $quote['kind'];
        if ('domain' === $kind) {
            return null;
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
        $compute = $game || 'kvm' === $kind;

        return ['cpu' => $compute ? (int) $quote['cpu'] : 0, 'ram_mib' => $compute ? (int) ($quote['runtime_ram_mib'] ?? $quote['ram_mib']) : 0, 'disk_mib' => in_array($kind, ['gameserver', 'kvm', 'webspace'], true) ? (int) $quote['disk_mib'] : 0, 'ports' => $game ? max(0, (int) ($quote['port_count'] ?? 0)) : 0, 'ipv4' => 'kvm' === $kind ? 1 : 0, 'slots' => in_array($kind, ['teamspeak3', 'teamspeak6'], true) ? (int) $quote['slots'] : 0, 'systems' => 1];
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
     * @return array{state:string,orderable:bool,label:string,detail:string,target:?string,required:array<string,int>,available:array<string,int>,missing:array<string,int>}
     */
    public function inspect(ShopProduct $product, ?array $quote = null): array
    {
        $kind = $product->getKind();
        $options = $product->getProvisioningOptions();
        $quote ??= array_replace($options, ['kind' => $kind->value, 'node_id' => $product->getProvisioningNode()?->getId() ?? '', 'cpu' => $product->getCpuLimit(), 'ram_mib' => $product->getRamLimit() * 1024, 'runtime_ram_mib' => \App\Module\Gameserver\Application\GameserverMemoryBudget::runtimeMiB($product->getRamLimit() * 1024, ShopProductKind::Gameserver === $product->getKind() ? (string) ($product->getGameTemplate()?->getInstallResolver()['type'] ?? '') : ''), 'disk_mib' => $product->getDiskLimit() * 1024, 'port_count' => count($product->getGameTemplate()?->getRequiredPorts() ?? []), 'slots' => (int) ($options['slots'] ?? 32)]);
        $target = self::target($quote);
        $required = ShopProductKind::Domain === $kind ? [] : $this->requirements($quote);
        $result = ['state' => 'available', 'orderable' => true, 'label' => 'Kapazität verfügbar', 'detail' => 'Das freigegebene Restbudget deckt diesen Tarif. Die endgültige Reservierung erfolgt bei der Bestellung.', 'target' => $target, 'required' => $required, 'available' => [], 'missing' => []];
        if (ShopProductKind::Domain === $kind) {
            return array_replace($result, ['label' => 'Verfügbarkeit wird beim Registrar geprüft', 'detail' => 'Für Domains ist kein Kapazitätspool erforderlich. Registrar, Domainname und feste Laufzeit werden separat geprüft.']);
        }
        if (null === $target || 'agent:' === $target || in_array($target, ['teamspeak3:0', 'teamspeak6:0'], true)) {
            return array_replace($result, ['state' => 'target_missing', 'orderable' => false, 'label' => 'Derzeit nicht zur Bestellung freigegeben', 'detail' => 'Dem Produkt fehlt ein Bereitstellungsziel. Bitte zuerst einen Agenten beziehungsweise Voice-Node zuordnen.', 'target' => null]);
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
}
