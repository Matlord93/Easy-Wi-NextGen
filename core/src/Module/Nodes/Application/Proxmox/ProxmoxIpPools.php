<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

use App\Infrastructure\Doctrine\EntityIdentifier;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxIpLease;
use App\Module\Nodes\Domain\Entity\ProxmoxIpPool;
use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use App\Module\Nodes\Domain\Proxmox\ProxmoxIpRange;
use App\Repository\EntityQueryResult;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProxmoxIpPools
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return list<ProxmoxIpPool> */
    public function pools(ProxmoxCluster $cluster, string $node): array
    {
        return $this->em->getRepository(ProxmoxIpPool::class)->findBy(compact('cluster', 'node'), ['family' => 'ASC']);
    }

    /** @return list<ProxmoxIpLease> */
    public function leases(ProxmoxTask $task): array
    {
        return $this->em->getRepository(ProxmoxIpLease::class)->findBy(['task' => $task], ['family' => 'ASC']);
    }

    /** @return list<string> */
    public function switchChoices(ProxmoxTask $task, string $ipConfig): array
    {
        $choices = [];
        $settings = self::networkSettings($ipConfig);
        foreach ($this->leases($task) as $lease) {
            if (self::active($lease, $settings)) {
                $choices[] = $lease->getAddress();
            }
        }

        return $choices;
    }

    public function reserveSwitch(ProxmoxTask $task, string $net0, string $ipConfig, string $expectedAddress): string
    {
        return $this->em->getConnection()->transactional(function () use ($task, $net0, $ipConfig, $expectedAddress): string {
            $cluster = $task->getCluster();
            $this->em->refresh($cluster, LockMode::PESSIMISTIC_WRITE);
            $settings = self::networkSettings($ipConfig);
            $leases = $this->leases($task);
            $active = array_find($leases, static fn (ProxmoxIpLease $lease): bool => $lease->getAddress() === $expectedAddress && self::active($lease, $settings));
            if (null === $active) {
                throw new \InvalidArgumentException('vm_config.address_switch_unavailable');
            }
            $family = $active->getFamily();
            $pool = $this->em->getRepository(ProxmoxIpPool::class)->findOneBy(['cluster' => $cluster, 'node' => $task->getNode(), 'family' => $family, 'enabled' => true]);
            if (null === $pool || !self::matchesPool($active, $pool)) {
                throw new \InvalidArgumentException('pve.error.ip_pool_network');
            }
            self::assertPort($net0, $pool->getBridge(), $pool->getVlan());
            $alternate = array_find($leases, static fn (ProxmoxIpLease $lease): bool => $lease->getFamily() === $family && $lease->getReservationSlot() !== $active->getReservationSlot());
            if (null === $alternate) {
                $key = $this->freeAddress($pool);
                $this->em->getConnection()->insert('proxmox_ip_leases', [
                    'task_id' => $task->getId(), 'family' => $family, 'address_key' => $key, 'reservation_slot' => 1,
                    'prefix' => $pool->getPrefix(), 'gateway' => $pool->getGateway(), 'bridge' => $pool->getBridge(), 'vlan' => $pool->getVlan(),
                ]);
                $nextAddress = ProxmoxIpRange::address($key);
            } else {
                if (!self::matchesPool($alternate, $pool)) {
                    throw new \InvalidArgumentException('pve.error.ip_pool_network');
                }
                $nextAddress = $alternate->getAddress();
            }
            $settings[6 === $family ? 'ip6' : 'ip'] = $nextAddress.'/'.$pool->getPrefix();

            return implode(',', array_map(static fn (string $field, string $value): string => $field.'='.$value, array_keys($settings), $settings));
        });
    }

    private static function matchesPool(ProxmoxIpLease $lease, ProxmoxIpPool $pool): bool
    {
        $key = ProxmoxIpRange::key($lease->getAddress());

        return strcmp($key, ProxmoxIpRange::key($pool->getFirst())) >= 0 && strcmp($key, ProxmoxIpRange::key($pool->getLast())) <= 0
            && $lease->getGateway() === $pool->getGateway() && $lease->getPrefix() === $pool->getPrefix()
            && $lease->getBridge() === $pool->getBridge() && $lease->getVlan() === $pool->getVlan();
    }

    /** @param array<string, string> $settings */
    private static function active(ProxmoxIpLease $lease, array $settings): bool
    {
        $suffix = 6 === $lease->getFamily() ? '6' : '';
        [$address, $prefix] = array_pad(explode('/', $settings['ip'.$suffix] ?? '', 2), 2, '');
        $gateway = $settings['gw'.$suffix] ?? '';

        return false !== filter_var($address, FILTER_VALIDATE_IP) && false !== filter_var($gateway, FILTER_VALIDATE_IP)
            && ProxmoxIpRange::key($address) === ProxmoxIpRange::key($lease->getAddress()) && (string) $lease->getPrefix() === $prefix
            && ProxmoxIpRange::key($gateway) === ProxmoxIpRange::key($lease->getGateway());
    }

    /** @return array<string, string> */
    public static function networkSettings(string $value): array
    {
        $settings = [];
        foreach (explode(',', $value) as $pair) {
            [$field, $setting] = array_pad(explode('=', $pair, 2), 2, '');
            if ('' === $setting || !in_array($field, ['ip', 'gw', 'ip6', 'gw6'], true) || isset($settings[$field])) {
                return [];
            }
            $settings[$field] = $setting;
        }
        ksort($settings, SORT_STRING);

        return $settings;
    }

    /** @return list<ProxmoxIpLease> */
    public function nodeLeases(ProxmoxCluster $cluster, string $node, int $afterId): array
    {
        $query = $this->em->createQueryBuilder()->select('lease, task')->from(ProxmoxIpLease::class, 'lease')
            ->join('lease.task', 'task')->where('task.cluster = :cluster')->andWhere('task.node = :node')->andWhere('lease.id > :cursor')
            ->setParameter('cluster', EntityIdentifier::require($cluster->getId()))->setParameter('node', $node)->setParameter('cursor', $afterId)
            ->orderBy('lease.id')->setMaxResults(51)->getQuery();

        return EntityQueryResult::all($query, ProxmoxIpLease::class);
    }

    public function save(ProxmoxCluster $cluster, string $node, ProxmoxIpRange $range, string $bridge, ?int $vlan, bool $enabled): void
    {
        $this->em->getConnection()->transactional(function () use ($cluster, $node, $range, $bridge, $vlan, $enabled): void {
            $this->em->refresh($cluster, LockMode::PESSIMISTIC_WRITE);
            $repository = $this->em->getRepository(ProxmoxIpPool::class);
            $pool = $repository->findOneBy(['cluster' => $cluster, 'node' => $node, 'family' => $range->family]);
            if ($enabled) {
                $otherFamily = $repository->findOneBy(['cluster' => $cluster, 'node' => $node, 'family' => 4 === $range->family ? 6 : 4, 'enabled' => true]);
                if (null !== $otherFamily && ($otherFamily->getBridge() !== $bridge || $otherFamily->getVlan() !== $vlan)) {
                    throw new \InvalidArgumentException('pve.error.ip_pool_network');
                }
                $first = ProxmoxIpRange::key($range->first);
                $last = ProxmoxIpRange::key($range->last);
                foreach ($repository->findBy(['family' => $range->family, 'enabled' => true]) as $other) {
                    if ($other !== $pool && strcmp($first, ProxmoxIpRange::key($other->getLast())) <= 0 && strcmp($last, ProxmoxIpRange::key($other->getFirst())) >= 0) {
                        throw new \InvalidArgumentException('pve.error.ip_pool_overlap');
                    }
                }
            }
            if (null === $pool) {
                $this->em->persist(new ProxmoxIpPool($cluster, $node, $range->family, $range, $bridge, $vlan, $enabled));
            } else {
                $pool->configure($range, $bridge, $vlan, $enabled);
            }
            $this->em->flush();
        });
    }

    /** Called in the same DB transaction as the task claim. */
    public function reserve(int $taskId, ProxmoxCluster $cluster, string $node, string $net0): void
    {
        $pools = $this->em->getRepository(ProxmoxIpPool::class)->findBy(['cluster' => $cluster, 'node' => $node, 'enabled' => true], ['family' => 'ASC']);
        if ([] === $pools) {
            throw new \InvalidArgumentException('pve.error.ip_pool_missing');
        }
        foreach ($pools as $pool) {
            self::assertPort($net0, $pool->getBridge(), $pool->getVlan());
            $this->em->getConnection()->insert('proxmox_ip_leases', [
                'task_id' => $taskId, 'family' => $pool->getFamily(), 'address_key' => $this->freeAddress($pool),
                'prefix' => $pool->getPrefix(), 'gateway' => $pool->getGateway(), 'bridge' => $pool->getBridge(), 'vlan' => $pool->getVlan(),
            ]);
        }
    }

    private function freeAddress(ProxmoxIpPool $pool): string
    {
        $candidate = ProxmoxIpRange::key($pool->getFirst());
        $last = ProxmoxIpRange::key($pool->getLast());
        $allocated = $this->em->getConnection()->iterateColumn('SELECT address_key FROM proxmox_ip_leases WHERE family = ? AND address_key BETWEEN ? AND ? ORDER BY address_key', [$pool->getFamily(), $candidate, $last]);
        foreach ($allocated as $used) {
            if (!is_string($used) || null === $candidate) {
                throw new \UnexpectedValueException('Invalid address reservation.');
            }
            if ($used !== $candidate) {
                break;
            }
            $candidate = ProxmoxIpRange::successor($candidate);
        }
        if (null === $candidate || strcmp($candidate, $last) > 0) {
            throw new \InvalidArgumentException('pve.error.ip_pool_exhausted');
        }

        return $candidate;
    }

    public static function assertPort(string $net0, string $bridge, ?int $vlan): void
    {
        preg_match('/(?:^|,)bridge=([A-Za-z0-9_.-]+)(?:,|$)/D', $net0, $bridgeMatch);
        preg_match('/(?:^|,)tag=([0-9]+)(?:,|$)/D', $net0, $vlanMatch);
        if (($bridgeMatch[1] ?? null) !== $bridge || (isset($vlanMatch[1]) ? (int) $vlanMatch[1] : null) !== $vlan || str_contains($net0, 'trunks=') || str_contains($net0, 'link_down=1')) {
            throw new \InvalidArgumentException('pve.error.ip_pool_network');
        }
    }

    /** @param list<ProxmoxIpLease> $leases */
    public static function cloudInit(array $leases): string
    {
        $parts = [];
        foreach ($leases as $lease) {
            if (0 !== $lease->getReservationSlot()) {
                continue;
            }
            $suffix = 6 === $lease->getFamily() ? '6' : '';
            $parts[] = 'ip'.$suffix.'='.$lease->getAddress().'/'.$lease->getPrefix();
            $parts[] = 'gw'.$suffix.'='.$lease->getGateway();
        }

        return [] === $parts ? 'ip=dhcp' : implode(',', $parts);
    }
}
