<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Dto;

use App\Module\Core\Dto\Ts6\Ts6NodeDto;
use PHPUnit\Framework\TestCase;

final class Ts6NodeDtoTest extends TestCase
{
    public function testDefaultDownloadUrlUsesCurrentTarXzRelease(): void
    {
        self::assertSame(
            'https://github.com/teamspeak/teamspeak6-server/releases/download/v6.0.0-beta10/teamspeak6-server-linux-amd64.tar.xz',
            Ts6NodeDto::DEFAULT_DOWNLOAD_URL,
        );
    }
}
