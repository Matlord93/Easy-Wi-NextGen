<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Ticket;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\TicketStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\CustomerProfileRepository;
use App\Repository\DatabaseRepository;
use App\Repository\InstanceRepository;
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
        private readonly CustomerProfileRepository $profileRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $instances = $this->instanceRepository->findByCustomer($customer);
        $tickets = $this->ticketRepository->findByCustomer($customer);

        $summary = [
            'instances_total' => count($instances),
            'instances_running' => $this->countInstancesByStatus($instances, InstanceStatus::Running),
            'webspaces_total' => count($this->webspaceRepository->findByCustomer($customer)),
            'databases_total' => count($this->databaseRepository->findByCustomer($customer)),
            'tickets_total' => count($tickets),
            'tickets_open' => $this->countTicketsByStatus($tickets, TicketStatus::Open),
        ];

        $resourceUsage = $this->calculateResourceUsage($instances);

        return new Response($this->twig->render('customer/dashboard/index.html.twig', [
            'activeNav' => 'dashboard',
            'customerName' => $this->resolveCustomerDisplayName($customer),
            'summary' => $summary,
            'instances' => $this->normalizeInstances(array_slice($instances, 0, 3)),
            'tickets' => $this->normalizeTickets(array_slice($tickets, 0, 3)),
            'resourceUsage' => $resourceUsage,
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

            return [
                'id' => $instance->getId(),
                'name' => $instance->getTemplate()->getName(),
                'status' => $instance->getStatus()->value,
                'status_label' => $statusLabel,
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
        $totals = [
            'cpu' => 0,
            'ram' => 0,
            'disk' => 0,
        ];
        $running = [
            'cpu' => 0,
            'ram' => 0,
            'disk' => 0,
        ];

        foreach ($instances as $instance) {
            $totals['cpu'] += $instance->getCpuLimit();
            $totals['ram'] += $instance->getRamLimit();
            $totals['disk'] += $instance->getDiskLimit();

            if ($instance->getStatus() === InstanceStatus::Running) {
                $running['cpu'] += $instance->getCpuLimit();
                $running['ram'] += $instance->getRamLimit();
                $running['disk'] += $instance->getDiskLimit();
            }
        }

        return [
            'cpu' => $this->formatUsagePercent($running['cpu'], $totals['cpu']),
            'ram' => $this->formatUsagePercent($running['ram'], $totals['ram']),
            'disk' => $this->formatUsagePercent($running['disk'], $totals['disk']),
        ];
    }

    private function formatUsagePercent(int $used, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) min(100, round(($used / $total) * 100));
    }
}
