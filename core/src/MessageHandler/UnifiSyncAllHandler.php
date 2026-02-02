<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\UnifiSyncAllMessage;
use App\Module\Unifi\Application\UnifiPortSyncService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UnifiSyncAllHandler
{
    public function __construct(private readonly UnifiPortSyncService $syncService)
    {
    }

    public function __invoke(UnifiSyncAllMessage $message): void
    {
        $this->syncService->sync(null, false);
    }
}
