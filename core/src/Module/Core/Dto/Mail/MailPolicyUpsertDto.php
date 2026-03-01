<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Mail;

use App\Module\Core\Domain\Entity\MailPolicy;

final readonly class MailPolicyUpsertDto
{
    public function __construct(
        public bool $requireTls,
        public int $maxRecipients,
        public int $maxHourlyEmails,
        public bool $allowExternalForwarding,
        public string $spamProtectionLevel,
        public bool $greylistingEnabled,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $maxRecipients = is_numeric($payload['max_recipients'] ?? null) ? (int) $payload['max_recipients'] : 100;
        $maxHourlyEmails = is_numeric($payload['max_hourly_emails'] ?? null) ? (int) $payload['max_hourly_emails'] : 500;

        if ($maxRecipients < 1 || $maxRecipients > 1000) {
            throw new \InvalidArgumentException('max_recipients must be between 1 and 1000.');
        }
        if ($maxHourlyEmails < 1 || $maxHourlyEmails > 100000) {
            throw new \InvalidArgumentException('max_hourly_emails must be between 1 and 100000.');
        }

        $spamLevel = strtolower(trim((string) ($payload['spam_protection_level'] ?? MailPolicy::SPAM_MED)));
        if (!in_array($spamLevel, [MailPolicy::SPAM_LOW, MailPolicy::SPAM_MED, MailPolicy::SPAM_HIGH], true)) {
            throw new \InvalidArgumentException('spam_protection_level must be low, med or high.');
        }

        return new self(
            self::toBool($payload['require_tls'] ?? false),
            $maxRecipients,
            $maxHourlyEmails,
            self::toBool($payload['allow_external_forwarding'] ?? false),
            $spamLevel,
            self::toBool($payload['greylisting_enabled'] ?? true),
        );
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
