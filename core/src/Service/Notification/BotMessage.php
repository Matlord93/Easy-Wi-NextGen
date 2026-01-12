<?php

declare(strict_types=1);

namespace App\Service\Notification;

final class BotMessage
{
    /**
     * @param array<int, array{label: string, value: string}> $fields
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
        public readonly ?string $severity = null,
        public readonly array $fields = [],
    ) {
    }
}
