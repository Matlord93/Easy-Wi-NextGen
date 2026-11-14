<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Module\Core\Application\PrivacyGdprBackgroundJobRunnerInterface;
use App\Module\Core\Command\GdprExportProcessCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GdprExportProcessCommandTest extends TestCase
{
    #[DataProvider('cases')]
    public function testExecutesExportJob(array $options, int $limit, int $count, string $message): void
    {
        $runner = $this->createMock(PrivacyGdprBackgroundJobRunnerInterface::class);
        $runner->expects($this->once())->method('processPendingExportsOnly')->with($limit)->willReturn($count);
        $tester = new CommandTester(new GdprExportProcessCommand($runner));

        self::assertSame(Command::SUCCESS, $tester->execute($options));
        self::assertStringContainsString($message, $tester->getDisplay());
    }

    public static function cases(): iterable
    {
        yield 'default empty queue' => [[], 25, 0, 'No pending GDPR exports.'];
        yield 'explicit batch' => [['--limit' => '7'], 7, 3, 'Processed 3 GDPR export request(s).'];
        yield 'minimum batch' => [['--limit' => '0'], 1, 0, 'No pending GDPR exports.'];
    }
}
