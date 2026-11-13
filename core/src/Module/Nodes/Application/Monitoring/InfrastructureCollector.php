<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Monitoring;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Nodes\Application\InfrastructureUsage;
use App\Module\Nodes\Application\Kvm\KvmPanelService;
use App\Module\Nodes\Application\Proxmox\ProxmoxManager;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

final readonly class InfrastructureCollector
{
    public function __construct(private EntityManagerInterface $em, private InfrastructureHistoryStore $history, private InfrastructureAlerts $alerts, private InfrastructureTargets $targets, private InfrastructureUsage $usage, private KvmPanelService $kvm, private ProxmoxManager $proxmox, private InfrastructureMonitoringSettings $settings, private LockFactory $locks)
    {
    }

    public function run(bool $force = false, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $options = $this->settings->get();
        $state = $this->settings->state();
        $result = ['samples' => 0, 'unavailable' => 0, 'pruned' => 0, 'skipped' => false];
        if (!$options['enabled'] || (!$force && ($state['last_minute'] ?? null) === intdiv($now->getTimestamp(), 60))) {
            return array_replace($result, ['skipped' => true]);
        }
        $lock = $this->locks->createLock('infrastructure.monitoring.collect', 120);
        if (!$lock->acquire()) {
            return array_replace($result, ['skipped' => true]);
        }
        try {
            $started = microtime(true);
            foreach ($this->em->getRepository(Agent::class)->findAll() as $node) {
                if (true !== ($node->getMetadata()['kvm_managed']['supported'] ?? null) || Agent::STATUS_DECOMMISSIONED === $node->getStatus()) {
                    continue;
                }
                $nodeUsage = $this->usage->node($node);
                $this->observe($this->targets->node($node), $nodeUsage, !$nodeUsage['stale'], $now, $result);
                foreach ($this->kvm->inventory($node)['guests'] ?? [] as $guest) {
                    if (empty($guest['managed']) || !empty($guest['error'])) {
                        continue;
                    }
                    if (!is_string($guest['id'] ?? null) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $guest['id'])) {
                        continue;
                    }
                    $target = $this->targets->kvm($node, $guest['id'], (string) ($guest['label'] ?? ''));
                    $data = $this->usage->kvm($node, $guest['id']);
                    // An intentionally stopped guest is not an unavailable host.
                    $available = !$nodeUsage['stale'] && (!$data['stale'] || 'shut off' === ($guest['state'] ?? ''));
                    $this->observe($target, $data, $available, $now, $result);
                }
            }
            $clusters = $this->em->getRepository(ProxmoxCluster::class)->findBy(['enabled' => true], ['id' => 'ASC']);
            $count = count($clusters);
            $start = $count ? (int) ($state['cluster_cursor'] ?? 0) % $count : 0;
            for ($step = 0; $step < $count && $step < 4 && microtime(true) - $started < 25; ++$step) {
                $index = ($start + $step) % $count;
                $cluster = $clusters[$index];
                $state['cluster_cursor'] = ($index + 1) % $count;
                try {
                    $overview = $this->proxmox->overview($cluster);
                    $nodeStates = array_column($overview['nodes'], 'status', 'node');
                    $state['known_nodes'][$cluster->getId()] = array_values(array_slice(array_filter(array_column($overview['nodes'], 'node'), static fn ($name): bool => is_string($name) && 1 === preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/D', $name)), 0, 1024));
                    $at = (new \DateTimeImmutable())->format(DATE_ATOM);
                    foreach ($overview['nodes'] as $node) {
                        $data = $this->usage->proxmox($node + ['observed_at' => $at]);
                        $this->observe($this->targets->proxmox($cluster, 'node', (string) $node['node']), $data, 'online' === ($node['status'] ?? ''), $now, $result);
                    }
                    $guests = array_values(array_filter($overview['guests'], static fn (array $g): bool => empty($g['template'])));
                    usort($guests, static fn (array $a, array $b): int => [$a['vmid'], $a['type']] <=> [$b['vmid'], $b['type']]);
                    $guestCount = count($guests);
                    $cursor = $guestCount ? (int) ($state['vm_cursors'][$cluster->getId()] ?? 0) % $guestCount : 0;
                    for ($n = 0; $n < min($guestCount, $options['proxmox_batch_size']) && microtime(true) - $started < 25; ++$n) {
                        $guestIndex = ($cursor + $n) % $guestCount;
                        $guest = $guests[$guestIndex];
                        $state['vm_cursors'][$cluster->getId()] = ($guestIndex + 1) % $guestCount;
                        $target = $this->targets->proxmox($cluster, $guest['type'], (string) $guest['vmid'], (string) ($guest['name'] ?? ''));
                        if (isset($nodeStates[$guest['node']]) && 'online' !== $nodeStates[$guest['node']]) {
                            // Cluster resources can retain the last values of an offline host.
                            $this->observe($target, [], false, $now, $result);
                            continue;
                        }
                        $assignment = $this->em->getRepository(ProxmoxGuestAssignment::class)->findOneBy(['cluster' => $cluster, 'kind' => $guest['type'], 'vmId' => (int) $guest['vmid']]);
                        try {
                            $values = $this->proxmox->monitoringUsage($cluster, $guest, $assignment?->getIdentityTag());
                            $this->observe($target, $this->usage->proxmox($values + ['observed_at' => $at]), true, $now, $result);
                        } catch (\InvalidArgumentException) {
                            // Identity mismatch must not persist another owner's VM data.
                            ++$result['unavailable'];
                        } catch (\App\Module\Nodes\Domain\Proxmox\ProxmoxApiException) {
                            $this->observe($target, [], false, $now, $result);
                        }
                    }
                } catch (\App\Module\Nodes\Domain\Proxmox\ProxmoxApiException) {
                    ++$result['unavailable'];
                    foreach ($state['known_nodes'][$cluster->getId()] ?? [] as $name) {
                        $this->observe($this->targets->proxmox($cluster, 'node', $name), [], false, $now, $result);
                    }
                    foreach ($this->em->getRepository(ProxmoxGuestAssignment::class)->findBy(['cluster' => $cluster]) as $assignment) {
                        $this->observe($this->targets->proxmox($cluster, $assignment->getKind(), (string) $assignment->getVmId()), [], false, $now, $result);
                    }
                }
            }
            $result['pruned'] = $this->history->prune((int) $options['retention_days'], $now);
            $state['last_minute'] = intdiv($now->getTimestamp(), 60);
            $state['last_run_at'] = $now->format(DATE_ATOM);
            $state['last_result'] = $result;
            // Removed clusters must not leave an unbounded cursor dictionary.
            $state['vm_cursors'] = array_intersect_key($state['vm_cursors'] ?? [], array_flip(array_map(static fn (ProxmoxCluster $c) => $c->getId(), $clusters)));
            $state['known_nodes'] = array_intersect_key($state['known_nodes'] ?? [], array_flip(array_map(static fn (ProxmoxCluster $c) => $c->getId(), $clusters)));
            $this->settings->updateState($state);

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function observe(InfrastructureTarget $target, array $data, bool $available, \DateTimeImmutable $now, array &$result): void
    {
        if ($available) {
            $result['samples'] += $this->history->record($target, $data);
        } else {
            ++$result['unavailable'];
        }
        $observed = $data['observed_at'] ?? null;
        $at = $available && is_string($observed) ? (new \DateTimeImmutable($observed))->getTimestamp() : $now->getTimestamp();
        if (null === $target->visibleSince || !$available || $at > $target->visibleSince) {
            $this->alerts->observe($target, $data, $at, $available);
        }
    }
}
