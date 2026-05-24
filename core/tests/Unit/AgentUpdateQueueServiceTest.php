<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Application\AgentReleaseChecker;
use App\Module\Core\Application\AgentReleaseCheckerInterface;
use App\Module\Core\Application\AgentUpdateQueueService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Repository\AgentRepository;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AgentUpdateQueueServiceTest extends TestCase
{
    public function testAnalyzeManualCandidatesMarksRunningAndQueuedAsBlockedWithReason(): void
    {
        $agent = $this->createAgent('agent-running');
        $runningJob = new Job('agent.update', ['agent_id' => $agent->getId(), 'version' => 'v1.1.0']);
        $runningJob->transitionTo(JobStatus::Running);

        $service = $this->createService([$runningJob], [
            'easywi-agent-linux-amd64.tar.gz' => [
                'version' => 'v1.2.0',
                'download_url' => 'https://example.invalid/agent',
                'checksums_url' => 'https://example.invalid/checksums',
                'asset_name' => 'easywi-agent-linux-amd64.tar.gz',
            ],
        ]);

        $analysis = $service->analyzeManualUpdateCandidates([$agent], 'v1.2.0', AgentReleaseChecker::CHANNEL_STABLE);

        self::assertCount(1, $analysis['blocked']);
        self::assertSame('already_running', $analysis['blocked'][0]['reason']);
        self::assertCount(0, $analysis['eligible']);
    }

    public function testAnalyzeManualCandidatesSupportsLinuxArm64TarGz(): void
    {
        $agent = $this->createAgent('agent-arm', 'linux', 'arm64');
        $service = $this->createService([], [
            'easywi-agent-linux-arm64.tar.gz' => [
                'version' => 'v1.2.0',
                'download_url' => 'https://example.invalid/agent-arm64.tar.gz',
                'checksums_url' => 'https://example.invalid/checksums',
                'asset_name' => 'easywi-agent-linux-arm64.tar.gz',
            ],
        ]);

        $analysis = $service->analyzeManualUpdateCandidates([$agent], 'v1.2.0', AgentReleaseChecker::CHANNEL_STABLE);

        self::assertCount(1, $analysis['eligible']);
        self::assertCount(0, $analysis['skipped']);
    }

    public function testAnalyzeManualCandidatesSkipsWhenNoReleaseAssetFound(): void
    {
        $agent = $this->createAgent('agent-linux');
        $service = $this->createService([], []);

        $analysis = $service->analyzeManualUpdateCandidates([$agent], 'v1.2.0', AgentReleaseChecker::CHANNEL_STABLE);

        self::assertCount(1, $analysis['skipped']);
        self::assertSame('no_release_asset', $analysis['skipped'][0]['reason']);
        self::assertSame('easywi-agent-linux-amd64.tar.gz', $analysis['skipped'][0]['expectedAssetName']);
    }

    private function createAgent(string $id, string $os = 'linux', string $arch = 'amd64'): Agent
    {
        $agent = new Agent($id, ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], $id);
        $agent->recordHeartbeat(['os' => $os, 'arch' => $arch], 'v1.0.0', '127.0.0.1');

        return $agent;
    }

    /** @param Job[] $jobs @param array<string,array<string,mixed>> $assets */
    private function createService(array $jobs, array $assets): AgentUpdateQueueService
    {
        $agentRepository = $this->createMock(AgentRepository::class);
        $jobRepository = $this->createMock(JobRepository::class);
        $releaseChecker = $this->createMock(AgentReleaseCheckerInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $jobRepository->method('findLatestByType')->willReturnCallback(static function (string $type) use ($jobs): array {
            return array_values(array_filter($jobs, static fn (Job $job): bool => $job->getType() === $type));
        });

        $releaseChecker->method('getReleaseAssetUrlsForChannel')->willReturnCallback(static function (string $assetName) use ($assets): ?array {
            return $assets[$assetName] ?? null;
        });
        $releaseChecker->method('isUpdateAvailable')->willReturnCallback(static function (?string $current, ?string $latest): bool {
            return version_compare((string) preg_replace('/^[^0-9]*/', '', $latest ?? ''), (string) preg_replace('/^[^0-9]*/', '', $current ?? ''), '>');
        });
        $releaseChecker->method('releaseAssetRequiresPanelProxy')->willReturn(false);

        return new AgentUpdateQueueService($agentRepository, $jobRepository, $releaseChecker, $entityManager);
    }
}
