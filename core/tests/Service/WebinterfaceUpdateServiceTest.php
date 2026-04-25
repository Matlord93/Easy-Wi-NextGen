<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Setup\Application\WebinterfaceUpdateService;
use App\Module\Setup\Application\WebinterfaceUpdateSettingsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebinterfaceUpdateServiceTest extends TestCase
{
    public function testFallsBackToGithubReleaseManifestWhenConfiguredManifestIsUnavailable(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 404]),
            new MockResponse(json_encode([
                [
                    'draft' => false,
                    'prerelease' => false,
                    'tag_name' => 'v1.2.3',
                    'assets' => [
                        [
                            'name' => 'manifest.json',
                            'browser_download_url' => 'https://example.test/manifest.json',
                        ],
                    ],
                    'body' => 'release notes',
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse(json_encode([
                'latest' => '1.2.3',
                'asset_url' => 'https://example.test/easywi-webinterface-1.2.3.zip',
                'sha256' => 'abc',
                'notes' => 'notes from manifest',
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $tmpDir = sys_get_temp_dir() . '/easywi-update-test-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0775, true);
        file_put_contents($tmpDir . '/VERSION', "1.2.2\n");

        $service = new WebinterfaceUpdateService(
            $httpClient,
            $this->createStub(WebinterfaceUpdateSettingsService::class),
            'https://localhost/manifest.json',
            $tmpDir,
            '',
            '',
            $tmpDir . '/update.lock',
            '',
            '1.2.2',
            'Matlord93/Easy-Wi-NextGen',
            'stable',
            'test',
            false,
        );

        $status = $service->checkForUpdate();

        self::assertNull($status->error);
        self::assertSame('1.2.2', $status->installedVersion);
        self::assertSame('1.2.3', $status->latestVersion);
        self::assertTrue($status->updateAvailable);
        self::assertSame('notes from manifest', $status->notes);
    }

    public function testBuildsManifestFromReleaseAssetsWhenManifestAssetMissing(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse(json_encode([
                [
                    'draft' => false,
                    'prerelease' => false,
                    'tag_name' => 'v2.0.0',
                    'assets' => [
                        [
                            'name' => 'easywi-webinterface-2.0.0.zip',
                            'browser_download_url' => 'https://example.test/easywi-webinterface-2.0.0.zip',
                        ],
                    ],
                    'body' => 'Generated fallback notes',
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $tmpDir = sys_get_temp_dir() . '/easywi-update-test-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0775, true);
        file_put_contents($tmpDir . '/VERSION', "1.9.0\n");

        $service = new WebinterfaceUpdateService(
            $httpClient,
            $this->createStub(WebinterfaceUpdateSettingsService::class),
            'https://localhost/manifest.json',
            $tmpDir,
            '',
            '',
            $tmpDir . '/update.lock',
            '',
            '1.9.0',
            'Matlord93/Easy-Wi-NextGen',
            'stable',
            'test',
            false,
        );

        $status = $service->checkForUpdate();

        self::assertNull($status->error);
        self::assertSame('2.0.0', $status->latestVersion);
        self::assertTrue($status->updateAvailable);
        self::assertSame('Generated fallback notes', $status->notes);
        self::assertSame('https://example.test/easywi-webinterface-2.0.0.zip', $status->assetUrl);
    }
}
