<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Infrastructure\Config\ScalarValue;

use App\Infrastructure\Config\JsonMap;

use App\Infrastructure\Config\JsonArray;

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

    /** @param array<array-key, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $meta = JsonArray::orEmpty($raw['metadata'] ?? null);

        return new self(
            schemaVersion: ScalarValue::text($raw['schema_version'] ?? self::SCHEMA_VERSION),
            backupType: ScalarValue::text($raw['backup_type'] ?? MusicbotBackupType::Customer->value),
            instanceId: ScalarValue::text($meta['instance_id'] ?? ''),
            customerId: ScalarValue::text($meta['customer_id'] ?? ''),
            serviceName: ScalarValue::text($meta['service_name'] ?? ''),
            appVersion: ScalarValue::text($meta['app_version'] ?? ''),
            createdAt: new \DateTimeImmutable(ScalarValue::text($meta['created_at'] ?? 'now')),
            data: JsonMap::fromValue($raw['data'] ?? null) ?? [],
            checksum: ScalarValue::text($raw['checksum'] ?? ''),
        );
    }
}
