<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Job;

use App\Module\Voice\Application\Model\VoiceServer;

interface BackupIntegrationHookInterface
{
    public function triggerForServer(VoiceServer $server): void;
}
