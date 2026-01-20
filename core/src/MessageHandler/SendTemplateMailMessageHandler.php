<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendTemplateMailMessage;
use App\Module\Core\Application\MailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendTemplateMailMessageHandler
{
    public function __construct(private readonly MailService $mailService)
    {
    }

    public function __invoke(SendTemplateMailMessage $message): void
    {
        $this->mailService->sendTemplate(
            $message->getTo(),
            $message->getTemplateKey(),
            $message->getContext(),
            $message->getLocale(),
            false,
        );
    }
}
