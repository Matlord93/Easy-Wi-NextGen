<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\TicketMessage;
use App\Entity\User;
use App\Enum\TicketCategory;
use App\Enum\TicketPriority;
use App\Enum\TicketStatus;
use App\Enum\UserType;
use App\Repository\TicketMessageRepository;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/tickets')]
final class AdminTicketController
{
    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly TicketMessageRepository $ticketMessageRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_tickets', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $tickets = $this->ticketRepository->findBy([], ['lastMessageAt' => 'DESC']);

        return new Response($this->twig->render('admin/tickets/index.html.twig', [
            'tickets' => $this->normalizeTickets($tickets),
            'summary' => $this->buildSummary($tickets),
            'form' => $this->buildFormContext(),
            'customers' => $this->userRepository->findBy(['type' => UserType::Customer], ['email' => 'ASC']),
            'statusStyles' => $this->statusStyles(),
            'priorityStyles' => $this->priorityStyles(),
            'activeNav' => 'tickets',
        ]));
    }

    #[Route(path: '/table', name: 'admin_tickets_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $tickets = $this->ticketRepository->findBy([], ['lastMessageAt' => 'DESC']);

        return new Response($this->twig->render('admin/tickets/_table.html.twig', [
            'tickets' => $this->normalizeTickets($tickets),
            'statusStyles' => $this->statusStyles(),
            'priorityStyles' => $this->priorityStyles(),
        ]));
    }

    #[Route(path: '/form', name: 'admin_tickets_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/tickets/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'customers' => $this->userRepository->findBy(['type' => UserType::Customer], ['email' => 'ASC']),
        ]));
    }

    #[Route(path: '', name: 'admin_tickets_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $formData = $this->parsePayload($request);
        $customers = $this->userRepository->findBy(['type' => UserType::Customer], ['email' => 'ASC']);

        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, $customers, Response::HTTP_BAD_REQUEST);
        }

        $ticket = new Ticket(
            $formData['customer'],
            $formData['subject'],
            $formData['category'],
            $formData['priority'],
        );

        $message = new TicketMessage($ticket, $actor, $formData['message']);
        $ticket->noteMessage();

        $this->entityManager->persist($ticket);
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'ticket.created', [
            'ticket_id' => $ticket->getId(),
            'customer_id' => $ticket->getCustomer()->getId(),
            'subject' => $ticket->getSubject(),
            'category' => $ticket->getCategory()->value,
            'priority' => $ticket->getPriority()->value,
        ]);
        $this->auditLogger->log($actor, 'ticket.message.created', [
            'ticket_id' => $ticket->getId(),
            'message_id' => $message->getId(),
            'author_id' => $actor->getId(),
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/tickets/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'customers' => $customers,
        ]));
        $response->headers->set('HX-Trigger', 'tickets-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_ticket_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $ticket = $this->ticketRepository->find($id);
        if ($ticket === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $messages = $this->ticketMessageRepository->findByTicket($ticket);

        return new Response($this->twig->render('admin/tickets/show.html.twig', [
            'ticket' => $this->normalizeTicket($ticket),
            'messages' => $this->normalizeMessages($messages),
            'statusStyles' => $this->statusStyles(),
            'priorityStyles' => $this->priorityStyles(),
            'activeNav' => 'tickets',
        ]));
    }

    #[Route(path: '/{id}/messages', name: 'admin_ticket_messages_create', methods: ['POST'])]
    public function addMessage(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $ticket = $this->ticketRepository->find($id);
        if ($ticket === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $messageBody = trim((string) $request->request->get('message', ''));
        if ($messageBody === '') {
            return new Response('Message is required.', Response::HTTP_BAD_REQUEST);
        }

        $message = new TicketMessage($ticket, $actor, $messageBody);
        $ticket->noteMessage();

        $this->entityManager->persist($message);
        $this->auditLogger->log($actor, 'ticket.message.created', [
            'ticket_id' => $ticket->getId(),
            'message_id' => $message->getId(),
            'author_id' => $actor->getId(),
        ]);
        $this->entityManager->flush();

        return new Response($this->twig->render('admin/tickets/_messages.html.twig', [
            'ticket' => $this->normalizeTicket($ticket),
            'messages' => $this->normalizeMessages($this->ticketMessageRepository->findByTicket($ticket)),
            'statusStyles' => $this->statusStyles(),
            'priorityStyles' => $this->priorityStyles(),
        ]));
    }

    #[Route(path: '/{id}/status', name: 'admin_ticket_status_update', methods: ['POST'])]
    public function updateStatus(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $ticket = $this->ticketRepository->find($id);
        if ($ticket === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $statusValue = strtolower(trim((string) $request->request->get('status', '')));
        $status = TicketStatus::tryFrom($statusValue);
        if ($status === null) {
            return new Response('Invalid status.', Response::HTTP_BAD_REQUEST);
        }

        $ticket->setStatus($status);
        $this->auditLogger->log($actor, 'ticket.status.updated', [
            'ticket_id' => $ticket->getId(),
            'status' => $ticket->getStatus()->value,
        ]);
        $this->entityManager->flush();

        return new Response($this->twig->render('admin/tickets/_status_badge.html.twig', [
            'ticket' => $this->normalizeTicket($ticket),
            'statusStyles' => $this->statusStyles(),
        ]));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->getType() === UserType::Admin;
    }

    private function buildSummary(array $tickets): array
    {
        $open = 0;
        $pending = 0;
        $resolved = 0;
        $closed = 0;

        foreach ($tickets as $ticket) {
            match ($ticket->getStatus()) {
                TicketStatus::Open => $open++,
                TicketStatus::Pending => $pending++,
                TicketStatus::Resolved => $resolved++,
                TicketStatus::Closed => $closed++,
            };
        }

        return [
            'total' => count($tickets),
            'open' => $open,
            'pending' => $pending,
            'resolved' => $resolved,
            'closed' => $closed,
        ];
    }

    private function normalizeTickets(array $tickets): array
    {
        return array_map(fn (Ticket $ticket) => $this->normalizeTicket($ticket), $tickets);
    }

    private function normalizeTicket(Ticket $ticket): array
    {
        return [
            'id' => $ticket->getId(),
            'subject' => $ticket->getSubject(),
            'category' => $ticket->getCategory()->value,
            'status' => $ticket->getStatus()->value,
            'priority' => $ticket->getPriority()->value,
            'customer' => [
                'id' => $ticket->getCustomer()->getId(),
                'email' => $ticket->getCustomer()->getEmail(),
            ],
            'created_at' => $ticket->getCreatedAt(),
            'last_message_at' => $ticket->getLastMessageAt(),
        ];
    }

    private function normalizeMessages(array $messages): array
    {
        return array_map(static fn (TicketMessage $message) => [
            'id' => $message->getId(),
            'author' => [
                'id' => $message->getAuthor()->getId(),
                'email' => $message->getAuthor()->getEmail(),
                'type' => $message->getAuthor()->getType()->value,
            ],
            'body' => $message->getBody(),
            'created_at' => $message->getCreatedAt(),
        ], $messages);
    }

    private function buildFormContext(?array $overrides = null): array
    {
        $defaults = [
            'errors' => [],
            'customer_id' => '',
            'subject' => '',
            'category' => TicketCategory::General->value,
            'priority' => TicketPriority::Normal->value,
            'message' => '',
        ];

        return array_merge($defaults, $overrides ?? []);
    }

    private function parsePayload(Request $request): array
    {
        $errors = [];
        $customerId = trim((string) $request->request->get('customer_id', ''));
        $subject = trim((string) $request->request->get('subject', ''));
        $categoryValue = strtolower(trim((string) $request->request->get('category', '')));
        $priorityValue = strtolower(trim((string) $request->request->get('priority', '')));
        $message = trim((string) $request->request->get('message', ''));

        if ($customerId === '' || !is_numeric($customerId)) {
            $errors[] = 'Customer is required.';
        }
        if ($subject === '') {
            $errors[] = 'Subject is required.';
        }
        if ($message === '') {
            $errors[] = 'Message is required.';
        }

        $category = TicketCategory::tryFrom($categoryValue);
        if ($category === null) {
            $errors[] = 'Category is required.';
        }

        $priority = TicketPriority::tryFrom($priorityValue);
        if ($priority === null) {
            $errors[] = 'Priority is required.';
        }

        $customer = null;
        if ($customerId !== '' && is_numeric($customerId)) {
            $customer = $this->userRepository->find((int) $customerId);
            if ($customer === null || $customer->getType() !== UserType::Customer) {
                $errors[] = 'Customer not found.';
            }
        }

        return [
            'errors' => $errors,
            'customer' => $customer,
            'customer_id' => $customerId,
            'subject' => $subject,
            'category' => $category ?? TicketCategory::General,
            'priority' => $priority ?? TicketPriority::Normal,
            'message' => $message,
        ];
    }

    private function renderFormWithErrors(array $formData, array $customers, int $status): Response
    {
        return new Response($this->twig->render('admin/tickets/_form.html.twig', [
            'form' => $this->buildFormContext($formData),
            'customers' => $customers,
        ]), $status);
    }

    private function statusStyles(): array
    {
        return [
            TicketStatus::Open->value => 'border-amber-200 bg-amber-50 text-amber-700',
            TicketStatus::Pending->value => 'border-indigo-200 bg-indigo-50 text-indigo-700',
            TicketStatus::Resolved->value => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            TicketStatus::Closed->value => 'border-slate-200 bg-slate-100 text-slate-600',
        ];
    }

    private function priorityStyles(): array
    {
        return [
            TicketPriority::Low->value => 'border-slate-200 bg-slate-50 text-slate-600',
            TicketPriority::Normal->value => 'border-sky-200 bg-sky-50 text-sky-700',
            TicketPriority::High->value => 'border-orange-200 bg-orange-50 text-orange-700',
            TicketPriority::Urgent->value => 'border-rose-200 bg-rose-50 text-rose-700',
        ];
    }
}
