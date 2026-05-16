<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\MailService;
use App\Module\Core\Application\NotificationService;
use App\Module\Core\Domain\Entity\Ticket;
use App\Module\Core\Domain\Entity\TicketAttachment;
use App\Module\Core\Domain\Entity\TicketMessage;
use App\Module\Core\Domain\Entity\TicketQuickReply;
use App\Module\Core\Domain\Entity\TicketTemplate;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\TicketCategory;
use App\Module\Core\Domain\Enum\TicketPriority;
use App\Module\Core\Domain\Enum\TicketStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\TicketAttachmentRepository;
use App\Repository\TicketMessageRepository;
use App\Repository\TicketQuickReplyRepository;
use App\Repository\TicketRepository;
use App\Repository\TicketTemplateRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route(path: '/admin/tickets')]
final class AdminTicketController
{
    private const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;
    private const ALLOWED_ATTACHMENT_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
        'application/zip',
    ];
    private const BLOCKED_ATTACHMENT_EXTENSIONS = [
        'bat',
        'cmd',
        'exe',
        'htm',
        'html',
        'js',
        'php',
        'sh',
        'svg',
    ];
    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly TicketMessageRepository $ticketMessageRepository,
        private readonly TicketAttachmentRepository $ticketAttachmentRepository,
        #[Autowire(service: 'default.storage')] private readonly FilesystemOperator $storage,
        private readonly UserRepository $userRepository,
        private readonly TicketTemplateRepository $ticketTemplateRepository,
        private readonly TicketQuickReplyRepository $ticketQuickReplyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notificationService,
        private readonly MailService $mailService,
        private readonly AppSettingsService $appSettingsService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
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
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }
        $tickets = $this->applyTicketFilters($this->ticketRepository->findVisibleForAdminQueue($admin), $request, $admin);

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
        if (!$this->isCsrfValid($request, 'admin_ticket_create')) {
            return new Response('Invalid CSRF token.', Response::HTTP_UNAUTHORIZED);
        }

        $formData = $this->parsePayload($request);
        $attachmentFiles = $this->attachmentFiles($request);
        $attachmentError = $this->validateAttachmentFiles($attachmentFiles);
        if ($attachmentError !== null) {
            $formData['errors'][] = $attachmentError;
        }
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
        $this->persistAttachments($ticket, $message, $actor, $attachmentFiles);
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

        $customer = $ticket->getCustomer();
        $this->mailService->sendTemplate(
            $customer->getEmail(),
            'ticket_opened',
            [
                'ticket_id'         => $ticket->getId(),
                'ticket_subject'    => $ticket->getSubject(),
                'ticket_category'   => ucfirst(strtolower($ticket->getCategory()->value)),
                'ticket_priority'   => ucfirst(strtolower($ticket->getPriority()->value)),
                'ticket_created_at' => $ticket->getCreatedAt()->format('d.m.Y H:i'),
                'ticket_message'    => $message->getBody(),
                'ticket_url'        => '/tickets/' . $ticket->getId(),
                'customer_name'     => $customer->getName() ?? $customer->getEmail(),
                'customer_email'    => $customer->getEmail(),
            ],
            null,
            true,
        );

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
            'staffUsers' => $this->staffUsers(),
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
        if (!$this->isCsrfValid($request, 'admin_ticket_message_' . $id)) {
            return new Response('Invalid CSRF token.', Response::HTTP_UNAUTHORIZED);
        }

        $ticket = $this->ticketRepository->find($id);
        if ($ticket === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $attachmentFiles = $this->attachmentFiles($request);
        $attachmentError = $this->validateAttachmentFiles($attachmentFiles);
        if ($attachmentError !== null) {
            return new Response($attachmentError, Response::HTTP_BAD_REQUEST);
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
        $this->persistAttachments($ticket, $message, $actor, $attachmentFiles);
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

        $customer = $ticket->getCustomer();
        $this->mailService->sendTemplate(
            $customer->getEmail(),
            'ticket_reply_customer',
            [
                'ticket_id'      => $ticket->getId(),
                'ticket_subject' => $ticket->getSubject(),
                'reply_message'  => $message->getBody(),
                'replied_by'     => $actor->getName() ?? $actor->getEmail(),
                'replied_at'     => $message->getCreatedAt()->format('d.m.Y H:i'),
                'ticket_url'     => '/tickets/' . $ticket->getId(),
                'customer_name'  => $customer->getName() ?? $customer->getEmail(),
            ],
            null,
            true,
        );

        return new Response($this->twig->render('admin/tickets/_messages.html.twig', [
            'ticket' => $this->normalizeTicket($ticket),
            'messages' => $this->normalizeMessages($this->ticketMessageRepository->findByTicket($ticket)),
            'statusStyles' => $this->statusStyles(),
            'priorityStyles' => $this->priorityStyles(),
        ]));
    }

    #[Route(path: '/{id}/internal-notes', name: 'admin_ticket_internal_notes_create', methods: ['POST'])]
    public function addInternalNote(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }
        if (!$this->isCsrfValid($request, 'admin_ticket_internal_note_' . $id)) {
            return new Response('Invalid CSRF token.', Response::HTTP_UNAUTHORIZED);
        }

        $ticket = $this->ticketRepository->find($id);
        if ($ticket === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $noteBody = trim((string) $request->request->get('internal_note', ''));
        if ($noteBody === '') {
            return new Response('Internal note is required.', Response::HTTP_BAD_REQUEST);
        }

        $message = new TicketMessage($ticket, $actor, $noteBody, true);

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'ticket.internal_note.created', [
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
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }
        if (!$this->isCsrfValid($request, 'admin_ticket_status_' . $id)) {
            return new Response('Invalid CSRF token.', Response::HTTP_UNAUTHORIZED);
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

        if (!$request->headers->has('HX-Request')) {
            return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/admin/tickets/' . $ticket->getId()]);
        }

        return new Response($this->twig->render('admin/tickets/_status_badge.html.twig', [
            'ticket' => $this->normalizeTicket($ticket),
            'statusStyles' => $this->statusStyles(),
        ]));
    }


    #[Route(path: '/{id}/metadata', name: 'admin_ticket_metadata_update', methods: ['POST'])]
    public function updateMetadata(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }
        if (!$this->isCsrfValid($request, 'admin_ticket_metadata_' . $id)) {
            return new Response('Invalid CSRF token.', Response::HTTP_UNAUTHORIZED);
        }

        $ticket = $this->ticketRepository->find($id);
        if ($ticket === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $status = TicketStatus::tryFrom(strtolower(trim((string) $request->request->get('status', $ticket->getStatus()->value))));
        $priority = TicketPriority::tryFrom(strtolower(trim((string) $request->request->get('priority', $ticket->getPriority()->value))));
        $category = TicketCategory::tryFrom(strtolower(trim((string) $request->request->get('category', $ticket->getCategory()->value))));
        $assignedTo = $this->resolveAssignee((string) $request->request->get('assigned_to_id', ''));

        if ($status === null || $priority === null || $category === null || $assignedTo === false) {
            return new Response('Invalid ticket metadata.', Response::HTTP_BAD_REQUEST);
        }

        $ticket->setStatus($status);
        $ticket->setPriority($priority);
        $ticket->setCategory($category);
        $ticket->assignTo($assignedTo);

        $this->auditLogger->log($actor, 'ticket.metadata.updated', [
            'ticket_id' => $ticket->getId(),
            'status' => $ticket->getStatus()->value,
            'priority' => $ticket->getPriority()->value,
            'category' => $ticket->getCategory()->value,
            'assigned_to_id' => $ticket->getAssignedTo()?->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/admin/tickets/' . $ticket->getId()]);
    }

    #[Route(path: '/templates', name: 'admin_ticket_templates_create', methods: ['POST'])]
    public function createTemplate(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }
        if (!$this->isCsrfValid($request, 'admin_ticket_template_create')) {
            return new Response('Invalid CSRF token.', Response::HTTP_UNAUTHORIZED);
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
            $payload['form']['category'],
            $payload['form']['priority'],
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
        if (!$this->isCsrfValid($request, 'admin_ticket_template_' . $id)) {
            return new Response('Invalid CSRF token.', Response::HTTP_UNAUTHORIZED);
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
        $template->setCategory($payload['form']['category']);
        $template->setPriority($payload['form']['priority']);
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
        if (!$this->isCsrfValid($request, 'admin_ticket_template_delete_' . $id)) {
            return new Response('Invalid CSRF token.', Response::HTTP_UNAUTHORIZED);
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
        if (!$this->isCsrfValid($request, 'admin_ticket_quick_reply_create')) {
            return new Response('Invalid CSRF token.', Response::HTTP_UNAUTHORIZED);
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
        if (!$this->isCsrfValid($request, 'admin_ticket_quick_reply_' . $id)) {
            return new Response('Invalid CSRF token.', Response::HTTP_UNAUTHORIZED);
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
        if (!$this->isCsrfValid($request, 'admin_ticket_quick_reply_delete_' . $id)) {
            return new Response('Invalid CSRF token.', Response::HTTP_UNAUTHORIZED);
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


    #[Route(path: '/attachments/{id}/download', name: 'admin_ticket_attachment_download', methods: ['GET'])]
    public function downloadAttachment(Request $request, int $id): Response
    {
        if ($this->requireAdmin($request) === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $attachment = $this->ticketAttachmentRepository->find($id);
        if (!$attachment instanceof TicketAttachment) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return $this->streamAttachment($attachment);
    }


    /**
     * @return UploadedFile[]
     */
    private function attachmentFiles(Request $request): array
    {
        $files = $request->files->all('attachments');
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (!is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    /**
     * @param UploadedFile[] $files
     */
    private function validateAttachmentFiles(array $files): ?string
    {
        foreach ($files as $file) {
            if (!$file->isValid()) {
                return 'Attachment upload failed.';
            }

            if ($file->getSize() === null || $file->getSize() > self::MAX_ATTACHMENT_BYTES) {
                return 'Attachment exceeds the maximum size of 10 MB.';
            }

            $mimeType = $file->getMimeType() ?? 'application/octet-stream';
            if (!in_array($mimeType, self::ALLOWED_ATTACHMENT_MIME_TYPES, true)) {
                return 'Attachment file type is not allowed.';
            }

            $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
            if ($extension !== '' && in_array($extension, self::BLOCKED_ATTACHMENT_EXTENSIONS, true)) {
                return 'Attachment file type is not allowed.';
            }
        }

        return null;
    }

    /**
     * @param UploadedFile[] $files
     */
    private function persistAttachments(Ticket $ticket, TicketMessage $message, User $actor, array $files): void
    {
        foreach ($files as $file) {
            $mimeType = $file->getMimeType() ?? 'application/octet-stream';
            $extension = $file->guessExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION) ?: 'bin';
            $safeExtension = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($extension)) ?: 'bin';
            if (in_array($safeExtension, self::BLOCKED_ATTACHMENT_EXTENSIONS, true)) {
                $safeExtension = 'bin';
            }
            $path = sprintf('ticket-attachments/%s/%s.%s', (new \DateTimeImmutable())->format('Y/m'), bin2hex(random_bytes(16)), $safeExtension);
            $stream = fopen($file->getPathname(), 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to read uploaded attachment.');
            }

            try {
                $this->storage->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $attachment = new TicketAttachment(
                $ticket,
                $message,
                $actor,
                $this->sanitizeAttachmentName($file->getClientOriginalName()),
                $path,
                $mimeType,
                (int) $file->getSize(),
            );
            $this->entityManager->persist($attachment);
        }
    }

    private function sanitizeAttachmentName(string $name): string
    {
        $name = trim(basename(str_replace('\\', '/', $name)));
        if ($name === '') {
            return 'attachment';
        }

        return mb_substr($name, 0, 180);
    }

    private function streamAttachment(TicketAttachment $attachment): Response
    {
        $storagePath = $attachment->getStoragePath();
        if (!$this->isSafeAttachmentStoragePath($storagePath) || !$this->storage->fileExists($storagePath)) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $response = new StreamedResponse(function () use ($storagePath): void {
            $stream = $this->storage->readStream($storagePath);
            if ($stream === false) {
                return;
            }

            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        });
        $mimeType = $attachment->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_ATTACHMENT_MIME_TYPES, true)) {
            $mimeType = 'application/octet-stream';
        }
        $response->headers->set('Content-Type', $mimeType);
        $fileSize = $this->storage->fileSize($storagePath);
        $response->headers->set('Content-Length', (string) $fileSize);
        $downloadName = $this->sanitizeAttachmentName($attachment->getOriginalName());
        $fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'attachment';
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $downloadName, $fallbackName));

        return $response;
    }

    private function isSafeAttachmentStoragePath(string $storagePath): bool
    {
        return str_starts_with($storagePath, 'ticket-attachments/')
            && !str_contains($storagePath, '..')
            && !str_starts_with($storagePath, '/')
            && !str_contains($storagePath, "\0");
    }

    /**
     * @param TicketMessage[] $messages
     *
     * @return array<int, array<int, array{id: int|null, name: string, size: int, mime_type: string, download_url: string}>>
     */
    private function normalizeAttachmentsByMessage(array $messages, string $downloadPrefix): array
    {
        $attachmentsByMessage = [];
        foreach ($this->ticketAttachmentRepository->findByMessages($messages) as $attachment) {
            $messageId = $attachment->getMessage()->getId();
            if ($messageId === null) {
                continue;
            }

            $attachmentsByMessage[$messageId][] = [
                'id' => $attachment->getId(),
                'name' => $attachment->getOriginalName(),
                'size' => $attachment->getSizeBytes(),
                'mime_type' => $attachment->getMimeType(),
                'download_url' => sprintf('%s/%s/download', $downloadPrefix, $attachment->getId()),
            ];
        }

        return $attachmentsByMessage;
    }

    private function isCsrfValid(Request $request, string $tokenId): bool
    {
        return $this->csrfTokenManager->isTokenValid(new CsrfToken($tokenId, (string) $request->request->get('_token', '')));
    }

    private function requireAdmin(Request $request): ?User
    {
        $actor = $request->attributes->get('current_user');

        if (!$actor instanceof User || !$actor->isAdmin()) {
            return null;
        }

        return $actor;
    }

    private function buildSummary(array $tickets, ?User $admin = null): array
    {
        $open = 0;
        $pending = 0;
        $resolved = 0;
        $closed = 0;
        $highPriority = 0;
        $resolvedToday = 0;
        $assignedToMe = 0;
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        foreach ($tickets as $ticket) {
            match ($ticket->getStatus()) {
                TicketStatus::Open => $open++,
                TicketStatus::Pending => $pending++,
                TicketStatus::Resolved => $resolved++,
                TicketStatus::Closed => $closed++,
            };

            if (in_array($ticket->getPriority(), [TicketPriority::High, TicketPriority::Urgent], true)) {
                $highPriority++;
            }

            if ($ticket->getStatus() === TicketStatus::Resolved && $ticket->getUpdatedAt()->format('Y-m-d') === $today) {
                $resolvedToday++;
            }

            if ($admin !== null && $ticket->getAssignedTo()?->getId() === $admin->getId()) {
                $assignedToMe++;
            }
        }

        return [
            'total' => count($tickets),
            'open' => $open,
            'in_progress' => 0,
            'pending' => $pending,
            'resolved' => $resolved,
            'closed' => $closed,
            'high_priority' => $highPriority,
            'resolved_today' => $resolvedToday,
            'assigned_to_me' => $assignedToMe,
        ];
    }


    /**
     * @param Ticket[] $tickets
     * @return Ticket[]
     */
    private function applyTicketFilters(array $tickets, Request $request, ?User $admin = null): array
    {
        $filters = $this->ticketFilters($request);

        return array_values(array_filter($tickets, static function (Ticket $ticket) use ($filters, $admin): bool {
            if ($filters['status'] !== '' && $ticket->getStatus()->value !== $filters['status']) {
                return false;
            }

            if ($filters['priority'] !== '' && $ticket->getPriority()->value !== $filters['priority']) {
                return false;
            }

            if ($filters['category'] !== '' && $ticket->getCategory()->value !== $filters['category']) {
                return false;
            }

            if ($filters['assignee'] === 'unassigned' && $ticket->getAssignedTo() !== null) {
                return false;
            }

            if ($filters['assignee'] === 'me') {
                if ($admin === null || $ticket->getAssignedTo()?->getId() !== $admin->getId()) {
                    return false;
                }
            } elseif ($filters['assignee'] !== '' && $filters['assignee'] !== 'unassigned') {
                if (!ctype_digit($filters['assignee']) || $ticket->getAssignedTo()?->getId() !== (int) $filters['assignee']) {
                    return false;
                }
            }

            if ($filters['q'] !== '') {
                $haystack = strtolower(sprintf('%s %s %s %s', $ticket->getId(), $ticket->getSubject(), $ticket->getCustomer()->getEmail(), $ticket->getCustomer()->getName() ?? ''));
                if (!str_contains($haystack, strtolower($filters['q']))) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function ticketFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query->get('q', '')),
            'status' => strtolower(trim((string) $request->query->get('status', ''))),
            'priority' => strtolower(trim((string) $request->query->get('priority', ''))),
            'category' => strtolower(trim((string) $request->query->get('category', ''))),
            'assignee' => trim((string) $request->query->get('assignee', '')),
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
            'assigned_to' => $ticket->getAssignedTo() === null ? null : [
                'id' => $ticket->getAssignedTo()->getId(),
                'email' => $ticket->getAssignedTo()->getEmail(),
            ],
            'created_at' => $ticket->getCreatedAt(),
            'last_message_at' => $ticket->getLastMessageAt(),
            'updated_at' => $ticket->getUpdatedAt(),
        ];
    }

    private function normalizeMessages(array $messages): array
    {
        $attachmentsByMessage = $this->normalizeAttachmentsByMessage($messages, '/admin/tickets/attachments');

        return array_map(fn (TicketMessage $message) => [
            'id' => $message->getId(),
            'author' => [
                'id' => $message->getAuthor()->getId(),
                'email' => $message->getAuthor()->getEmail(),
                'type' => $message->getAuthor()->getType()->value,
            ],
            'body' => $message->getBody(),
            'created_at' => $message->getCreatedAt(),
            'attachments' => $attachmentsByMessage[$message->getId()] ?? [],
            'is_internal' => $message->isInternal(),
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
        $allTickets = $this->ticketRepository->findVisibleForAdminQueue($admin);
        $tickets = $this->applyTicketFilters($allTickets, $request, $admin);
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
            'summary' => $this->buildSummary($allTickets, $admin),
            'form' => $this->buildFormContext(),
            'customers' => $this->userRepository->findBy(['type' => UserType::Customer], ['email' => 'ASC']),
            'staffUsers' => $this->staffUsers(),
            'filters' => $this->ticketFilters($request),
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


    /**
     * @return User[]
     */
    private function staffUsers(): array
    {
        $staff = array_merge(
            $this->userRepository->findBy(['type' => UserType::Admin], ['email' => 'ASC']),
            $this->userRepository->findBy(['type' => UserType::Superadmin], ['email' => 'ASC']),
        );

        usort($staff, static fn (User $a, User $b): int => strcasecmp($a->getEmail(), $b->getEmail()));

        return $staff;
    }

    private function resolveAssignee(string $value): User|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (!ctype_digit($value)) {
            return false;
        }

        $user = $this->userRepository->find((int) $value);
        if (!$user instanceof User || !$user->isAdmin()) {
            return false;
        }

        return $user;
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
            TicketStatus::Open->value => 'border-blue-200 bg-blue-50 text-blue-700',
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
