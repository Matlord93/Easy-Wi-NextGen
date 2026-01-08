<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GdprExport;
use App\Entity\User;
use App\Repository\BackupDefinitionRepository;
use App\Repository\ConsentLogRepository;
use App\Repository\CustomerProfileRepository;
use App\Repository\DatabaseRepository;
use App\Repository\DnsRecordRepository;
use App\Repository\DomainRepository;
use App\Repository\InstanceRepository;
use App\Repository\InvoiceArchiveRepository;
use App\Repository\InvoiceRepository;
use App\Repository\MailAliasRepository;
use App\Repository\MailboxRepository;
use App\Repository\PaymentRepository;
use App\Repository\PortBlockRepository;
use App\Repository\TicketMessageRepository;
use App\Repository\TicketRepository;
use App\Repository\Ts3InstanceRepository;
use App\Repository\WebspaceRepository;
use ZipArchive;

final class GdprExportService
{
    public function __construct(
        private readonly EncryptionService $encryptionService,
        private readonly CustomerProfileRepository $profileRepository,
        private readonly ConsentLogRepository $consentLogRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly InvoiceArchiveRepository $invoiceArchiveRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly TicketRepository $ticketRepository,
        private readonly TicketMessageRepository $ticketMessageRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly DomainRepository $domainRepository,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly DatabaseRepository $databaseRepository,
        private readonly MailboxRepository $mailboxRepository,
        private readonly MailAliasRepository $mailAliasRepository,
        private readonly BackupDefinitionRepository $backupDefinitionRepository,
        private readonly DnsRecordRepository $dnsRecordRepository,
        private readonly Ts3InstanceRepository $ts3InstanceRepository,
        private readonly PortBlockRepository $portBlockRepository,
    ) {
    }

    public function buildExport(User $customer): GdprExport
    {
        $now = new \DateTimeImmutable();
        $fileName = sprintf('gdpr_export_%d_%s.zip', $customer->getId() ?? 0, $now->format('Ymd_His'));
        $zipPath = $this->buildZipArchive($customer, $fileName, $now);
        $zipBytes = file_get_contents($zipPath);
        if ($zipBytes === false) {
            throw new \RuntimeException('Unable to read GDPR export archive.');
        }

        @unlink($zipPath);

        $encryptedPayload = $this->encryptionService->encrypt($zipBytes);

        return new GdprExport(
            $customer,
            $fileName,
            strlen($zipBytes),
            $encryptedPayload,
            $now->modify('+7 days'),
            $now,
        );
    }

    private function buildZipArchive(User $customer, string $fileName, \DateTimeImmutable $now): string
    {
        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $fileName;
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create GDPR export archive.');
        }

        $payload = json_encode($this->buildDataPayload($customer, $now), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('Unable to encode GDPR export payload.');
        }
        $zip->addFromString('data.json', $payload);

        foreach ($this->invoiceArchiveRepository->findByCustomer($customer) as $archive) {
            $invoice = $archive->getInvoice();
            $path = sprintf('invoices/%s.pdf', $invoice->getNumber());
            $zip->addFromString($path, $archive->getPdfContents());
        }

        $zip->close();

