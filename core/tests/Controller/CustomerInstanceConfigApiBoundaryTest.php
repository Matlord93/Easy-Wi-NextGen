<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceConfigApiController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class CustomerInstanceConfigApiBoundaryTest extends TestCase
{
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $controller = (new \ReflectionClass(CustomerInstanceConfigApiController::class))->newInstanceWithoutConstructor();
        return (new \ReflectionMethod($controller, $method))->invoke($controller, ...$arguments);
    }

    public function testTemplateMergeEscapesQuotesAndPreservesUnknownLines(): void
    {
        self::assertSame("// comment\nname \"New \\\"name\\\"\"\nmaxplayers \"32\"\n", $this->invoke(
            'renderTemplateContent',
            ['apply_mode' => 'merge_kv'],
            ['values' => ['name' => 'New "name"', 'maxplayers' => 32]],
            "// comment\nname \"Old\"",
            [],
        ));
    }

    public function testPropertiesMergeKeepsExistingKeysAndConvertsScalars(): void
    {
        self::assertSame("motd=Welcome\nenabled=0\n", $this->invoke(
            'renderTemplateContent',
            ['apply_mode' => 'properties'],
            ['values' => ['enabled' => 0]],
            "motd=Welcome\nenabled=1\n",
            [],
        ));
    }

    public function testNestedConfigValuesAreRejectedBeforeStringConversion(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->invoke('renderTemplateContent', ['apply_mode' => 'properties'], ['values' => ['motd' => ['invalid']]], '', []);
    }

    public function testRequestParsingPreservesScalarAndNestedObjectFields(): void
    {
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"values":{"enabled":false,"slots":32},"content":"text"}');
        self::assertSame(['values' => ['enabled' => false, 'slots' => 32], 'content' => 'text'], $this->invoke('parsePayload', $request));
    }

    public function testRequestIdFallsBackToAttribute(): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Request-ID', '0');
        $request->attributes->set('request_id', ' fallback-id ');
        self::assertSame('fallback-id', $this->invoke('resolveRequestId', $request));
    }
}
