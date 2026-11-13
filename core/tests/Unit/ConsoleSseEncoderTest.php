<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\Http\TypedStreamResponse;
use App\Module\Gameserver\Application\ConsoleSseEncoder;
use PHPUnit\Framework\TestCase;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\Console\ConsoleEventBusInterface;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceConsoleStreamController;
use App\Repository\InstanceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ConsoleSseEncoderTest extends TestCase
{
    public function testDataCannotInjectHtmlOrAdditionalSseFrames(): void
    {
        $payload = ['seq' => 12, 'text' => "</script><img src=x onerror=alert(1)>\n\nevent: injected\ndata: injected", 'quotes' => "\"'&"];
        $encoded = ConsoleSseEncoder::encode($payload, "status\n\ndata: injected");
        self::assertStringStartsWith("id: 12\nevent: chunk\ndata: ", $encoded);
        self::assertStringNotContainsString('<', $encoded);
        self::assertSame(1, substr_count($encoded, "\n\n"));
        $data = explode("\n", $encoded)[2];
        self::assertSame($payload, json_decode(substr($data, 6), true, 32, JSON_THROW_ON_ERROR));
    }

    public function testBinaryConsoleBytesDoNotBreakTheStream(): void
    {
        $encoded = ConsoleSseEncoder::encode(['text' => "a\xffb"], 'status', -1);
        self::assertStringStartsWith("event: status\ndata: ", $encoded);
        self::assertStringContainsString('\\ufffd', $encoded);
    }

    public function testTypedDownloadsPreserveArbitraryBytes(): void
    {
        $sql = "INSERT INTO t VALUES ('</script>\"');\n\0\xff";
        ob_start();
        try {
            $response = new TypedStreamResponse('application/sql; charset=UTF-8', static function (\Closure $write) use ($sql): void {
                $write($sql);
            });
            $response->sendContent();
            self::assertSame($sql, ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public function testStreamCannotBeChangedIntoAnHtmlResponse(): void
    {
        $response = new TypedStreamResponse('text/event-stream', static function (\Closure $write): void {
            $write('must not be sent');
        });
        $response->headers->set('Content-Type', 'text/html');
        $this->expectException(\InvalidArgumentException::class);
        $response->sendContent();
    }

    public function testStreamCannotDisableNosniff(): void
    {
        $response = new TypedStreamResponse('text/event-stream', static function (\Closure $write): void {
            $write('must not be sent');
        });
        $response->headers->remove('X-Content-Type-Options');
        $this->expectException(\LogicException::class);
        $response->sendContent();
    }

    public function testStreamTypeCannotInjectHeaders(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TypedStreamResponse("text/event-stream; charset=UTF-8\r\nContent-Type: text/html", static function (\Closure $write): void {
        });
    }

    public function testControllerReplaysEventsThroughTheTypedStreamAndReleasesSubscriber(): void
    {
        $owner = new User('owner@example.test', UserType::Customer);
        $instance = $this->createStub(Instance::class);
        $instance->method('getCustomer')->willReturn($owner);
        $repository = $this->createStub(InstanceRepository::class);
        $repository->method('find')->willReturn($instance);
        $bus = $this->createMock(ConsoleEventBusInterface::class);
        $bus->expects(self::once())->method('incrementSubscriber')->with(1);
        $bus->expects(self::once())->method('decrementSubscriber')->with(1);
        $payload = ['seq' => 12, 'text' => "</script>\n\"quoted\""];
        $bus->method('replayConsoleEvents')->willReturn([$payload]);
        $controller = new CustomerInstanceConsoleStreamController($repository, $bus, $this->createStub(TranslatorInterface::class), 0);
        $request = Request::create('/instances/1/console/stream');
        $request->attributes->set('current_user', $owner);
        $response = $controller->stream($request, 1);
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $level = ob_get_level();
        ob_start();
        ob_start();
        try {
            $response->sendContent();
            $inner = ob_get_clean();
            $wire = ob_get_clean().$inner;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        self::assertStringStartsWith("id: 12\nevent: chunk\ndata: ", $wire);
        self::assertStringNotContainsString('</script>', $wire);
        self::assertSame($payload, json_decode(substr(explode("\n", $wire)[2], 6), true, 32, JSON_THROW_ON_ERROR));
    }
}
