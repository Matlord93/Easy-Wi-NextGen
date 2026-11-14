<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\MusicbotWorkflowService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MusicbotWorkflowInputValidationTest extends TestCase
{
    #[DataProvider('malformedActions')]
    public function testMalformedActionsFailWithAValidationError(array $actions, string $message): void
    {
        $service = (new \ReflectionClass(MusicbotWorkflowService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(MusicbotWorkflowService::class, 'validateActions');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $method->invoke($service, $actions);
    }

    public static function malformedActions(): iterable
    {
        yield 'nested type' => [[['type' => ['invalid']]], 'Unknown action type'];
        yield 'scalar entry' => [['invalid'], 'Unknown action type'];
        yield 'nested webhook URL' => [[['type' => 'send_webhook', 'config' => ['url' => ['invalid']]]], 'requires a "url"'];
    }
}
