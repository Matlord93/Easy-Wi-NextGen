<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotTrackPathResolver;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use PHPUnit\Framework\TestCase;

final class MusicbotTrackPathResolverTest extends TestCase
{
    public function testAbsoluteTrackPathIsNotJoinedUnderInstanceData(): void
    {
        [$resolver, $instance, $dir] = $this->fixture();
        $path = $dir . '/instance/data/tracks/customer-2/song.mp3';
        mkdir(dirname($path), 0770, true);
        file_put_contents($path, 'mp3');

        self::assertSame($path, $resolver->resolveLocalPath($path, $instance));
    }

    public function testRelativeVarMusicbotTrackPathResolvesToTrackRootWithoutDoubleDataPrefix(): void
    {
        [$resolver, $instance, $dir] = $this->fixture();
        $path = $dir . '/instance/data/tracks/customer-2/song.mp3';
        mkdir(dirname($path), 0770, true);
        file_put_contents($path, 'mp3');

        self::assertSame($path, $resolver->resolveLocalPath('var/musicbot/tracks/customer-2/song.mp3', $instance));
    }

    public function testRadioUrlAndTraversalAreRejectedAsLocalPaths(): void
    {
        [$resolver, $instance] = $this->fixture();

        self::assertNull($resolver->resolveLocalPath('https://radio.example/stream', $instance));
        self::assertNull($resolver->resolveLocalPath('../secret.mp3', $instance, false));
    }

    /** @return array{MusicbotTrackPathResolver, MusicbotInstance, string} */
    private function fixture(): array
    {
        $dir = sys_get_temp_dir() . '/easywi-track-resolver-' . bin2hex(random_bytes(6));
        mkdir($dir . '/project/var/musicbot/tracks', 0770, true);
        mkdir($dir . '/instance/data/tracks', 0770, true);
        $customer = new User('customer@example.test', UserType::Customer);
        $agent = new Agent('agent-uuid-1', ['token' => 'hash'], 'Test Agent');
        $instance = new MusicbotInstance($customer, $agent, 'Bot', 'musicbot-test', $dir . '/instance');

        return [new MusicbotTrackPathResolver($dir . '/project'), $instance, $dir];
    }
}
