<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\MusicbotYoutubeService;
use PHPUnit\Framework\TestCase;

final class MusicbotYoutubeProcessTest extends TestCase
{
    public function testArgumentsArePassedLiterallyAndExitStatusIsPreserved(): void
    {
        $argument = '$(printf injected); "quoted" & data';
        $result = $this->runProcess([PHP_BINARY, '-r', 'fwrite(STDOUT, $argv[1]); fwrite(STDERR, "error"); exit(7);', '--', $argument]);

        self::assertSame($argument, $result['stdout']);
        self::assertSame('error', $result['stderr']);
        self::assertSame(7, $result['exit_code']);
    }

    public function testStderrIsDrainedWhileWaitingForStdout(): void
    {
        $result = $this->runProcess([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("e", 200000)); fwrite(STDOUT, "done");']);

        self::assertSame('done', $result['stdout']);
        self::assertSame(200000, strlen($result['stderr']));
        self::assertSame(0, $result['exit_code']);
    }

    /**
     * @param list<string> $arguments
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runProcess(array $arguments): array
    {
        $service = (new \ReflectionClass(MusicbotYoutubeService::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(MusicbotYoutubeService::class, 'runProcess'))->invoke($service, $arguments);
    }
}
