<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\Ticket;
use App\Module\Core\Domain\Entity\TicketMessage;
use App\Module\Core\Domain\Entity\TicketQuickReply;
use App\Module\Core\Domain\Entity\TicketTemplate;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\TicketCategory;
use App\Module\Core\Domain\Enum\TicketPriority;
use App\Module\Core\Domain\Enum\TicketStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\TicketMessageRepository;
use App\Repository\TicketQuickReplyRepository;
use App\Repository\TicketRepository;
use App\Repository\TicketTemplateRepository;
use App\Repository\UserRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\NotificationService;
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
        private readonly TicketTemplateRepository $ticketTemplateRepository,
        private readonly TicketQuickReplyRepository $ticketQuickReplyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notificationService,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_tickets', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return $this->renderIndex($admin, $request);
    }

    #[Route(path: '/table', name: 'admin_tickets_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->requireAdmin($request)) {
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
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/tickets/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'customers' => $this->userRepository->findBy(['type' => UserType::Customer], ['email' => 'ASC']),
            'templates' => $this->normalizeTemplates($this->ticketTemplateRepository->findByAdmin($admin)),
            'adminSignature' => $admin->getAdminSignature(),
        ]));
    }

    #[Route(path: '', name: 'admin_tickets_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $formData = $this->parsePayload($request);
        $customers = $this->userRepository->findBy(['type' => UserType::Customer], ['email' => 'ASC']);
        $templates = $this->ticketTemplateRepository->findByAdmin($actor);

        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, $customers, $templates, $actor, Response::HTTP_BAD_REQUEST);
        }

        $includeSignature = $request->request->get('include_signature') === '1';
        $messageBody = $this->applySignature($actor, $formData['message'], $includeSignature);

        $ticket = new Ticket(
            $formData['customer'],
            $formData['subject'],
            $formData['category'],
            $formData['priority'],
        );

        $message = new TicketMessage($ticket, $actor, $messageBody);
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
        $this->notificationService->notify(
            $ticket->getCustomer(),
            sprintf('ticket.created.%s', $ticket->getId()),
            sprintf('Ticket opened · #%s', $ticket->getId()),
            $ticket->getSubject(),
            'tickets',
            '/tickets',
        );
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/tickets/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'customers' => $customers,
            'templates' => $this->normalizeTemplates($templates),
            'adminSignature' => $actor->getAdminSignature(),
        ]));
        $response->headers->set('HX-Trigger', 'tickets-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_ticket_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
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
            'quickReplies' => $this->normalizeQuickReplies($this->ticketQuickReplyRepository->findByAdmin($admin)),
            'adminSignature' => $admin->getAdminSignature(),
            'activeNav' => 'tickets',
        ]));
    }

    #[Route(path: '/{id}/messages', name: 'admin_ticket_messages_create', methods: ['POST'])]
    public function addMessage(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
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

        $includeSignature = $request->request->get('include_signature') === '1';
        $messageBody = $this->applySignature($actor, $messageBody, $includeSignature);

        $message = new TicketMessage($ticket, $actor, $messageBody);
        $ticket->noteMessage();

        $this->entityManager->persist($message);
        $this->auditLogger->log($actor, 'ticket.message.created', [
            'ticket_id' => $ticket->getId(),
            'message_id' => $message->getId(),
            'author_id' => $actor->getId(),
        ]);
        $this->notificationService->notify(
            $ticket->getCustomer(),
            sprintf('ticket.message.%s.%s', $ticket->getId(), $message->getId()),
            sprintf('Reply on ticket · #%s', $ticket->getId()),
            'An operator replied to your ticket.',
            'tickets',
            '/tickets',
        );
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
        if (!$actor instanceof User || !$actor->isAdmin()) {
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

    #[Route(path: '/templates', name: 'admin_ticket_templates_create', methods: ['POST'])]
    public function createTemplate(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $payload = $this->parseTemplatePayload($request);
        if ($payload['errors'] !== []) {
            return $this->renderIndex($admin, $request, [
                'templateErrors' => $payload['errors'],
                'templateForm' => $payload['form'],
            ], Response::HTTP_BAD_REQUEST);
        }

        $template = new TicketTemplate(
            $admin,
            $payload['form']['title'],
            $payload['form']['subject'],
            TicketCategory::from($payload['form']['category']),
            TicketPriority::from($payload['form']['priority']),
            $payload['form']['body'],
        );
        $this->entityManager->persist($template);
        $this->entityManager->flush();

        $this->auditLogger->log($admin, 'ticket.template.created', [
            'template_id' => $template->getId(),
            'title' => $template->getTitle(),
        ]);

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/admin/tickets?template_created=' . $template->getId()]);
    }

    #[Route(path: '/templates/{id}', name: 'admin_ticket_templates_update', methods: ['POST'])]
    public function updateTemplate(Request $request, int $id): Response
    {
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $template = $this->ticketTemplateRepository->find($id);
        if ($template === null || $template->getAdmin()->getId() !== $admin->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $payload = $this->parseTemplatePayload($request);
        if ($payload['errors'] !== []) {
            return $this->renderIndex($admin, $request, [
                'templateErrors' => $payload['errors'],
                'templateForm' => $payload['form'],
            ], Response::HTTP_BAD_REQUEST);
        }

        $template->setTitle($payload['form']['title']);
        $template->setSubject($payload['form']['subject']);
        $template->setCategory(TicketCategory::from($payload['form']['category']));
        $template->setPriority(TicketPriority::from($payload['form']['priority']));
        $template->setBody($payload['form']['body']);

        $this->auditLogger->log($admin, 'ticket.template.updated', [
            'template_id' => $template->getId(),
            'title' => $template->getTitle(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/admin/tickets?template_updated=' . $template->getId()]);
    }

    #[Route(path: '/templates/{id}/delete', name: 'admin_ticket_templates_delete', methods: ['POST'])]
    public function deleteTemplate(Request $request, int $id): Response
    {
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $template = $this->ticketTemplateRepository->find($id);
        if ($template === null || $template->getAdmin()->getId() !== $admin->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($template);
        $this->entityManager->flush();

        $this->auditLogger->log($admin, 'ticket.template.deleted', [
            'template_id' => $id,
        ]);

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/admin/tickets?template_deleted=' . $id]);
    }

    #[Route(path: '/quick-replies', name: 'admin_ticket_quick_replies_create', methods: ['POST'])]
    public function createQuickReply(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $payload = $this->parseQuickReplyPayload($request);
        if ($payload['errors'] !== []) {
            return $this->renderIndex($admin, $request, [
                'quickReplyErrors' => $payload['errors'],
                'quickReplyForm' => $payload['form'],
            ], Response::HTTP_BAD_REQUEST);
        }

        $quickReply = new TicketQuickReply(
            $admin,
            $payload['form']['title'],
            $payload['form']['body'],
        );
        $this->entityManager->persist($quickReply);
        $this->entityManager->flush();

        $this->auditLogger->log($admin, 'ticket.quick_reply.created', [
            'quick_reply_id' => $quickReply->getId(),
            'title' => $quickReply->getTitle(),
        ]);

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/admin/tickets?quick_reply_created=' . $quickReply->getId()]);
    }

    #[Route(path: '/quick-replies/{id}', name: 'admin_ticket_quick_replies_update', methods: ['POST'])]
    public function updateQuickReply(Request $request, int $id): Response
    {
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $quickReply = $this->ticketQuickReplyRepository->find($id);
        if ($quickReply === null || $quickReply->getAdmin()->getId() !== $admin->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $payload = $this->parseQuickReplyPayload($request);
        if ($payload['errors'] !== []) {
            return $this->renderIndex($admin, $request, [
                'quickReplyErrors' => $payload['errors'],
                'quickReplyForm' => $payload['form'],
            ], Response::HTTP_BAD_REQUEST);
        }

        $quickReply->setTitle($payload['form']['title']);
        $quickReply->setBody($payload['form']['body']);

        $this->auditLogger->log($admin, 'ticket.quick_reply.updated', [
            'quick_reply_id' => $quickReply->getId(),
            'title' => $quickReply->getTitle(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/admin/tickets?quick_reply_updated=' . $quickReply->getId()]);
    }

    #[Route(path: '/quick-replies/{id}/delete', name: 'admin_ticket_quick_replies_delete', methods: ['POST'])]
    public function deleteQuickReply(Request $request, int $id): Response
    {
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $quickReply = $this->ticketQuickReplyRepository->find($id);
        if ($quickReply === null || $quickReply->getAdmin()->getId() !== $admin->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($quickReply);
        $this->entityManager->flush();

        $this->auditLogger->log($admin, 'ticket.quick_reply.deleted', [
            'quick_reply_id' => $id,
        ]);

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/admin/tickets?quick_reply_deleted=' . $id]);
    }

    private function requireAdmin(Request $request): ?User
    {
        $actor = $request->attributes->get('current_user');

        if (!$actor instanceof User || !$actor->isAdmin()) {
            return null;
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

    private function normalizeTemplates(array $templates): array
    {
        return array_map(static fn (TicketTemplate $template): array => [
            'id' => $template->getId(),
            'title' => $template->getTitle(),
            'subject' => $template->getSubject(),
            'category' => $template->getCategory()->value,
            'priority' => $template->getPriority()->value,
            'body' => $template->getBody(),
            'updated_at' => $template->getUpdatedAt(),
        ], $templates);
    }

    private function normalizeQuickReplies(array $quickReplies): array
    {
        return array_map(static fn (TicketQuickReply $quickReply): array => [
            'id' => $quickReply->getId(),
            'title' => $quickReply->getTitle(),
            'body' => $quickReply->getBody(),
            'updated_at' => $quickReply->getUpdatedAt(),
        ], $quickReplies);
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
            'include_signature' => true,
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
            'include_signature' => $request->request->get('include_signature') === '1',
        ];
    }

    private function renderFormWithErrors(array $formData, array $customers, array $templates, User $actor, int $status): Response
    {
        return new Response($this->twig->render('admin/tickets/_form.html.twig', [
            'form' => $this->buildFormContext($formData),
            'customers' => $customers,
            'templates' => $this->normalizeTemplates($templates),
            'adminSignature' => $actor->getAdminSignature(),
        ]), $status);
    }

    private function renderIndex(User $admin, Request $request, array $overrides = [], int $status = Response::HTTP_OK): Response
    {
        $tickets = $this->ticketRepository->findBy([], ['lastMessageAt' => 'DESC']);
        $templates = $this->ticketTemplateRepository->findByAdmin($admin);
        $quickReplies = $this->ticketQuickReplyRepository->findByAdmin($admin);

        $success = [];
        foreach (['template_created', 'template_updated', 'template_deleted', 'quick_reply_created', 'quick_reply_updated', 'quick_reply_deleted'] as $key) {
            $value = $request->query->get($key);
            if ($value !== null) {
                $success[] = sprintf('%s %s.', str_replace('_', ' ', ucfirst($key)), $value);
            }
        }

        return new Response($this->twig->render('admin/tickets/index.html.twig', array_merge([
            'tickets' => $this->normalizeTickets($tickets),
            'summary' => $this->buildSummary($tickets),
            'form' => $this->buildFormContext(),
            'customers' => $this->userRepository->findBy(['type' => UserType::Customer], ['email' => 'ASC']),
            'statusStyles' => $this->statusStyles(),
            'priorityStyles' => $this->priorityStyles(),
            'templates' => $this->normalizeTemplates($templates),
            'quickReplies' => $this->normalizeQuickReplies($quickReplies),
            'templateForm' => [
                'title' => '',
                'subject' => '',
                'category' => TicketCategory::General->value,
                'priority' => TicketPriority::Normal->value,
                'body' => '',
            ],
            'quickReplyForm' => [
                'title' => '',
                'body' => '',
            ],
            'templateErrors' => [],
            'quickReplyErrors' => [],
            'success' => $success,
            'adminSignature' => $admin->getAdminSignature(),
            'activeNav' => 'tickets',
        ], $overrides)), $status);
    }

    private function parseTemplatePayload(Request $request): array
    {
        $title = trim((string) $request->request->get('title', ''));
        $subject = trim((string) $request->request->get('subject', ''));
        $categoryValue = strtolower(trim((string) $request->request->get('category', '')));
        $priorityValue = strtolower(trim((string) $request->request->get('priority', '')));
        $body = trim((string) $request->request->get('body', ''));

        $errors = [];
        if ($title === '') {
            $errors[] = 'Template title is required.';
        }
        if ($subject === '') {
            $errors[] = 'Template subject is required.';
        }
        if ($body === '') {
            $errors[] = 'Template body is required.';
        }

        $category = TicketCategory::tryFrom($categoryValue);
        if ($category === null) {
            $errors[] = 'Template category is required.';
            $category = TicketCategory::General;
        }

        $priority = TicketPriority::tryFrom($priorityValue);
        if ($priority === null) {
            $errors[] = 'Template priority is required.';
            $priority = TicketPriority::Normal;
        }

        return [
            'errors' => $errors,
            'form' => [
                'title' => $title,
                'subject' => $subject,
                'category' => $category->value,
                'priority' => $priority->value,
                'body' => $body,
            ],
        ];
    }

    private function parseQuickReplyPayload(Request $request): array
    {
        $title = trim((string) $request->request->get('title', ''));
        $body = trim((string) $request->request->get('body', ''));

        $errors = [];
        if ($title === '') {
            $errors[] = 'Quick reply title is required.';
        }
        if ($body === '') {
            $errors[] = 'Quick reply body is required.';
        }

        return [
            'errors' => $errors,
            'form' => [
                'title' => $title,
                'body' => $body,
            ],
        ];
    }

    private function applySignature(User $admin, string $message, bool $includeSignature): string
    {
        if (!$includeSignature) {
            return $message;
        }

        $signature = trim((string) $admin->getAdminSignature());
        if ($signature === '') {
            return $message;
        }

        return $message . "\n\n--\n" . $signature;
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
