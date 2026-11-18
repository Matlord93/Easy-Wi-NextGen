<?php

declare(strict_types=1);

namespace App\Module\Voice\Application;

final class VoiceActionConflict extends \RuntimeException
{
    public function __construct(public readonly ?string $jobId)
    {
        parent::__construct('Another voice action is already in progress.');
    }
}
