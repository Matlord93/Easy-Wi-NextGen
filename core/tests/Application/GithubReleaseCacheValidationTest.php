<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\GithubReleaseResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class GithubReleaseCacheValidationTest extends TestCase
{
    public function testMalformedCacheMetadataCannotReachHttpHeadersOrStatusValues(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem('github_release.'.hash('sha256', 'owner/repo|stable|core'));
        $item->set(['releases' => 'invalid', 'etag' => ['unexpected'], 'rate_limit_reset' => ['unexpected'], 'expires_at' => 'invalid']);
        $cache->save($item);
        $client = new MockHttpClient();
        $resolver = new GithubReleaseResolver($client, null, $cache);
        $status = $resolver->getCacheStatus('owner/repo', 'stable', 'core');

        self::assertFalse($status['has_cache']);
        self::assertNull($status['rate_limit_reset']);
        self::assertNull($status['expires_at']);
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testLegacyNumericMetadataAndCachedReleasesRemainUsable(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem('github_release.'.hash('sha256', 'owner/repo|stable|core'));
        $item->set(['releases' => [['tag_name' => 'v1.2.3']], 'expires_at' => (string) (time() + 3600)]);
        $cache->save($item);
        $client = new MockHttpClient();
        $resolver = new GithubReleaseResolver($client, null, $cache);

        self::assertSame('v1.2.3', $resolver->getLatestVersion('owner/repo', 'stable', 'core'));
        self::assertSame(0, $client->getRequestsCount());
    }
}
