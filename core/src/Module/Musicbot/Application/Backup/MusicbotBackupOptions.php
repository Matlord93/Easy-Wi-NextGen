<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

final class MusicbotBackupOptions
{
    public function __construct(
        public readonly MusicbotBackupType $type = MusicbotBackupType::Customer,
        public readonly bool $includeQueue = false,
        public readonly bool $includeTracks = false,
        public readonly bool $includeYoutubeHistory = false,
        public readonly bool $includePluginLogs = false,
        public readonly bool $includeCustomerLimits = false,
        public readonly bool $maskSecrets = true,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, bool $isAdmin = false): self
    {
        $type = MusicbotBackupType::tryFrom((string) ($data['type'] ?? '')) ?? MusicbotBackupType::Customer;

        if (!$isAdmin && $type === MusicbotBackupType::Admin) {
            $type = MusicbotBackupType::Customer;
        }

        return new self(
            type: $type,
            includeQueue: (bool) ($data['include_queue'] ?? false),
            includeTracks: (bool) ($data['include_tracks'] ?? ($type === MusicbotBackupType::Full)),
            includeYoutubeHistory: (bool) ($data['include_youtube_history'] ?? false),
            includePluginLogs: (bool) ($data['include_plugin_logs'] ?? false),
            includeCustomerLimits: $isAdmin && (bool) ($data['include_customer_limits'] ?? false),
            maskSecrets: !$isAdmin || (bool) ($data['mask_secrets'] ?? true),
        );
    }
}
