<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\MusicbotProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

final class MusicbotProcessRunnerTest extends TestCase
{
    public function testShellSyntaxIsDeliveredAsLiteralArguments(): void
    {
        $args = ['; echo injected', '$(echo injected)', '`echo injected`', '"quote"', '--exec=whoami', 'space separated'];
        $result = MusicbotProcessRunner::run([PHP_BINARY, '-r', 'echo json_encode(array_slice($argv, 1));', '--', ...$args], 5);
        self::assertSame(0, $result['exit_code']);
        self::assertSame($args, json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR));
    }

    public function testDrainsStderrWithoutBlockingStdout(): void
    {
        $result = MusicbotProcessRunner::run([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("e", 262144)); echo "done";'], 5);
        self::assertSame(0, $result['exit_code']);
        self::assertSame('done', $result['stdout']);
        self::assertSame(262144, strlen($result['stderr']));
    }

    public function testSilentProcessCannotBypassTimeout(): void
    {
        $this->expectException(ProcessTimedOutException::class);
        MusicbotProcessRunner::run([PHP_BINARY, '-r', 'usleep(3000000);'], 0.1);
    }

    public function testOutputLimitStopsAnOtherwiseLongRunningChild(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('configured limit');
        MusicbotProcessRunner::run([PHP_BINARY, '-r', 'echo str_repeat("x", 32768); sleep(10);'], 5, 1024);
    }
}
