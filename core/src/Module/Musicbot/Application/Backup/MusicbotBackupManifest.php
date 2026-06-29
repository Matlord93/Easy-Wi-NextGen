<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

final class MusicbotBackupManifest
{
    public const SCHEMA_VERSION = '1';

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $backupType,
        public readonly string $instanceId,
        public readonly string $customerId,
        public readonly string $serviceName,
        public readonly string $appVersion,
        public readonly \DateTimeImmutable $createdAt,
        public readonly array $data,
        public readonly string $checksum = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'backup_type' => $this->backupType,
            'metadata' => [
                'instance_id' => $this->instanceId,
                'customer_id' => $this->customerId,
                'service_name' => $this->serviceName,
                'app_version' => $this->appVersion,
                'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            ],
            'data' => $this->data,
            'checksum' => $this->checksum,
        ];
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $meta = $raw['metadata'] ?? [];

        return new self(
            schemaVersion: (string) ($raw['schema_version'] ?? self::SCHEMA_VERSION),
            backupType: (string) ($raw['backup_type'] ?? MusicbotBackupType::Customer->value),
            instanceId: (string) ($meta['instance_id'] ?? ''),
            customerId: (string) ($meta['customer_id'] ?? ''),
            serviceName: (string) ($meta['service_name'] ?? ''),
            appVersion: (string) ($meta['app_version'] ?? ''),
            createdAt: new \DateTimeImmutable((string) ($meta['created_at'] ?? 'now')),
            data: (array) ($raw['data'] ?? []),
            checksum: (string) ($raw['checksum'] ?? ''),
        );
    }
}
