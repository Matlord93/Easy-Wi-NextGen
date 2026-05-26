<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Update;

final class UpdateResult
{
    public function __construct(
        public readonly string $serverType,
        public readonly ?string $installedVersion,
        public readonly ?string $availableVersion,
        public readonly bool $updateAvailable,
        public readonly string $status,
        public readonly ?string $messageKey = null,
        /** @var array<string, scalar|null> */
        public readonly array $messageParams = [],
        public readonly ?string $assetUrl = null,
        public readonly ?string $assetName = null,
        public readonly ?int $assetSize = null,
        public readonly ?string $releaseTag = null,
        public readonly ?string $releaseNotes = null,
        public readonly ?ChecksumInfo $checksum = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'server_type' => $this->serverType,
            'installed_version' => $this->installedVersion,
            'available_version' => $this->availableVersion,
            'update_available' => $this->updateAvailable,
            'status' => $this->status,
            'message_key' => $this->messageKey,
            'message_params' => $this->messageParams,
            'asset_url' => $this->assetUrl,
            'asset_name' => $this->assetName,
            'asset_size' => $this->assetSize,
            'release_tag' => $this->releaseTag,
            'release_notes' => $this->releaseNotes,
            'checksum' => $this->checksum?->isAvailable() ? [
                'algorithm' => $this->checksum->algorithm,
                'value' => $this->checksum->value,
                'source' => $this->checksum->source,
            ] : null,
        ];
    }
}
