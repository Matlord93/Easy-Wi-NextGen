<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Gameserver\Application\ConsoleCommandSettings;

final class ConsoleCommandValidator
{
    private const MAX_LENGTH = 512;
    private const DISALLOWED_TOKENS = [';', '&&', '||', '|', '>', '<', '`'];

    public function __construct(
        private readonly ConsoleCommandSettings $commandSettings,
    ) {
    }

    public function validate(string $command): ?string
    {
        $command = trim($command);
        if ($command === '') {
            return 'Command is required.';
        }

        if (strlen($command) > self::MAX_LENGTH) {
            return 'Command is too long.';
        }

        if (preg_match('/[\r\n\0]/', $command)) {
            return 'Command contains invalid characters.';
        }

        foreach (self::DISALLOWED_TOKENS as $token) {
            if (str_contains($command, $token)) {
                return 'Command contains unsupported characters.';
            }
        }

        $allowed = $this->commandSettings->getCustomerConsoleAllowedCommands();
        if ($allowed !== []) {
            $commandName = strtolower(strtok($command, ' ') ?: $command);
            $allowedNormalized = array_map(static fn (string $value): string => strtolower($value), $allowed);
            if (!in_array($commandName, $allowedNormalized, true)) {
                return 'Command is not allowed.';
            }
        }

        return null;
    }
}
