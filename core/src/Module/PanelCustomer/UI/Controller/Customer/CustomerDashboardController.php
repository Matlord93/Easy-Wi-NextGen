<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Ticket;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\TicketStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Application\DiskUsageFormatter;
use App\Repository\CustomerProfileRepository;
use App\Repository\DatabaseRepository;
use App\Repository\InstanceRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ShopRentalRepository;
use App\Repository\TicketRepository;
use App\Repository\WebspaceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/dashboard')]
final class CustomerDashboardController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly DatabaseRepository $databaseRepository,
        private readonly TicketRepository $ticketRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ShopRentalRepository $rentalRepository,
        private readonly CustomerProfileRepository $profileRepository,
        private readonly DiskUsageFormatter $diskUsageFormatter,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $instances = $this->instanceRepository->findByCustomer($customer);
        $tickets = $this->ticketRepository->findByCustomer($customer);
        $rentals = $this->rentalRepository->findBy(['customer' => $customer], ['expiresAt' => 'DESC'], 1);
        $invoices = $this->invoiceRepository->findByCustomer($customer);

        $summary = [
            'instances_total' => count($instances),
            'instances_running' => $this->countInstancesByStatus($instances, InstanceStatus::Running),
            'webspaces_total' => count($this->webspaceRepository->findByCustomer($customer)),
            'databases_total' => count($this->databaseRepository->findByCustomer($customer)),
            'tickets_total' => count($tickets),
            'tickets_open' => $this->countTicketsByStatus($tickets, TicketStatus::Open),
            'tickets_pending' => $this->countTicketsByStatus($tickets, TicketStatus::Pending),
        ];

        $resourceUsage = $this->calculateResourceUsage($instances);
        $resourceUsage['disk_used_human'] = $this->diskUsageFormatter->formatBytes($resourceUsage['disk_used_bytes']);
        $resourceUsage['disk_limit_human'] = $resourceUsage['disk_limit_bytes'] > 0
            ? $this->diskUsageFormatter->formatBytes($resourceUsage['disk_limit_bytes'])
            : null;
        $nextPayment = $this->resolveNextPayment($invoices);

        return new Response($this->twig->render('customer/dashboard/index.html.twig', [
            'activeNav' => 'dashboard',
            'customerName' => $this->resolveCustomerDisplayName($customer),
            'summary' => $summary,
            'instances' => $this->normalizeInstances(array_slice($instances, 0, 3)),
            'tickets' => $this->normalizeTickets(array_slice($tickets, 0, 3)),
            'resourceUsage' => $resourceUsage,
            'activePlan' => $this->resolveActivePlan($rentals),
            'nextPayment' => $nextPayment,
            'invoices' => $this->normalizeInvoices(array_slice($invoices, 0, 5)),
        ]));
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    /**
     * @param Instance[] $instances
     */
    private function normalizeInstances(array $instances): array
    {
        return array_map(static function (Instance $instance): array {
            $statusLabel = ucwords(str_replace('_', ' ', $instance->getStatus()->value));
            $queryCache = $instance->getQueryStatusCache();
            $latencyMs = isset($queryCache['latency_ms']) && is_numeric($queryCache['latency_ms'])
                ? (int) $queryCache['latency_ms']
                : null;

            return [
                'id' => $instance->getId(),
                'name' => $instance->getTemplate()->getDisplayName(),
                'game_key' => $instance->getTemplate()->getGameKey(),
                'server_name' => $instance->getServerName(),
                'status' => $instance->getStatus()->value,
                'status_label' => $statusLabel,
                'latency_ms' => $latencyMs,
            ];
        }, $instances);
    }

    /**
     * @param Ticket[] $tickets
     */
    private function normalizeTickets(array $tickets): array
    {
        return array_map(static function (Ticket $ticket): array {
            return [
                'id' => $ticket->getId(),
                'subject' => $ticket->getSubject(),
                'status' => $ticket->getStatus()->value,
                'status_label' => $ticket->getStatus()->name,
            ];
        }, $tickets);
    }

    /**
     * @param Instance[] $instances
     */
    private function countInstancesByStatus(array $instances, InstanceStatus $status): int
    {
        return count(array_filter($instances, static fn (Instance $instance): bool => $instance->getStatus() === $status));
    }

    /**
     * @param Ticket[] $tickets
     */
    private function countTicketsByStatus(array $tickets, TicketStatus $status): int
    {
        return count(array_filter($tickets, static fn (Ticket $ticket): bool => $ticket->getStatus() === $status));
    }

    private function resolveCustomerDisplayName(User $customer): string
    {
        $profile = $this->profileRepository->findOneByCustomer($customer);
        if ($profile === null) {
            return $customer->getEmail();
        }

        $name = trim(sprintf('%s %s', $profile->getFirstName(), $profile->getLastName()));
        if ($name !== '') {
            return $name;
        }

        $company = $profile->getCompany();
        if ($company !== null && trim($company) !== '') {
            return $company;
        }

        return $customer->getEmail();
    }

    /**
     * @param Instance[] $instances
     * @return array{cpu: int, ram: int, disk: int}
     */
    private function calculateResourceUsage(array $instances): array
    {
        $diskLimitBytes = 0.0;
        $diskUsedBytes = 0.0;
        $cpuWeighted = 0.0;
        $ramWeighted = 0.0;
        $cpuTotal = 0.0;
        $ramTotal = 0.0;
        $nodeLimits = [];
        $nodeMap = [];

        foreach ($instances as $instance) {
            $nodeId = $instance->getNode()->getId();
            $nodeMap[$nodeId] = $instance->getNode();
            $nodeLimits[$nodeId]['cpu'] = ($nodeLimits[$nodeId]['cpu'] ?? 0) + $instance->getCpuLimit();
            $nodeLimits[$nodeId]['ram'] = ($nodeLimits[$nodeId]['ram'] ?? 0) + $instance->getRamLimit();

            $cpuTotal += $instance->getCpuLimit();
            $ramTotal += $instance->getRamLimit();
            $diskLimitBytes += $instance->getDiskLimitBytes();
            $diskUsedBytes += $instance->getDiskUsedBytes();
        }

        foreach ($nodeLimits as $nodeId => $limits) {
            $node = $nodeMap[$nodeId] ?? null;
            if ($node === null) {
                continue;
            }

            $stats = $node->getLastHeartbeatStats() ?? [];
            $metrics = is_array($stats['metrics'] ?? null) ? $stats['metrics'] : [];
            $nodeCpuPercent = $this->extractMetricPercent($metrics, 'cpu')
                ?? $this->extractMetricPercent($stats, 'cpu');
            $nodeMemoryPercent = $this->extractMetricPercent($metrics, 'memory')
                ?? $this->extractMetricPercent($stats, 'memory');

            if ($nodeCpuPercent !== null && ($limits['cpu'] ?? 0) > 0) {
                $cpuWeighted += $nodeCpuPercent * $limits['cpu'];
            }

            if ($nodeMemoryPercent !== null && ($limits['ram'] ?? 0) > 0) {
                $ramWeighted += $nodeMemoryPercent * $limits['ram'];
            }
        }

        return [
            'cpu' => $this->formatUsagePercent($cpuWeighted, $cpuTotal),
            'ram' => $this->formatUsagePercent($ramWeighted, $ramTotal),
            'disk' => $this->formatUsagePercent($diskUsedBytes, $diskLimitBytes),
            'disk_used_bytes' => (int) $diskUsedBytes,
            'disk_limit_bytes' => (int) $diskLimitBytes,
        ];
    }

    private function formatUsagePercent(float $used, float $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) min(100, round(($used / $total) * 100));
    }

    private function extractMetricPercent(array $metrics, string $key): ?float
    {
        $metric = $metrics[$key] ?? null;
        if (is_array($metric)) {
            $value = $metric['percent'] ?? null;
            return is_numeric($value) ? (float) $value : null;
        }

        return is_numeric($metric) ? (float) $metric : null;
    }

    private function resolveActivePlan(array $rentals): ?array
    {
        $rental = $rentals[0] ?? null;
        if (!$rental instanceof \App\Module\Core\Domain\Entity\ShopRental) {
            return null;
        }

        return [
            'name' => $rental->getProduct()->getName(),
            'expires_at' => $rental->getExpiresAt(),
        ];
    }

    private function resolveNextPayment(array $invoices): ?array
    {
        $next = null;
        foreach ($invoices as $invoice) {
            if (!$invoice instanceof \App\Module\Core\Domain\Entity\Invoice) {
                continue;
            }
            if ($invoice->getStatus() === \App\Module\Core\Domain\Enum\InvoiceStatus::Paid) {
                continue;
            }
            if ($next === null || $invoice->getDueDate() < $next->getDueDate()) {
                $next = $invoice;
            }
        }

        if ($next === null) {
            return null;
        }

        return [
            'number' => $next->getNumber(),
            'amount_due' => $next->getAmountDueCents(),
            'currency' => $next->getCurrency(),
            'due_date' => $next->getDueDate(),
            'status' => $next->getStatus()->value,
        ];
    }

    private function normalizeInvoices(array $invoices): array
    {
        return array_map(static function (\App\Module\Core\Domain\Entity\Invoice $invoice): array {
            return [
                'id' => $invoice->getId(),
                'number' => $invoice->getNumber(),
                'status' => $invoice->getStatus()->value,
                'amount_due' => $invoice->getAmountDueCents(),
                'currency' => $invoice->getCurrency(),
                'due_date' => $invoice->getDueDate(),
            ];
        }, $invoices);
    }
}
