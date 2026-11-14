<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\UnifiSyncManualRulesMessage;
use App\Module\Unifi\Application\UnifiSynchronizerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UnifiSyncManualRulesHandler
{
    public function __construct(private readonly UnifiSynchronizerInterface $syncService)
    {
    }

    public function __invoke(UnifiSyncManualRulesMessage $_message): void
    {
        $this->syncService->sync(null, false);
    }
}
