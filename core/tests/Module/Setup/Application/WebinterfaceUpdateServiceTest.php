<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup\Application;

use App\Module\Setup\Application\WebinterfaceUpdateService;
use App\Module\Setup\Application\WebinterfaceUpdateSettingsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebinterfaceUpdateServiceTest extends TestCase
{
    public function testCheckForUpdateParsesFeedArtifact(): void
    {
        $feed = [
            'latest' => '1.2.3',
            'releases' => [[
                'version' => '1.2.3',
                'artifacts' => [
                    'core_novendor_targz' => [
                        'url' => 'https://example.invalid/core-novendor-1.2.3.tar.gz',
                        'sha256' => 'abc123',
                    ],
                ],
                'changelog' => 'Fixes',
            ]],
        ];

        $service = new WebinterfaceUpdateService(
            new MockHttpClient([new MockResponse((string) json_encode($feed))]),
            $this->createStub(WebinterfaceUpdateSettingsService::class),
            'https://example.invalid/feed.json',
            '/tmp/install',
            '/tmp/releases',
            '/tmp/current',
            '/tmp/lock',
            '',
            '1.0.0',
            '',
            'stable',
            'test',
            false,
        );

        $status = $service->checkForUpdate();
        self::assertSame('1.2.3', $status->latestVersion);
        self::assertSame('https://example.invalid/core-novendor-1.2.3.tar.gz', $status->assetUrl);
        self::assertSame('abc123', $status->assetSha256);
    }
}
