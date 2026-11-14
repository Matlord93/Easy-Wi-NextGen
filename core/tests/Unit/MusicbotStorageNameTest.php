<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\UI\Controller\Admin\AdminMusicbotController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MusicbotStorageNameTest extends TestCase
{
    #[DataProvider('names')]
    public function testDisplayNamesCannotIntroduceDirectoryOrWrapperSyntax(string $name): void
    {
        $controller = (new \ReflectionClass(AdminMusicbotController::class))->newInstanceWithoutConstructor();
        $serviceName = (new \ReflectionMethod(AdminMusicbotController::class, 'buildServiceName'))->invoke($controller, $name);

        self::assertIsString($serviceName);
        self::assertMatchesRegularExpression('/^musicbot-[a-z0-9-]{1,32}-[a-f0-9]{6}$/D', $serviceName);
        self::assertSame($serviceName, basename($serviceName));
    }

    public static function names(): iterable
    {
        yield 'traversal' => ['../../secrets'];
        yield 'windows' => ['..\\..\\private'];
        yield 'wrapper and NUL' => ["php://filter\0/resource=secret"];
        yield 'empty after normalization' => ['///'];
        yield 'ordinary name' => ['My Musicbot 42'];
    }
}
