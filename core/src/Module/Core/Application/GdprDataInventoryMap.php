<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class GdprDataInventoryMap
{
    /**
     * @return array<int, array{entity: string, pii_fields: list<string>, export_scope: string, deletion_strategy: string}>
     */
    public function all(): array
    {
        return [
            [
                'entity' => 'users',
                'pii_fields' => ['email', 'name', 'termsAcceptedIp', 'privacyAcceptedIp'],
                'export_scope' => 'core identity record',
                'deletion_strategy' => 'anonymize identity, keep technical identifiers for retention obligations',
            ],
            [
                'entity' => 'customer_profiles',
                'pii_fields' => ['firstName', 'lastName', 'address', 'postal', 'city', 'country', 'phone', 'company', 'vatId'],
                'export_scope' => 'profile section',
                'deletion_strategy' => 'overwrite with redacted values',
            ],
            [
                'entity' => 'consent_logs',
                'pii_fields' => ['ip', 'userAgent', 'acceptedAt'],
                'export_scope' => 'consents section',
                'deletion_strategy' => 'IP and user-agent redaction',
            ],
            [
                'entity' => 'tickets + ticket_messages',
                'pii_fields' => ['subject', 'body', 'author_id', 'timestamps'],
                'export_scope' => 'tickets section',
                'deletion_strategy' => 'retained based on retention policy',
            ],
            [
                'entity' => 'invoices + payments',
                'pii_fields' => ['invoice number', 'reference', 'amounts', 'timestamps'],
                'export_scope' => 'orders/invoices/payments + invoice PDFs',
                'deletion_strategy' => 'retained for accounting/legal obligations',
            ],
            [
                'entity' => 'service resources',
                'pii_fields' => ['domains', 'mailboxes', 'database names', 'backup targets'],
                'export_scope' => 'resources section',
                'deletion_strategy' => 'resource metadata retained or removed according to policy',
            ],
        ];
    }
}

