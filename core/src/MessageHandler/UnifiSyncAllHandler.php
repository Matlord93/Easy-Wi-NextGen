<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\UnifiSyncAllMessage;
use App\Module\Unifi\Application\UnifiSynchronizerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UnifiSyncAllHandler
{
    public function __construct(private readonly UnifiSynchronizerInterface $syncService)
    {
    }

    public function __invoke(UnifiSyncAllMessage $_message): void
    {
        $this->syncService->sync(null, false);
    }
}
