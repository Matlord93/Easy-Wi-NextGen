<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Domain\Enum\JobResultStatus;
use App\Module\Gameserver\Application\Query\QueryResultNormalizer;
use PHPUnit\Framework\TestCase;

final class QueryResultNormalizerTest extends TestCase
{
    public function testFailureClearsReportedFields(): void
    {
        $output = [
            'status' => 'offline',
            'players' => '12',
            'max_players' => '32',
            'message' => 'timeout',
        ];

        $normalized = QueryResultNormalizer::fromAgentOutput($output, 'steam_a2s', JobResultStatus::Failed);

        self::assertNull($normalized['reported']['players']);
        self::assertNull($normalized['reported']['max_players']);
        self::assertSame('timeout', $normalized['error']);
    }

    public function testSourceQueryNormalizesMapAndSlots(): void
    {
        $output = [
            'status' => 'running',
            'players' => '4',
            'max_players' => '20',
            'map' => 'de_dust2',
        ];

        $normalized = QueryResultNormalizer::fromAgentOutput($output, 'steam_a2s', JobResultStatus::Succeeded);

        self::assertSame('source', $normalized['engine']);
        self::assertSame(20, $normalized['reported']['max_players']);
        self::assertSame('de_dust2', $normalized['reported']['map']);
        self::assertTrue($normalized['capabilities']['map']);
    }

    public function testMinecraftJavaNormalizesPlayersAndSlots(): void
    {
        $output = [
            'status' => 'online',
            'players' => '3',
            'max_players' => '12',
            'motd' => 'Test',
            'version' => '1.20.4',
        ];

        $normalized = QueryResultNormalizer::fromAgentOutput($output, 'minecraft_java', JobResultStatus::Succeeded);

        self::assertSame('minecraft_java', $normalized['engine']);
        self::assertSame(3, $normalized['reported']['players']);
        self::assertSame(12, $normalized['reported']['max_players']);
        self::assertSame('Test', $normalized['reported']['motd']);
        self::assertSame('1.20.4', $normalized['reported']['version']);
    }
}
