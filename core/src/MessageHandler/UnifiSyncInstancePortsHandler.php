<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\UnifiSyncInstancePortsMessage;
use App\Module\Unifi\Application\UnifiPortSyncService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UnifiSyncInstancePortsHandler
{
    public function __construct(private readonly UnifiPortSyncService $syncService)
    {
    }

    public function __invoke(UnifiSyncInstancePortsMessage $message): void
    {
        $this->syncService->sync($message->getInstanceId(), false);
    }
}
