<?php

declare(strict_types=1);

namespace App\Tests\Module\Teamspeak\Application\Query;

use App\Module\Teamspeak\Application\Query\QueryCommandBuilder;
use App\Module\Teamspeak\Application\Query\QueryCommandException;
use PHPUnit\Framework\TestCase;

final class QueryCommandBuilderTest extends TestCase
{
    public function testBuildsValidCommand(): void
    {
        $builder = new QueryCommandBuilder();
        $command = $builder->build('clientlist', ['uid' => 'test', 'clid' => 5]);

        self::assertSame('clientlist', $command->command());
        self::assertSame(['uid' => 'test', 'clid' => 5], $command->args());
    }

    public function testRejectsInvalidCommand(): void
    {
        $builder = new QueryCommandBuilder();

        $this->expectException(QueryCommandException::class);
        $builder->build('client list');
    }

    public function testRejectsInvalidArgs(): void
    {
        $builder = new QueryCommandBuilder();

        $this->expectException(QueryCommandException::class);
        $builder->build('clientlist', ['invalid' => ['nested']]);
    }
}
