<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Application\DiskUsageFormatter;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Ticket;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\TicketStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelCustomer\Application\BookedResourceUsageAggregator;
use App\Repository\CustomerProfileRepository;
use App\Repository\DatabaseRepository;
use App\Repository\InstanceMetricSampleRepository;
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
        private readonly InstanceMetricSampleRepository $instanceMetricSampleRepository,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly DatabaseRepository $databaseRepository,
        private readonly TicketRepository $ticketRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ShopRentalRepository $rentalRepository,
        private readonly CustomerProfileRepository $profileRepository,
        private readonly DiskUsageFormatter $diskUsageFormatter,
        private readonly BookedResourceUsageAggregator $bookedResourceUsageAggregator,
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

        $latestInstanceMetrics = $this->instanceMetricSampleRepository->findLatestByInstances($instances);
        $resourceUsage = $this->calculateResourceUsage($instances, $latestInstanceMetrics);
        $resourceUsage['disk_used_human'] = $this->diskUsageFormatter->formatBytes($resourceUsage['disk_used_bytes']);
        $resourceUsage['disk_limit_human'] = $resourceUsage['disk_limit_bytes'] > 0
            ? $this->diskUsageFormatter->formatBytes($resourceUsage['disk_limit_bytes'])
            : null;
        $resourceUsage['booked_ram_human'] = is_numeric($resourceUsage['total_booked_ram_bytes'] ?? null)
            ? $this->diskUsageFormatter->formatBytes((int) $resourceUsage['total_booked_ram_bytes'])
            : null;
        $resourceUsage['used_ram_human'] = is_numeric($resourceUsage['total_used_ram_bytes'] ?? null)
            ? $this->diskUsageFormatter->formatBytes((int) $resourceUsage['total_used_ram_bytes'])
            : null;
        $nextPayment = $this->resolveNextPayment($invoices);

        return new Response($this->twig->render('customer/dashboard/index.html.twig', [
            'activeNav' => 'dashboard',
            'customerName' => $this->resolveCustomerDisplayName($customer),
            'summary' => $summary,
            'instances' => $this->normalizeInstances(array_slice($instances, 0, 3), $latestInstanceMetrics),
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
    private function normalizeInstances(array $instances, array $latestInstanceMetrics): array
    {
        return array_map(static function (Instance $instance) use ($latestInstanceMetrics): array {
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
                'ram_limit' => $instance->getRamLimit(),
                'booked_cpu_cores' => (float) $instance->getCpuLimit(),
                'booked_ram_bytes' => (int) $instance->getRamLimit() * 1024 * 1024,
                'cpu_percent' => is_numeric($latestInstanceMetrics[$instance->getId()]['cpu_percent'] ?? null) ? (float) $latestInstanceMetrics[$instance->getId()]['cpu_percent'] : null,
                'mem_used_bytes' => is_numeric($latestInstanceMetrics[$instance->getId()]['mem_used_bytes'] ?? null) ? (int) $latestInstanceMetrics[$instance->getId()]['mem_used_bytes'] : null,
                'metrics_reason' => is_string($latestInstanceMetrics[$instance->getId()]['error_code'] ?? null) ? $latestInstanceMetrics[$instance->getId()]['error_code'] : null,
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
     * @param array<int, array{cpu_percent: ?float, mem_used_bytes: ?int, tasks_current: ?int, collected_at: \DateTimeImmutable, error_code: ?string}> $latestInstanceMetrics
     * @return array{cpu: int|null, ram: int|null, disk: int, disk_used_bytes:int, disk_limit_bytes:int, total_booked_cpu_cores:?float, total_used_cpu_cores:?float, total_cpu_percent:?float, total_booked_ram_bytes:?int, total_used_ram_bytes:?int, total_ram_percent:?float}
     */
    private function calculateResourceUsage(array $instances, array $latestInstanceMetrics): array
    {
        $diskLimitBytes = 0.0;
        $diskUsedBytes = 0.0;
        $rows = [];

        foreach ($instances as $instance) {
            $diskLimitBytes += $instance->getDiskLimitBytes();
            $diskUsedBytes += $instance->getDiskUsedBytes();

            $metrics = $latestInstanceMetrics[$instance->getId()] ?? null;
            $rows[] = [
                'booked_cpu_cores' => (float) $instance->getCpuLimit(),
                'booked_ram_bytes' => (int) $instance->getRamLimit() * 1024 * 1024,
                'used_cpu_percent' => is_array($metrics) && is_numeric($metrics['cpu_percent'] ?? null) ? (float) $metrics['cpu_percent'] : null,
                'used_ram_bytes' => is_array($metrics) && is_numeric($metrics['mem_used_bytes'] ?? null) ? (int) $metrics['mem_used_bytes'] : null,
            ];
        }

        $totals = $this->bookedResourceUsageAggregator->aggregate($rows);

        return [
            'cpu' => isset($totals['total_cpu_percent']) ? (int) round((float) $totals['total_cpu_percent']) : null,
            'ram' => isset($totals['total_ram_percent']) ? (int) round((float) $totals['total_ram_percent']) : null,
            'disk' => $this->formatUsagePercent($diskUsedBytes, $diskLimitBytes),
            'disk_used_bytes' => (int) $diskUsedBytes,
            'disk_limit_bytes' => (int) $diskLimitBytes,
            'total_booked_cpu_cores' => $totals['total_booked_cpu_cores'],
            'total_used_cpu_cores' => $totals['total_used_cpu_cores'],
            'total_cpu_percent' => $totals['total_cpu_percent'],
            'total_booked_ram_bytes' => $totals['total_booked_ram_bytes'],
            'total_used_ram_bytes' => $totals['total_used_ram_bytes'],
            'total_ram_percent' => $totals['total_ram_percent'],
        ];
    }


    private function formatUsagePercent(float $used, float $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) min(100, round(($used / $total) * 100));
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
