<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Module\Core\Application\PrivacyGdprBackgroundJobRunnerInterface;
use App\Module\Core\Command\GdprExportCleanupCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GdprExportCleanupCommandTest extends TestCase
{
    #[DataProvider('cases')]
    public function testExecutesExportJob(array $options, int $limit, int $count, string $message): void
    {
        $runner = $this->createMock(PrivacyGdprBackgroundJobRunnerInterface::class);
        $runner->expects($this->once())->method('deleteExpiredExportsOnly')->with($limit)->willReturn($count);
        $tester = new CommandTester(new GdprExportCleanupCommand($runner));

        self::assertSame(Command::SUCCESS, $tester->execute($options));
        self::assertStringContainsString($message, $tester->getDisplay());
    }

    public static function cases(): iterable
    {
        yield 'default empty queue' => [[], 100, 0, 'No expired GDPR exports to delete.'];
        yield 'explicit batch' => [['--limit' => '7'], 7, 3, 'Deleted 3 expired GDPR export(s).'];
        yield 'minimum batch' => [['--limit' => '0'], 1, 0, 'No expired GDPR exports to delete.'];
    }
}
