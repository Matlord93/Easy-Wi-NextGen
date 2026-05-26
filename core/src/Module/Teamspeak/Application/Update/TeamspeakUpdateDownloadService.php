<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Update;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TeamspeakUpdateDownloadService
{
    public function __construct(private readonly HttpClientInterface $httpClient, private readonly ?string $githubToken = null) {}

    public function download(string $url, string $targetFile, int $maxBytes = 1073741824): void
    {
        if (!str_starts_with($url, 'https://github.com/teamspeak/teamspeak6-server/')) {
            throw new \RuntimeException('Unzulässige Download-Quelle.');
        }
        $dir = dirname($targetFile);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) { throw new \RuntimeException('Download-Verzeichnis nicht beschreibbar.'); }
        $headers = [];
        if ($this->githubToken !== null && $this->githubToken !== '') { $headers['Authorization'] = 'Bearer '.$this->githubToken; }
        $response = $this->httpClient->request('GET', $url, ['timeout' => 60, 'headers' => $headers]);
        $content = $response->getContent();
        if (strlen($content) > $maxBytes) { throw new \RuntimeException('Download überschreitet Maximalgröße.'); }
        file_put_contents($targetFile, $content, LOCK_EX);
    }

    public function verifySha256(string $file, ?string $expectedSha256, bool $requireChecksum): ChecksumVerificationResult
    {
        if ($expectedSha256 === null || trim($expectedSha256) === '') {
            return new ChecksumVerificationResult(false, true, null, $requireChecksum ? 'Checksum erforderlich, aber nicht verfügbar.' : 'Checksum nicht verfügbar.');
        }
        $actual = hash_file('sha256', $file);
        if (!hash_equals(strtolower($expectedSha256), strtolower($actual))) {
            return new ChecksumVerificationResult(false, false, $actual, 'Checksum ungültig.');
        }
        return new ChecksumVerificationResult(true, false, $actual, null);
    }
}
