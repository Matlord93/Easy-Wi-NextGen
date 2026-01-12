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
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TicketApiController
{
    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly TicketMessageRepository $ticketMessageRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notificationService,
    ) {
    }

    #[Route(path: '/api/tickets', name: 'tickets_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/tickets', name: 'tickets_list_v1', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);

        $tickets = $actor->getType() === UserType::Admin
            ? $this->ticketRepository->findBy([], ['lastMessageAt' => 'DESC'])
            : $this->ticketRepository->findByCustomer($actor);

        return new JsonResponse([
            'tickets' => array_map(fn (Ticket $ticket) => $this->normalizeTicket($ticket), $tickets),
        ]);
    }

    #[Route(path: '/api/tickets', name: 'tickets_create', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/tickets', name: 'tickets_create_v1', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);
        $payload = $this->parseJsonPayload($request);

        $formData = $this->validateCreatePayload($actor, $payload);
        if ($formData['error'] instanceof JsonResponse) {
            return $formData['error'];
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
        if ($actor->getType() === UserType::Customer) {
            $this->notificationService->notifyAdmins(
                sprintf('ticket.created.%s', $ticket->getId()),
                sprintf('New ticket · #%s', $ticket->getId()),
                sprintf('%s · %s', $ticket->getCustomer()->getEmail(), $ticket->getSubject()),
                'tickets',
                '/admin/tickets',
            );
        } elseif ($actor->getType() === UserType::Admin) {
            $this->notificationService->notify(
                $ticket->getCustomer(),
                sprintf('ticket.created.%s', $ticket->getId()),
                sprintf('Ticket opened · #%s', $ticket->getId()),
                $ticket->getSubject(),
                'tickets',
                '/tickets',
            );
        }
        $this->entityManager->flush();

        return new JsonResponse([
            'ticket' => $this->normalizeTicket($ticket),
            'message' => $this->normalizeMessage($message),
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/tickets/{id}/messages', name: 'tickets_messages_create', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/tickets/{id}/messages', name: 'tickets_messages_create_v1', methods: ['POST'])]
    public function addMessage(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $ticket = $this->ticketRepository->find($id);
        if ($ticket === null) {
            return new JsonResponse(['error' => 'Ticket not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessTicket($actor, $ticket)) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $payload = $this->parseJsonPayload($request);
        $messageBody = trim((string) ($payload['message'] ?? ''));
        if ($messageBody === '') {
            return new JsonResponse(['error' => 'Message is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $message = new TicketMessage($ticket, $actor, $messageBody);
        $ticket->noteMessage();

        $this->entityManager->persist($message);
        $this->auditLogger->log($actor, 'ticket.message.created', [
            'ticket_id' => $ticket->getId(),
            'message_id' => $message->getId(),
            'author_id' => $actor->getId(),
        ]);
        if ($actor->getType() === UserType::Customer) {
            $this->notificationService->notifyAdmins(
                sprintf('ticket.message.%s.%s', $ticket->getId(), $message->getId()),
                sprintf('Ticket reply · #%s', $ticket->getId()),
                sprintf('%s replied', $actor->getEmail()),
                'tickets',
                '/admin/tickets',
            );
        } elseif ($actor->getType() === UserType::Admin) {
            $this->notificationService->notify(
                $ticket->getCustomer(),
                sprintf('ticket.message.%s.%s', $ticket->getId(), $message->getId()),
                sprintf('Reply on ticket · #%s', $ticket->getId()),
                'An operator replied to your ticket.',
                'tickets',
                '/tickets',
            );
        }
        $this->entityManager->flush();

        return new JsonResponse([
            'ticket' => $this->normalizeTicket($ticket),
            'message' => $this->normalizeMessage($message),
        ]);
    }

    #[Route(path: '/api/tickets/{id}/status', name: 'tickets_status_update', methods: ['PATCH'])]
    #[Route(path: '/api/v1/customer/tickets/{id}/status', name: 'tickets_status_update_v1', methods: ['PATCH'])]
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        if ($actor->getType() !== UserType::Admin) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $ticket = $this->ticketRepository->find($id);
        if ($ticket === null) {
            return new JsonResponse(['error' => 'Ticket not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $this->parseJsonPayload($request);
        $statusValue = strtolower(trim((string) ($payload['status'] ?? '')));
        $status = TicketStatus::tryFrom($statusValue);
        if ($status === null) {
            return new JsonResponse(['error' => 'Invalid status.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $ticket->setStatus($status);

        $this->auditLogger->log($actor, 'ticket.status.updated', [
            'ticket_id' => $ticket->getId(),
            'status' => $ticket->getStatus()->value,
        ]);
        $this->entityManager->flush();

        return new JsonResponse([
            'ticket' => $this->normalizeTicket($ticket),
        ]);
    }

    #[Route(path: '/api/tickets/{id}/messages', name: 'tickets_messages_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/tickets/{id}/messages', name: 'tickets_messages_list_v1', methods: ['GET'])]
    public function listMessages(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $ticket = $this->ticketRepository->find($id);
        if ($ticket === null) {
            return new JsonResponse(['error' => 'Ticket not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessTicket($actor, $ticket)) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $messages = $this->ticketMessageRepository->findByTicket($ticket);

        return new JsonResponse([
            'ticket' => $this->normalizeTicket($ticket),
            'messages' => array_map(fn (TicketMessage $message) => $this->normalizeMessage($message), $messages),
        ]);
    }

    private function requireUser(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function parseJsonPayload(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\JsonException $exception) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid JSON payload.', $exception);
        }
    }

    private function validateCreatePayload(User $actor, array $payload): array
    {
        $subject = trim((string) ($payload['subject'] ?? ''));
        $categoryValue = strtolower(trim((string) ($payload['category'] ?? '')));
        $priorityValue = strtolower(trim((string) ($payload['priority'] ?? '')));
        $message = trim((string) ($payload['message'] ?? ''));
        $customerId = $payload['customer_id'] ?? null;

        if ($subject === '') {
            return ['error' => new JsonResponse(['error' => 'Subject is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $category = TicketCategory::tryFrom($categoryValue);
        if ($category === null) {
            return ['error' => new JsonResponse(['error' => 'Category is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $priority = TicketPriority::tryFrom($priorityValue);
        if ($priority === null) {
            return ['error' => new JsonResponse(['error' => 'Priority is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($message === '') {
            return ['error' => new JsonResponse(['error' => 'Message is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $customer = $actor;
        if ($actor->getType() === UserType::Admin) {
            if (!is_numeric($customerId)) {
                return ['error' => new JsonResponse(['error' => 'Customer is required.'], JsonResponse::HTTP_BAD_REQUEST)];
            }
            $customer = $this->userRepository->find((int) $customerId);
            if ($customer === null || $customer->getType() !== UserType::Customer) {
                return ['error' => new JsonResponse(['error' => 'Customer not found.'], JsonResponse::HTTP_BAD_REQUEST)];
            }
        }

        if ($customer->getType() !== UserType::Customer) {
            return ['error' => new JsonResponse(['error' => 'Invalid customer.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        return [
            'error' => null,
            'customer' => $customer,
            'subject' => $subject,
            'category' => $category,
            'priority' => $priority,
            'message' => $message,
        ];
    }

    private function normalizeTicket(Ticket $ticket): array
    {
        return [
            'id' => $ticket->getId(),
            'subject' => $ticket->getSubject(),
            'category' => $ticket->getCategory()->value,
            'priority' => $ticket->getPriority()->value,
            'status' => $ticket->getStatus()->value,
            'customer' => [
                'id' => $ticket->getCustomer()->getId(),
                'email' => $ticket->getCustomer()->getEmail(),
            ],
            'created_at' => $ticket->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $ticket->getUpdatedAt()->format(DATE_ATOM),
            'last_message_at' => $ticket->getLastMessageAt()->format(DATE_ATOM),
        ];
    }

    private function normalizeMessage(TicketMessage $message): array
    {
        return [
            'id' => $message->getId(),
            'ticket_id' => $message->getTicket()->getId(),
            'author' => [
                'id' => $message->getAuthor()->getId(),
                'email' => $message->getAuthor()->getEmail(),
                'type' => $message->getAuthor()->getType()->value,
            ],
            'body' => $message->getBody(),
            'created_at' => $message->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    private function canAccessTicket(User $actor, Ticket $ticket): bool
    {
        return $actor->getType() === UserType::Admin || $ticket->getCustomer()->getId() === $actor->getId();
    }
}
