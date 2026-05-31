<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\PanelAdmin\UI\Controller\Admin\AdminPluginCatalogController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdminPluginCatalogControllerChecksumTest extends TestCase
{
    public function testCreatePayloadAcceptsEmptyChecksum(): void
    {
        $payload = $this->parseCreatePayload('');

        self::assertSame([], $payload['errors']);
        self::assertSame('', $payload['checksum']);
    }

    public function testCreatePayloadAcceptsValidSha256Checksum(): void
    {
        $checksum = str_repeat('a', 64);

        $payload = $this->parseCreatePayload($checksum);

        self::assertSame([], $payload['errors']);
        self::assertSame($checksum, $payload['checksum']);
    }

    public function testCreatePayloadRejectsManualVerificationPlaceholder(): void
    {
        $payload = $this->parseCreatePayload('manual-verification-required');

        self::assertContains('Checksum must be empty or a valid MD5, SHA1, SHA256, or SHA512 hex digest.', $payload['errors']);
    }

    public function testImportEntryAcceptsEmptyChecksumValidation(): void
    {
        $errors = $this->parseImportErrors('');

        self::assertContains('Entry 1: Template reference is required (game_key/template_game_key).', $errors);
        self::assertNotContains('Entry 1: Checksum must be empty or a valid MD5, SHA1, SHA256, or SHA512 hex digest.', $errors);
    }

    public function testImportEntryAcceptsValidSha256ChecksumValidation(): void
    {
        $errors = $this->parseImportErrors(str_repeat('b', 64));

        self::assertContains('Entry 1: Template reference is required (game_key/template_game_key).', $errors);
        self::assertNotContains('Entry 1: Checksum must be empty or a valid MD5, SHA1, SHA256, or SHA512 hex digest.', $errors);
    }

    public function testImportEntryRejectsManualVerificationPlaceholder(): void
    {
        $errors = $this->parseImportErrors('manual-verification-required');

        self::assertContains('Entry 1: Checksum must be empty or a valid MD5, SHA1, SHA256, or SHA512 hex digest.', $errors);
    }

    public function testRecommendedSeedEntriesUseEmptyChecksumForDynamicPlugins(): void
    {
        $entries = $this->buildRecommendedSeedEntries();
        $entriesByName = [];
        foreach ($entries as $entry) {
            $entriesByName[$entry['name']] = $entry;
        }

        foreach (['MetaMod:Source', 'CounterStrikeSharp', 'uMod (Oxide)', 'LuckPerms', 'EssentialsX', 'SourceMod'] as $pluginName) {
            self::assertArrayHasKey($pluginName, $entriesByName);
            self::assertSame('', $entriesByName[$pluginName]['checksum']);
        }
    }

    /** @return array{errors: array<int, string>, checksum: string} */
    private function parseCreatePayload(string $checksum): array
    {
        $request = new Request([], [
            'game_key' => 'cs2',
            'name' => 'Metamod:Source',
            'version' => 'latest',
            'checksum' => $checksum,
            'download_url' => 'github://alliedmodders/metamod-source/releases/latest?asset=mmsource-*-linux.tar.gz',
            'description' => '',
        ]);

        $method = new \ReflectionMethod(AdminPluginCatalogController::class, 'parsePayload');

        return $method->invoke($this->controller(), $request);
    }

    /** @return array<int, string> */
    private function parseImportErrors(string $checksum): array
    {
        $errors = [];
        $method = new \ReflectionMethod(AdminPluginCatalogController::class, 'parseImportEntry');
        $method->invokeArgs($this->controller(), [[
            'game_key' => '',
            'name' => 'Metamod:Source',
            'version' => 'latest',
            'checksum' => $checksum,
            'download_url' => 'github://alliedmodders/metamod-source/releases/latest?asset=mmsource-*-linux.tar.gz',
            'description' => '',
        ], 0, &$errors]);

        return $errors;
    }

    /** @return array<int, array{game_key:string,name:string,version:string,checksum:string,download_url:string,description:string}> */
    private function buildRecommendedSeedEntries(): array
    {
        $method = new \ReflectionMethod(AdminPluginCatalogController::class, 'buildRecommendedSeedEntries');

        return $method->invoke($this->controller());
    }

    private function controller(): AdminPluginCatalogController
    {
        $reflection = new \ReflectionClass(AdminPluginCatalogController::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
