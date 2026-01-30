<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Gameserver\Application\ConsoleCommandValidator;
use App\Module\Gameserver\Application\ConsoleCommandSettings;
use PHPUnit\Framework\TestCase;

final class ConsoleCommandValidatorTest extends TestCase
{
    public function testRejectsShellOperators(): void
    {
        $settings = new class implements ConsoleCommandSettings {
            public function getCustomerConsoleAllowedCommands(): array
            {
                return [];
            }
        };
        $validator = new ConsoleCommandValidator($settings);

        $error = $validator->validate('status; rm -rf /');

        self::assertSame('Command contains unsupported characters.', $error);
    }

    public function testAllowsWhitelistedCommand(): void
    {
        $settings = new class implements ConsoleCommandSettings {
            public function getCustomerConsoleAllowedCommands(): array
            {
                return ['status', 'say'];
            }
        };
        $validator = new ConsoleCommandValidator($settings);

        $error = $validator->validate('status');

        self::assertNull($error);
    }

    public function testRejectsNonWhitelistedCommand(): void
    {
        $settings = new class implements ConsoleCommandSettings {
            public function getCustomerConsoleAllowedCommands(): array
            {
                return ['status'];
            }
        };
        $validator = new ConsoleCommandValidator($settings);

        $error = $validator->validate('restart');

        self::assertSame('Command is not allowed.', $error);
    }
}
