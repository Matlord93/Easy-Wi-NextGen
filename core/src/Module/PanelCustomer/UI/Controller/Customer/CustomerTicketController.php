<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Ticket;
use App\Module\Core\Domain\Entity\TicketMessage;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\TicketCategory;
use App\Module\Core\Domain\Enum\TicketPriority;
use App\Module\Core\Domain\Enum\TicketStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\TicketMessageRepository;
use App\Repository\TicketRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/tickets')]
final class CustomerTicketController
{
    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly TicketMessageRepository $ticketMessageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notificationService,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_tickets', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        $tickets = $this->ticketRepository->findByCustomer($customer);

        return new Response($this->twig->render('customer/tickets/index.html.twig', [
            'tickets' => $this->normalizeTickets($tickets),
            'summary' => $this->buildSummary($tickets),
            'form' => $this->buildFormContext(),
            'statusStyles' => $this->statusStyles(),
            'priorityStyles' => $this->priorityStyles(),
            'activeNav' => 'tickets',
        ]));
    }

    #[Route(path: '/table', name: 'customer_tickets_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $tickets = $this->ticketRepository->findByCustomer($customer);

        return new Response($this->twig->render('customer/tickets/_table.html.twig', [
            'tickets' => $this->normalizeTickets($tickets),
            'statusStyles' => $this->statusStyles(),
            'priorityStyles' => $this->priorityStyles(),
        ]));
    }

    #[Route(path: '/form', name: 'customer_tickets_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        $this->requireCustomer($request);

        return new Response($this->twig->render('customer/tickets/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '', name: 'customer_tickets_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $this->requireCustomer($request);

        $formData = $this->parsePayload($request);

        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $ticket = new Ticket(
            $actor,
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
        $this->notificationService->notifyAdmins(
            sprintf('ticket.created.%s', $ticket->getId()),
            sprintf('New ticket · #%s', $ticket->getId()),
            sprintf('%s · %s', $ticket->getCustomer()->getEmail(), $ticket->getSubject()),
            'tickets',
            '/admin/tickets',
        );
        $this->entityManager->flush();

        $response = new Response($this->twig->render('customer/tickets/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'tickets-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'customer_ticket_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $ticket = $this->ticketRepository->find($id);
        if ($ticket === null || $ticket->getCustomer()->getId() !== $customer->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $messages = $this->ticketMessageRepository->findByTicket($ticket);

        return new Response($this->twig->render('customer/tickets/show.html.twig', [
            'ticket' => $this->normalizeTicket($ticket),
            'messages' => $this->normalizeMessages($messages),
            'statusStyles' => $this->statusStyles(),
            'priorityStyles' => $this->priorityStyles(),
            'activeNav' => 'tickets',
        ]));
    }

    #[Route(path: '/{id}/messages', name: 'customer_ticket_messages_create', methods: ['POST'])]
    public function addMessage(Request $request, int $id): Response
    {
        $actor = $this->requireCustomer($request);
        $ticket = $this->ticketRepository->find($id);
        if ($ticket === null || $ticket->getCustomer()->getId() !== $actor->getId()) {
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
        $this->notificationService->notifyAdmins(
            sprintf('ticket.message.%s.%s', $ticket->getId(), $message->getId()),
            sprintf('Ticket reply · #%s', $ticket->getId()),
            sprintf('%s replied', $actor->getEmail()),
            'tickets',
            '/admin/tickets',
        );
        $this->entityManager->flush();

        return new Response($this->twig->render('customer/tickets/_messages.html.twig', [
            'ticket' => $this->normalizeTicket($ticket),
            'messages' => $this->normalizeMessages($this->ticketMessageRepository->findByTicket($ticket)),
            'statusStyles' => $this->statusStyles(),
            'priorityStyles' => $this->priorityStyles(),
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
        $subject = trim((string) $request->request->get('subject', ''));
        $categoryValue = strtolower(trim((string) $request->request->get('category', '')));
        $priorityValue = strtolower(trim((string) $request->request->get('priority', '')));
        $message = trim((string) $request->request->get('message', ''));

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

        return [
            'errors' => $errors,
            'subject' => $subject,
            'category' => $category ?? TicketCategory::General,
            'priority' => $priority ?? TicketPriority::Normal,
            'message' => $message,
        ];
    }

    private function renderFormWithErrors(array $formData, int $status): Response
    {
        return new Response($this->twig->render('customer/tickets/_form.html.twig', [
            'form' => $this->buildFormContext($formData),
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
