<?php

declare(strict_types=1);

namespace App\Tests\Teamspeak;

use App\Module\Teamspeak\Application\Update\TeamspeakChecksumResolver;
use PHPUnit\Framework\TestCase;

final class TeamspeakChecksumResolverTest extends TestCase
{
    public function testResolvesDigestFromAssetMetadata(): void
    {
        $r = new TeamspeakChecksumResolver();
        $asset = ['name' => 'teamspeak6-server-linux-amd64.tar.xz', 'digest' => 'sha256:'.str_repeat('a',64)];
        $info = $r->resolve($asset, [$asset], '');
        self::assertTrue($info->isAvailable());
        self::assertSame(str_repeat('a',64), $info->value);
    }

    public function testResolvesHashFromBody(): void
    {
        $r = new TeamspeakChecksumResolver();
        $asset = ['name' => 'teamspeak6-server-linux-amd64.tar.xz'];
        $body = str_repeat('b',64).'  teamspeak6-server-linux-amd64.tar.xz';
        $info = $r->resolve($asset, [$asset], $body);
        self::assertSame(str_repeat('b',64), $info->value);
    }

    public function testDetectsShaAssetWithoutValue(): void
    {
        $r = new TeamspeakChecksumResolver();
        $asset = ['name' => 'teamspeak6-server-linux-amd64.tar.xz'];
        $sha = ['name' => 'teamspeak6-server-linux-amd64.tar.xz.sha256'];
        $info = $r->resolve($asset, [$asset, $sha], '');
        self::assertSame('sha256_asset', $info->source);
        self::assertFalse($info->isAvailable());
    }
}
