<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Console;

/**
 * Thrown when the agent is reachable but the game server console is not
 * accessible (e.g. command socket missing, permission denied).
 * Unlike a network timeout this is a definitive, non-retryable failure,
 * so the caller should return an error to the user rather than queueing
 * a fallback job.
 */
final class ConsoleUnavailableException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $agentErrorCode = 'CONSOLE_UNAVAILABLE',
    ) {
        parent::__construct($message);
    }
}
