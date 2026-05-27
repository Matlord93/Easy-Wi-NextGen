<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GithubReleaseAssetUrlResolver
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function resolve(string $downloadUrl): ?string
    {
        if (!preg_match('#^github://([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+)/releases/latest\?(.*)$#', $downloadUrl, $matches)) {
            return null;
        }

        parse_str($matches[3], $query);
        $assetPattern = is_string($query['asset'] ?? null) ? trim($query['asset']) : '';
        if ($assetPattern === '') {
            return null;
        }

        $apiUrl = sprintf('https://api.github.com/repos/%s/%s/releases/latest', $matches[1], $matches[2]);

        try {
            $response = $this->httpClient->request('GET', $apiUrl, [
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                ],
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $assets = is_array($data['assets'] ?? null) ? $data['assets'] : [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $name = is_string($asset['name'] ?? null) ? $asset['name'] : '';
            $browserDownloadUrl = is_string($asset['browser_download_url'] ?? null) ? trim($asset['browser_download_url']) : '';
            if ($name === '' || $browserDownloadUrl === '') {
                continue;
            }

            if (fnmatch($assetPattern, $name)) {
                return $browserDownloadUrl;
            }
        }

        return null;
    }
}
