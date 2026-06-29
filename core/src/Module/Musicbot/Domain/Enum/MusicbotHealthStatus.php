<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotHealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Warning = 'warning';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Degraded => 'Degraded',
            self::Warning => 'Warning',
            self::Failed => 'Failed',
            self::Unknown => 'Unknown',
        };
    }

    public function isOperational(): bool
    {
        return $this === self::Healthy || $this === self::Warning;
    }

    public function severity(): int
    {
        return match ($this) {
            self::Healthy => 0,
            self::Warning => 1,
            self::Degraded => 2,
            self::Failed => 3,
            self::Unknown => 4,
        };
    }

    public static function aggregate(self ...$statuses): self
    {
        if ($statuses === []) {
            return self::Unknown;
        }
        $worst = self::Healthy;
        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
