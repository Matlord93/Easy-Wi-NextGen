<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

interface AgentReleaseCheckerInterface
{
    public function getLatestVersionForChannel(string $channel, bool $force = false): ?string;

    /** @return array{version:string,download_url:string,checksums_url:string,signature_url:?string,asset_name:string,channel:string}|null */
    public function getReleaseAssetUrlsForChannel(string $assetName, string $channel, ?string $targetVersion = null, bool $force = false): ?array;

    public function isUpdateAvailable(?string $currentVersion, ?string $latestVersion = null): ?bool;

    /** @param array<string, mixed> $releaseInfo */
    public function releaseAssetRequiresPanelProxy(array $releaseInfo): bool;
}
