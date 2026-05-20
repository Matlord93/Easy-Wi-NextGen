<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\MailService;
use App\Module\Core\Application\NotificationService;
use App\Module\Core\Domain\Entity\Ticket;
use App\Module\Core\Domain\Entity\TicketAttachment;
use App\Module\Core\Domain\Entity\TicketMessage;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\TicketCategory;
use App\Module\Core\Domain\Enum\TicketPriority;
use App\Module\Core\Domain\Enum\TicketStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\TicketAttachmentRepository;
use App\Repository\TicketMessageRepository;
use App\Repository\TicketRepository;
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

#[Route(path: '/tickets')]
final class CustomerTicketController
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
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notificationService,
        private readonly MailService $mailService,
        private readonly AppSettingsService $appSettingsService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_tickets', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        $allTickets = $this->ticketRepository->findByCustomer($customer);
        $tickets = $this->applyTicketFilters($allTickets, $request);

        return new Response($this->twig->render('customer/tickets/index.html.twig', [
            'tickets' => $this->normalizeTickets($tickets),
            'summary' => $this->buildSummary($allTickets),
            'form' => $this->buildFormContext(),
            'statusStyles' => $this->statusStyles(),
            'priorityStyles' => $this->priorityStyles(),
            'filters' => $this->ticketFilters($request),
            'activeNav' => 'tickets',
        ]));
    }

    #[Route(path: '/table', name: 'customer_tickets_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $tickets = $this->applyTicketFilters($this->ticketRepository->findByCustomer($customer), $request);

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
        if (!$this->isCsrfValid($request, 'customer_ticket_create')) {
            return new Response('Invalid CSRF token.', Response::HTTP_UNAUTHORIZED);
        }

        $formData = $this->parsePayload($request);
        $attachmentFiles = $this->attachmentFiles($request);
        $attachmentError = $this->validateAttachmentFiles($attachmentFiles);
        if ($attachmentError !== null) {
            $formData['errors'][] = $attachmentError;
        }

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
        $this->notificationService->notifyAdmins(
            sprintf('ticket.created.%s', $ticket->getId()),
            sprintf('New ticket · #%s', $ticket->getId()),
            sprintf('%s · %s', $ticket->getCustomer()->getEmail(), $ticket->getSubject()),
            'tickets',
            '/admin/tickets',
        );
        $this->entityManager->flush();

        $ticketContext = $this->buildTicketMailContext($ticket, $message->getBody());
        $this->mailService->sendTemplate(
            $actor->getEmail(),
            'ticket_opened',
            $ticketContext,
            null,
            true,
        );
        $supportEmail = $this->appSettingsService->getSupportEmail() ?? $this->appSettingsService->getMailFromAddress();
        $this->mailService->sendTemplate(
            $supportEmail,
            'ticket_opened_admin',
            $ticketContext,
            null,
            true,
        );

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

        $messages = $this->ticketMessageRepository->findPublicByTicket($ticket);

        return new Response($this->twig->render('customer/tickets/show.html.twig', [
            'ticket' => $this->normalizeTicket($ticket),
            'messages' => $this->normalizeMessages($messages),
            'statusStyles' => $this->statusStyles(),
            'priorityStyles' => $this->priorityStyles(),
            'filters' => $this->ticketFilters($request),
            'activeNav' => 'tickets',
        ]));
    }

    #[Route(path: '/{id}/messages', name: 'customer_ticket_messages_create', methods: ['POST'])]
    public function addMessage(Request $request, int $id): Response
    {
        $actor = $this->requireCustomer($request);
        if (!$this->isCsrfValid($request, 'customer_ticket_message_' . $id)) {
            return new Response('Invalid CSRF token.', Response::HTTP_UNAUTHORIZED);
        }
        $ticket = $this->ticketRepository->find($id);
        if ($ticket === null || $ticket->getCustomer()->getId() !== $actor->getId()) {
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

        $message = new TicketMessage($ticket, $actor, $messageBody);
        $ticket->noteMessage();

        $this->entityManager->persist($message);
        $this->persistAttachments($ticket, $message, $actor, $attachmentFiles);
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

        $supportEmail = $this->appSettingsService->getSupportEmail() ?? $this->appSettingsService->getMailFromAddress();
        $this->mailService->sendTemplate(
            $supportEmail,
            'ticket_reply_admin',
            [
                'ticket_id'      => $ticket->getId(),
                'ticket_subject' => $ticket->getSubject(),
                'ticket_status'  => ucfirst(strtolower($ticket->getStatus()->value)),
                'customer_name'  => $actor->getName() ?? $actor->getEmail(),
                'customer_email' => $actor->getEmail(),
                'reply_message'  => $messageBody,
                'replied_at'     => $message->getCreatedAt()->format('d.m.Y H:i'),
                'ticket_url'     => '/admin/tickets/' . $ticket->getId(),
            ],
            null,
            true,
        );

        return new Response($this->twig->render('customer/tickets/_messages.html.twig', [
            'ticket' => $this->normalizeTicket($ticket),
            'messages' => $this->normalizeMessages($this->ticketMessageRepository->findPublicByTicket($ticket)),
            'statusStyles' => $this->statusStyles(),
            'priorityStyles' => $this->priorityStyles(),
        ]));
    }


    #[Route(path: '/attachments/{id}/download', name: 'customer_ticket_attachment_download', methods: ['GET'])]
    public function downloadAttachment(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $attachment = $this->ticketAttachmentRepository->find($id);
        if (!$attachment instanceof TicketAttachment || $attachment->getTicket()->getCustomer()->getId() !== $customer->getId() || $attachment->getMessage()->isInternal()) {
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
        $highPriority = 0;
        $resolvedToday = 0;
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
        ];
    }


    /**
     * @param Ticket[] $tickets
     * @return Ticket[]
     */
    private function applyTicketFilters(array $tickets, Request $request): array
    {
        $filters = $this->ticketFilters($request);

        return array_values(array_filter($tickets, static function (Ticket $ticket) use ($filters): bool {
            if ($filters['status'] !== '' && $ticket->getStatus()->value !== $filters['status']) {
                return false;
            }
            if ($filters['q'] !== '') {
                $haystack = strtolower(sprintf('%s %s %s', $ticket->getId(), $ticket->getSubject(), $ticket->getCategory()->value));
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
            'updated_at' => $ticket->getUpdatedAt(),
        ];
    }

    private function normalizeMessages(array $messages): array
    {
        $attachmentsByMessage = $this->normalizeAttachmentsByMessage($messages, '/tickets/attachments');

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
            $errors[] = 'error_subject_required';
        }
        if ($message === '') {
            $errors[] = 'error_message_required';
        }

        $category = TicketCategory::tryFrom($categoryValue);
        if ($category === null) {
            $errors[] = 'error_category_required';
        }

        $priority = TicketPriority::tryFrom($priorityValue);
        if ($priority === null) {
            $errors[] = 'error_priority_required';
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

    /**
     * @return array<string, mixed>
     */
    private function buildTicketMailContext(Ticket $ticket, string $messageBody): array
    {
        $customer = $ticket->getCustomer();

        return [
            'ticket_id'         => $ticket->getId(),
            'ticket_subject'    => $ticket->getSubject(),
            'ticket_category'   => ucfirst(strtolower($ticket->getCategory()->value)),
            'ticket_priority'   => ucfirst(strtolower($ticket->getPriority()->value)),
            'ticket_created_at' => $ticket->getCreatedAt()->format('d.m.Y H:i'),
            'ticket_message'    => $messageBody,
            'ticket_url'        => '/tickets/' . $ticket->getId(),
            'customer_name'     => $customer->getName() ?? $customer->getEmail(),
            'customer_email'    => $customer->getEmail(),
        ];
    }
}