        return $zipPath;
    }

    private function buildDataPayload(User $customer, \DateTimeImmutable $now): array
    {
        $profile = $this->profileRepository->findOneByCustomer($customer);
        $consents = $this->consentLogRepository->findByUser($customer);
        $invoices = $this->invoiceRepository->findByCustomer($customer);
        $payments = $this->paymentRepository->findByCustomer($customer);
        $tickets = $this->ticketRepository->findByCustomer($customer);

        $ticketPayloads = [];
        foreach ($tickets as $ticket) {
            $messages = $this->ticketMessageRepository->findByTicket($ticket);
            $ticketPayloads[] = [
                'id' => $ticket->getId(),
                'subject' => $ticket->getSubject(),
                'category' => $ticket->getCategory()->value,
                'status' => $ticket->getStatus()->value,
                'priority' => $ticket->getPriority()->value,
                'created_at' => $ticket->getCreatedAt()->format(DATE_RFC3339),
                'updated_at' => $ticket->getUpdatedAt()->format(DATE_RFC3339),
                'messages' => array_map(static function ($message): array {
                    return [
                        'id' => $message->getId(),
                        'author_id' => $message->getAuthor()->getId(),
                        'body' => $message->getBody(),
                        'created_at' => $message->getCreatedAt()->format(DATE_RFC3339),
                    ];
                }, $messages),
            ];
        }

        return [
            'generated_at' => $now->format(DATE_RFC3339),
            'customer' => [
                'id' => $customer->getId(),
                'email' => $customer->getEmail(),
                'created_at' => $customer->getCreatedAt()->format(DATE_RFC3339),
            ],
            'profile' => $profile === null ? null : [
                'first_name' => $profile->getFirstName(),
                'last_name' => $profile->getLastName(),
                'address' => $profile->getAddress(),
                'postal' => $profile->getPostal(),
                'city' => $profile->getCity(),
                'country' => $profile->getCountry(),
                'phone' => $profile->getPhone(),
                'company' => $profile->getCompany(),
                'vat_id' => $profile->getVatId(),
            ],
            'consents' => array_map(static function ($log): array {
                return [
                    'id' => $log->getId(),
                    'type' => $log->getType()->value,
                    'accepted_at' => $log->getAcceptedAt()->format(DATE_RFC3339),
                    'ip' => $log->getIp(),
                    'user_agent' => $log->getUserAgent(),
                    'version' => $log->getVersion(),
                ];
            }, $consents),
            'orders' => array_map(static function ($invoice): array {
                return [
                    'id' => $invoice->getId(),
                    'number' => $invoice->getNumber(),
                    'status' => $invoice->getStatus()->value,
                    'amount_total_cents' => $invoice->getAmountTotalCents(),
                    'currency' => $invoice->getCurrency(),
                    'created_at' => $invoice->getCreatedAt()->format(DATE_RFC3339),
                ];
            }, $invoices),
            'invoices' => array_map(static function ($invoice): array {
                return [
                    'id' => $invoice->getId(),
                    'number' => $invoice->getNumber(),
                    'status' => $invoice->getStatus()->value,
                    'currency' => $invoice->getCurrency(),
                    'amount_total_cents' => $invoice->getAmountTotalCents(),
                    'amount_due_cents' => $invoice->getAmountDueCents(),
                    'due_date' => $invoice->getDueDate()->format(DATE_RFC3339),
                    'paid_at' => $invoice->getPaidAt()?->format(DATE_RFC3339),
                    'created_at' => $invoice->getCreatedAt()->format(DATE_RFC3339),
                ];
            }, $invoices),
            'payments' => array_map(static function ($payment): array {
                return [
                    'id' => $payment->getId(),
                    'invoice_id' => $payment->getInvoice()->getId(),
                    'provider' => $payment->getProvider(),
                    'reference' => $payment->getReference(),
                    'amount_cents' => $payment->getAmountCents(),
                    'currency' => $payment->getCurrency(),
                    'status' => $payment->getStatus()->value,
                    'received_at' => $payment->getReceivedAt()?->format(DATE_RFC3339),
                    'created_at' => $payment->getCreatedAt()->format(DATE_RFC3339),
                ];
            }, $payments),
            'tickets' => $ticketPayloads,
            'resources' => [
                'instances' => array_map(static function ($instance): array {
                    return [
                        'id' => $instance->getId(),
                        'template' => $instance->getTemplate()->getName(),
                        'status' => $instance->getStatus()->value,
                        'cpu_limit' => $instance->getCpuLimit(),
                        'ram_limit' => $instance->getRamLimit(),
                        'disk_limit' => $instance->getDiskLimit(),
                        'created_at' => $instance->getCreatedAt()->format(DATE_RFC3339),
                    ];
                }, $this->instanceRepository->findByCustomer($customer)),
                'domains' => array_map(static function ($domain): array {
                    return [
                        'id' => $domain->getId(),
                        'domain' => $domain->getName(),
                        'status' => $domain->getStatus(),
                        'ssl_expires_at' => $domain->getSslExpiresAt()?->format(DATE_RFC3339),
                        'created_at' => $domain->getCreatedAt()->format(DATE_RFC3339),
                    ];
                }, $this->domainRepository->findByCustomer($customer)),
                'webspaces' => array_map(static function ($webspace): array {
                    return [
                        'id' => $webspace->getId(),
                        'path' => $webspace->getPath(),
                        'php_version' => $webspace->getPhpVersion(),
                        'quota' => $webspace->getQuota(),
                        'created_at' => $webspace->getCreatedAt()->format(DATE_RFC3339),
                    ];
                }, $this->webspaceRepository->findByCustomer($customer)),
                'databases' => array_map(static function ($database): array {
                    return [
                        'id' => $database->getId(),
                        'name' => $database->getName(),
                        'engine' => $database->getEngine(),
                        'host' => $database->getHost(),
                        'port' => $database->getPort(),
                        'created_at' => $database->getCreatedAt()->format(DATE_RFC3339),
                    ];
                }, $this->databaseRepository->findByCustomer($customer)),
                'mailboxes' => array_map(static function ($mailbox): array {
                    return [
                        'id' => $mailbox->getId(),
                        'address' => $mailbox->getAddress(),
                        'enabled' => $mailbox->isEnabled(),
                        'created_at' => $mailbox->getCreatedAt()->format(DATE_RFC3339),
                    ];
                }, $this->mailboxRepository->findByCustomer($customer)),
                'mail_aliases' => array_map(static function ($alias): array {
                    return [
                        'id' => $alias->getId(),
                        'address' => $alias->getAddress(),
                        'destinations' => $alias->getDestinations(),
                        'enabled' => $alias->isEnabled(),
                        'created_at' => $alias->getCreatedAt()->format(DATE_RFC3339),
                    ];
                }, $this->mailAliasRepository->findByCustomer($customer)),
                'backups' => array_map(static function ($backup): array {
                    $schedule = $backup->getSchedule();
                    return [
                        'id' => $backup->getId(),
                        'label' => $backup->getLabel(),
                        'target_type' => $backup->getTargetType()->value,
                        'target_id' => $backup->getTargetId(),
                        'schedule' => $schedule?->getCronExpression(),
                        'retention_days' => $schedule?->getRetentionDays(),
                        'retention_count' => $schedule?->getRetentionCount(),
                        'enabled' => $schedule?->isEnabled(),
                        'created_at' => $backup->getCreatedAt()->format(DATE_RFC3339),
                    ];
                }, $this->backupDefinitionRepository->findByCustomer($customer)),
                'dns_records' => array_map(static function ($record): array {
                    return [
                        'id' => $record->getId(),
                        'type' => $record->getType(),
                        'name' => $record->getName(),
                        'content' => $record->getContent(),
                        'ttl' => $record->getTtl(),
                        'created_at' => $record->getCreatedAt()->format(DATE_RFC3339),
                    ];
                }, $this->dnsRecordRepository->findByCustomer($customer)),
                'ts3_instances' => array_map(static function ($instance): array {
                    return [
                        'id' => $instance->getId(),
                        'name' => $instance->getName(),
                        'status' => $instance->getStatus()->value,
                        'voice_port' => $instance->getVoicePort(),
                        'query_port' => $instance->getQueryPort(),
                        'file_port' => $instance->getFilePort(),
                        'created_at' => $instance->getCreatedAt()->format(DATE_RFC3339),
                    ];
                }, $this->ts3InstanceRepository->findByCustomer($customer)),
                'port_blocks' => array_map(static function ($block): array {
                    return [
                        'id' => $block->getId(),
                        'start_port' => $block->getStartPort(),
                        'end_port' => $block->getEndPort(),
                        'created_at' => $block->getCreatedAt()->format(DATE_RFC3339),
                    ];
                }, $this->portBlockRepository->findByCustomer($customer)),
            ],
        ];
    }
}
