<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Enum\DnsRecordType;

final class DnsRecordHelper
{
    private const TTL_MIN = 60;
    private const TTL_MAX = 86400;
    private const HOSTNAME_TYPES = [DnsRecordType::CNAME, DnsRecordType::NS, DnsRecordType::MX];

    /**
     * @return string[]
     */
    public function recordTypes(): array
    {
        return array_map(static fn (DnsRecordType $type): string => $type->value, DnsRecordType::cases());
    }

    public function minTtl(): int
    {
        return self::TTL_MIN;
    }

    public function maxTtl(): int
    {
        return self::TTL_MAX;
    }

    public function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '@') {
            return '@';
        }
        return $name;
    }

    public function normalizeContent(string $content, string $type): string
    {
        $content = trim($content);
        if ($content === '') {
            return $content;
        }

        if (!in_array($type, $this->recordTypes(), true)) {
            return $content;
        }

        if (in_array(DnsRecordType::from($type), self::HOSTNAME_TYPES, true)) {
            if (!str_contains($content, ' ') && !str_ends_with($content, '.')) {
                $content .= '.';
            }
        }

        return $content;
    }

    public function normalizeZoneName(string $zoneName): string
    {
        $zoneName = trim($zoneName);
        if ($zoneName === '') {
            return '';
        }
        return str_ends_with($zoneName, '.') ? $zoneName : $zoneName . '.';
    }

    public function buildRecordName(string $name, string $zoneName): string
    {
        $normalized = $this->normalizeName($name);
        if ($normalized === '@') {
            return $zoneName;
        }

        if (str_ends_with($normalized, '.')) {
            return $normalized;
        }

        if ($zoneName === '') {
            return $normalized;
        }

        return sprintf('%s.%s', $normalized, $zoneName);
    }

    public function buildRecordContent(string $type, string $content, ?int $priority): string
    {
        $normalized = $this->normalizeContent($content, $type);

        if ($type !== DnsRecordType::MX->value) {
            return $normalized;
        }

        if ($priority === null) {
            return $normalized;
        }

        return sprintf('%d %s', $priority, $normalized);
    }

    /**
     * @return string[]
     */
    public function validate(string $name, string $type, string $content, ?int $ttl, ?int $priority): array
    {
        $errors = [];
        $normalizedName = $this->normalizeName($name);

        if ($normalizedName === '') {
            $errors[] = 'Name is required.';
        }

        if (preg_match('/\s/', $normalizedName)) {
            $errors[] = 'Name cannot contain whitespace.';
        }

        if (!in_array($type, $this->recordTypes(), true)) {
            $errors[] = 'Record type is invalid.';
        }

        if ($content === '') {
            $errors[] = 'Content is required.';
        }

        if ($ttl === null) {
            $errors[] = 'TTL is required.';
        } elseif ($ttl < self::TTL_MIN || $ttl > self::TTL_MAX) {
            $errors[] = sprintf('TTL must be between %d and %d.', self::TTL_MIN, self::TTL_MAX);
        }

        if ($type === DnsRecordType::MX->value) {
            if ($priority === null) {
                $errors[] = 'Priority is required for MX records.';
            } elseif ($priority < 0 || $priority > 65535) {
                $errors[] = 'Priority must be between 0 and 65535.';
            }
        }

        return $errors;
    }
}
