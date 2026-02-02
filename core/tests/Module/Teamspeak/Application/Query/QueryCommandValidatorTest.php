<?php

declare(strict_types=1);

namespace App\Tests\Module\Teamspeak\Application\Query;

use App\Module\Teamspeak\Application\Query\QueryCommandException;
use App\Module\Teamspeak\Application\Query\QueryCommandValidator;
use PHPUnit\Framework\TestCase;

final class QueryCommandValidatorTest extends TestCase
{
    public function testAllowsWhitelistedCommands(): void
    {
        $validator = new QueryCommandValidator(['clientlist' => true, 'channellist' => true]);

        $validator->assertAllowed('clientlist');
        $validator->assertAllowed('channellist');

        $this->addToAssertionCount(1);
    }

    public function testRejectsUnknownCommands(): void
    {
        $validator = new QueryCommandValidator(['clientlist' => true]);

        $this->expectException(QueryCommandException::class);
        $validator->assertAllowed('clientdelete');
    }
}
