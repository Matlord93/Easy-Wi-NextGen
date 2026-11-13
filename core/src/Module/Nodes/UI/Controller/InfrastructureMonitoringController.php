<?php

declare(strict_types=1);

namespace App\Module\Nodes\UI\Controller;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Nodes\Application\Monitoring\InfrastructureAlerts;
use App\Module\Nodes\Application\Monitoring\InfrastructureCollector;
use App\Module\Nodes\Application\Monitoring\InfrastructureHistoryStore;
use App\Module\Nodes\Application\Monitoring\InfrastructureMonitoringSettings;
use App\Module\Nodes\Application\Monitoring\InfrastructureTarget;
use App\Module\Nodes\Application\Monitoring\InfrastructureTargets;
use App\Module\Nodes\Domain\Entity\KvmGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class InfrastructureMonitoringController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly InfrastructureTargets $targets, private readonly InfrastructureHistoryStore $history, private readonly InfrastructureAlerts $alerts, private readonly InfrastructureMonitoringSettings $settings, private readonly InfrastructureCollector $collector, private readonly AuditLogger $audit)
    {
    }

    #[Route('/admin/monitoring', name: 'admin_infrastructure_monitoring', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $actor = $this->actor($request, true);
        $error = null;
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'infrastructure-monitoring-settings');
            try {
                if ('collect' === $request->request->get('action')) {
                    $this->collector->run(true);
                } else {
                    $this->settings->save($request->request->all('options'));
                    $this->audit->log($actor, 'infrastructure.monitoring.settings_updated', []);
                    $this->em->flush();
                }

                return $this->redirectToRoute('admin_infrastructure_monitoring', [], 303);
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('monitoring/index.html.twig', ['options' => $this->settings->get(), 'collector' => $this->settings->state(), 'error' => $error, 'activeNav' => 'monitoring'], new Response(status: $error ? 422 : 200, headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/admin/monitoring/{provider}/{connection}/{kind}/{reference}', name: 'admin_infrastructure_history', methods: ['GET', 'POST'])]
    public function admin(Request $request, string $provider, string $connection, string $kind, string $reference): Response
    {
        $actor = $this->actor($request, true);
        try {
            if ('kvm' === $provider) {
                $node = $this->em->find(Agent::class, $connection) ?? throw $this->createNotFoundException();
                if ('node' === $kind && $reference === $connection) {
                    $target = $this->targets->node($node);
                } elseif ('vm' === $kind) {
                    $target = $this->targets->kvm($node, $reference);
                } else {
                    throw $this->createNotFoundException();
                }
            } elseif ('proxmox' === $provider && ctype_digit($connection)) {
                $cluster = $this->em->find(ProxmoxCluster::class, (int) $connection) ?? throw $this->createNotFoundException();
                $target = $this->targets->proxmox($cluster, $kind, $reference);
            } else {
                throw $this->createNotFoundException();
            }
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'infrastructure-policy-'.$target->key);
            try {
                $this->alerts->configure($target, $request->request->all());
                $this->audit->log($actor, 'infrastructure.monitoring.policy_updated', ['resource_key' => $target->key]);
                $this->em->flush();

                return $this->redirectToRoute('admin_infrastructure_history', compact('provider', 'connection', 'kind', 'reference'), 303);
            } catch (\InvalidArgumentException $error) {
                return $this->page($request, $target, true, $error->getMessage());
            }
        }

        return $this->page($request, $target, true);
    }

    #[Route('/customer/monitoring/{provider}/{id<\d+>}', name: 'customer_infrastructure_history', methods: ['GET'])]
    public function customer(Request $request, string $provider, int $id): Response
    {
        $actor = $this->actor($request, false);
        if ('kvm' === $provider) {
            $assignment = $this->em->find(KvmGuestAssignment::class, $id);
            if (!$assignment || $assignment->getCustomer()->getId() !== $actor->getId()) {
                throw $this->createNotFoundException();
            }
            $target = $this->targets->kvm($assignment->getNode(), $assignment->getVmUuid());
        } elseif ('proxmox' === $provider) {
            $assignment = $this->em->find(ProxmoxGuestAssignment::class, $id);
            if (!$assignment || $assignment->getCustomer()->getId() !== $actor->getId()) {
                throw $this->createNotFoundException();
            }
            $target = $this->targets->proxmox($assignment->getCluster(), $assignment->getKind(), (string) $assignment->getVmId());
        } else {
            throw $this->createNotFoundException();
        }
        if ($target->customer?->getId() !== $actor->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->page($request, $target, false);
    }

    private function page(Request $request, InfrastructureTarget $target, bool $admin, ?string $error = null): Response
    {
        $format = (string) $request->query->get('format', 'html');
        if (!in_array($format, ['html', 'json', 'csv'], true)) {
            return new Response('Invalid format.', 400);
        }
        if ('html' !== $format) {
            try {
                [$from, $to] = $this->range($request);
                $history = $this->history->history($target, $from, $to);
            } catch (\InvalidArgumentException) {
                return new JsonResponse(['error' => 'monitor.invalid_range'], 422, ['Cache-Control' => 'private, no-store']);
            }
            if ('json' === $format) {
                return new JsonResponse($history, 200, ['Cache-Control' => 'private, no-store']);
            }

            return $this->csv($target, $history);
        }

        return $this->render('monitoring/history.html.twig', ['target' => $target, 'admin' => $admin, 'options' => $this->settings->get(), 'policy' => $this->alerts->policy($target), 'error' => $error, 'activeNav' => 'monitoring'], new Response(status: $error ? 422 : 200, headers: ['Cache-Control' => 'private, no-store']));
    }

    private function range(Request $request): array
    {
        if ($request->query->has('from') || $request->query->has('to')) {
            $times = [];
            foreach (['from', 'to'] as $field) {
                $raw = $request->query->get($field);
                if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z$/D', $raw)) {
                    throw new \InvalidArgumentException();
                }
                try {
                    $date = new \DateTimeImmutable($raw);
                } catch (\Exception) {
                    throw new \InvalidArgumentException();
                }
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
                    throw new \InvalidArgumentException();
                }
                $times[] = $date->getTimestamp();
            }

            return $times;
        }
        $hours = filter_var($request->query->get('hours', '24'), FILTER_VALIDATE_INT);
        if (!in_array($hours, [1, 6, 24, 168, 720, 2160, 8760], true)) {
            throw new \InvalidArgumentException();
        }

        return [time() - $hours * 3600, time()];
    }

    private function csv(InfrastructureTarget $target, array $history): Response
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['bucket_start_utc', 'first_observed_utc', 'last_observed_utc', 'stream', 'resolution_seconds', 'samples', 'metric', 'average', 'minimum', 'maximum', 'sources', 'memory_sources', 'interval_min_seconds', 'interval_max_seconds'], ',', '"', '');
        foreach ($history['points'] as $point) {
            foreach ($point['metrics'] as $metric => $values) {
                fputcsv($handle, [gmdate('Y-m-d\TH:i:s\Z', $point['timestamp']), gmdate('Y-m-d\TH:i:s\Z', $point['first_observed_at']), gmdate('Y-m-d\TH:i:s\Z', $point['last_observed_at']), $point['stream'], $point['resolution_seconds'], $values['count'], $metric, $values['average'], $values['min'], $values['max'], implode('|', $point['sources']), implode('|', $point['memory_sources']), $point['interval_min_seconds'] ?? '', $point['interval_max_seconds'] ?? ''], ',', '"', '');
            }
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return new Response("\xEF\xBB\xBF".$csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="monitoring-'.substr($target->key, 0, 12).'.csv"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function actor(Request $request, bool $admin): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || ($admin ? !$actor->isAdmin() : !$actor->isCustomer())) {
            throw new AccessDeniedHttpException();
        }

        return $actor;
    }

    private function csrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token', ''))) {
            throw new AccessDeniedHttpException();
        }
    }
}
