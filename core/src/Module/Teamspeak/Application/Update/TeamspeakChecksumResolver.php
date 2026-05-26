<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Update;

final class TeamspeakChecksumResolver
{
    /** @param array<string,mixed> $asset @param array<int,array<string,mixed>> $assets */
    public function resolve(array $asset, array $assets, string $releaseBody): ChecksumInfo
    {
        $digest = (string) ($asset['digest'] ?? '');
        if (str_starts_with($digest, 'sha256:')) {
            return new ChecksumInfo('sha256', strtolower(substr($digest, 7)), 'asset_digest');
        }

        $assetName = (string) ($asset['name'] ?? '');
        $shaAsset = $this->findShaAsset($assetName, $assets);
        if ($shaAsset !== null) {
            return new ChecksumInfo('sha256', null, 'sha256_asset');
        }

        $fromBody = $this->parseFromBody($assetName, $releaseBody);
        if ($fromBody !== null) {
            return new ChecksumInfo('sha256', strtolower($fromBody), 'release_body');
        }

        return new ChecksumInfo(null, null, null);
    }

    /** @param array<int,array<string,mixed>> $assets */
    public function findShaAsset(string $assetName, array $assets): ?array
    {
        $target = strtolower($assetName.'.sha256');
        foreach ($assets as $candidate) {
            $name = strtolower((string) ($candidate['name'] ?? ''));
            if ($name === $target || $name === strtolower($assetName).'.sha256.txt') {
                return $candidate;
            }
        }
        return null;
    }

    public function parseFromBody(string $assetName, string $body): ?string
    {
        $pattern = '/([a-fA-F0-9]{64})\s+(?:\*?'.preg_quote($assetName, '/').')/';
        if (preg_match($pattern, $body, $m) === 1) {
            return $m[1];
        }
        return null;
    }
}
