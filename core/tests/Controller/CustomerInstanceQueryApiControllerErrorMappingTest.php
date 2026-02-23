<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceQueryApiController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CustomerInstanceQueryApiControllerErrorMappingTest extends TestCase
{
    public function testMissingHostErrorMapsToDedicatedErrorCode(): void
    {
        $controller = (new ReflectionClass(CustomerInstanceQueryApiController::class))->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod(CustomerInstanceQueryApiController::class, 'resolveQueryErrorCode');
        $method->setAccessible(true);

        $code = $method->invoke($controller, 'missing required values: host');

        self::assertSame('INVALID_INSTANCE_HOST', $code);
    }

    public function testMissingHostErrorMapsToActionableMessage(): void
    {
        $controller = (new ReflectionClass(CustomerInstanceQueryApiController::class))->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod(CustomerInstanceQueryApiController::class, 'resolveQueryErrorMessage');
        $method->setAccessible(true);

        $message = $method->invoke($controller, 'missing required values: host', 'INVALID_INSTANCE_HOST', 42);

        self::assertSame('Cannot resolve query host for instance 42 (missing bind_ip/node_ip)', $message);
    }

    public function testOtherInvalidInputMessagesStayUnchanged(): void
    {
        $controller = (new ReflectionClass(CustomerInstanceQueryApiController::class))->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod(CustomerInstanceQueryApiController::class, 'resolveQueryErrorMessage');
        $method->setAccessible(true);

        $message = $method->invoke($controller, 'invalid input payload', 'INVALID_INPUT', 42);

        self::assertSame('invalid input payload', $message);
    }
}
