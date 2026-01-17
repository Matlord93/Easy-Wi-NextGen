<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Notification;

interface BotProviderInterface
{
    public function getName(): string;

    public function send(BotMessage $message, BotChannel $channel): void;
}
