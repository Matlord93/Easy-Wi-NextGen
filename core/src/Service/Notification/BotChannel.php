<?php

declare(strict_types=1);

namespace App\Service\Notification;

final class BotChannel
{
    public function __construct(
        public readonly string $endpoint,
        public readonly array $options = [],
    ) {
    }
}
