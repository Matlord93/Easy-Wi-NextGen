<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Application\Voice\ViewerSnapshotNormalizer;
use PHPUnit\Framework\TestCase;

final class ViewerSnapshotNormalizerTest extends TestCase
{
    public function testNormalizesAlternativeTs6FieldNamesAndFiltersQueryClients(): void
    {
        $normalized = ViewerSnapshotNormalizer::normalize([
            'snapshot' => [
                'server' => ['name' => 'TS Server 1'],
                'channels' => [
                    ['cid' => 1, 'pid' => 0, 'channel_name' => 'Root', 'channel_order' => 10],
                    ['channel_id' => '2', 'parent_id' => '1', 'name' => 'Games'],
                ],
                'clients' => [
                    ['clid' => 10, 'cid' => 2, 'client_nickname' => 'Alice', 'client_type' => 0],
                    ['client_id' => 11, 'channel_id' => 2, 'nickname' => 'Query', 'client_type' => 1],
                ],
            ],
        ]);

        self::assertCount(2, $normalized['channels']);
        self::assertSame('1', $normalized['channels'][0]['id']);
        self::assertNull($normalized['channels'][0]['parentId']);
        self::assertSame('2', $normalized['channels'][1]['id']);
        self::assertSame('1', $normalized['channels'][1]['parentId']);
        self::assertCount(1, $normalized['clients']);
        self::assertSame('Alice', $normalized['clients'][0]['nickname']);
        self::assertSame('2', $normalized['clients'][0]['channelId']);
    }
}

