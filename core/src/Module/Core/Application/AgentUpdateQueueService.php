<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Repository\AgentRepository;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AgentUpdateQueueService
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly JobRepository $jobRepository,
        private readonly AgentReleaseChecker $releaseChecker,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{total:int,queued:int,skipped:int,latestVersion:?string,requiresPanelProxy:bool}
     */
    public function queueAvailableUpdates(string $channel, bool $force = false): array
    {
        $agents = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $latestVersion = $this->releaseChecker->getLatestVersionForChannel($channel, $force);
        if ($this->updatesRequirePanelProxy($agents, $latestVersion, $channel)) {
            return [
                'total' => count($agents),
                'queued' => 0,
                'skipped' => count($agents),
                'latestVersion' => $latestVersion,
                'requiresPanelProxy' => true,
            ];
        }

        $queued = $this->queueAgentUpdates($agents, $latestVersion, $this->buildUpdateJobIndex($agents), $channel);

        return [
            'total' => count($agents),
            'queued' => $queued,
            'skipped' => max(0, count($agents) - $queued),
            'latestVersion' => $latestVersion,
            'requiresPanelProxy' => false,
        ];
    }

    /** @param Agent[] $agents */
    public function updatesRequirePanelProxy(array $agents, ?string $latestVersion, string $channel): bool
    {
        foreach ($agents as $agent) {
            $releaseInfo = $this->resolveAgentReleaseInfo($agent, $latestVersion, $channel);
            if ($releaseInfo !== null && $this->releaseChecker->releaseAssetRequiresPanelProxy($releaseInfo)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, Job> */
    public function buildUpdateJobIndex(array $agents): array
    {
        if ($agents === []) {
            return [];
        }

        $agentIds = array_map(static fn (Agent $agent): string => $agent->getId(), $agents);
        $limit = max(50, count($agents) * 4);
        $jobs = array_merge(
            $this->jobRepository->findLatestByType('agent.update', $limit),
            $this->jobRepository->findLatestByType('agent.self_update', $limit),
        );
        usort($jobs, static function (Job $a, Job $b): int {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        $index = [];
        foreach ($jobs as $job) {
            $payload = $job->getPayload();
            $agentId = is_string($payload['agent_id'] ?? null) ? $payload['agent_id'] : null;
            if ($agentId === null || $agentId === '') {
                continue;
            }

            if (!in_array($agentId, $agentIds, true)) {
                continue;
            }

            if (!array_key_exists($agentId, $index)) {
                $index[$agentId] = $job;
            }
        }

        return $index;
    }

    /**
     * @param Agent[] $agents
     * @param array<string, Job> $existingJobs
     */
    public function queueAgentUpdates(array $agents, ?string $latestVersion, array $existingJobs, string $channel): int
    {
        $queued = 0;
        foreach ($agents as $agent) {
            $payload = $this->buildAgentUpdatePayload($agent, $latestVersion, $channel);
            if ($payload === null) {
                continue;
            }

            $existingJob = $existingJobs[$agent->getId()] ?? null;
            if ($existingJob !== null) {
                if ($existingJob->getStatus() === JobStatus::Running) {
                    continue;
                }

                if ($existingJob->getStatus() === JobStatus::Queued) {
                    $existingPayload = $existingJob->getPayload();
                    $existingVersion = is_string($existingPayload['version'] ?? null) ? (string) $existingPayload['version'] : null;
                    $newVersion = is_string($payload['version'] ?? null) ? (string) $payload['version'] : null;

                    if ($existingVersion !== null && $newVersion !== null
                        && $this->releaseChecker->isUpdateAvailable($existingVersion, $newVersion) === true) {
                        $existingJob->transitionTo(JobStatus::Cancelled);
                    } else {
                        continue;
                    }
                }
            }

            $job = new Job($this->resolveAgentUpdateJobType($agent), $payload);
            $this->entityManager->persist($job);
            $queued++;
        }

        $this->entityManager->flush();

        return $queued;
    }

    private function buildAgentUpdatePayload(Agent $agent, ?string $latestVersion, string $channel): ?array
    {
        $analysis = $this->analyzeAgentUpdatePayload($agent, $latestVersion, $channel);

        return $analysis['payload'];
    }

    /** @return array{payload:?array,reason:?string} */
    private function analyzeAgentUpdatePayload(Agent $agent, ?string $latestVersion, string $channel): array
    {
        $currentVersion = $agent->getLastHeartbeatVersion();
        $stats = $agent->getLastHeartbeatStats() ?? [];
        $os = is_string($stats['os'] ?? null) ? strtolower($stats['os']) : '';
        $arch = is_string($stats['arch'] ?? null) ? strtolower($stats['arch']) : '';

        $assetName = $this->resolveAgentAssetName($os, $arch);
        if ($assetName === null) {
            return ['payload' => null, 'reason' => 'unsupported_platform'];
        }

        $releaseInfo = $this->releaseChecker->getReleaseAssetUrlsForChannel($assetName, $channel, $latestVersion);
        if ($releaseInfo === null) {
            return ['payload' => null, 'reason' => 'no_release_asset'];
        }

        $version = $releaseInfo['version'] ?? null;
        if (!is_string($version) || $version === '') {
            return ['payload' => null, 'reason' => 'missing_release_version'];
        }

        if (($releaseInfo['download_url'] ?? null) === null || ($releaseInfo['checksums_url'] ?? null) === null || ($releaseInfo['asset_name'] ?? null) === null) {
            return ['payload' => null, 'reason' => 'release_check_failed'];
        }

        if ($this->releaseChecker->isUpdateAvailable($currentVersion, $latestVersion ?? $version) !== true) {
            return ['payload' => null, 'reason' => 'not_outdated'];
        }

        if ($this->releaseChecker->releaseAssetRequiresPanelProxy($releaseInfo)) {
            return ['payload' => null, 'reason' => 'panel_proxy_required'];
        }

        return ['payload' => [
            'agent_id' => $agent->getId(),
            'download_url' => $releaseInfo['download_url'],
            'checksums_url' => $releaseInfo['checksums_url'],
            'signature_url' => $releaseInfo['signature_url'] ?? null,
            'asset_name' => $releaseInfo['asset_name'],
            'version' => $version,
            'channel' => $channel,
        ], 'reason' => 'eligible'];
    }

    /** @return array<string, mixed>|null */
    private function resolveAgentReleaseInfo(Agent $agent, ?string $latestVersion, string $channel): ?array
    {
        $stats = $agent->getLastHeartbeatStats() ?? [];
        $os = is_string($stats['os'] ?? null) ? strtolower($stats['os']) : '';
        $arch = is_string($stats['arch'] ?? null) ? strtolower($stats['arch']) : '';

        $assetName = $this->resolveAgentAssetName($os, $arch);
        if ($assetName === null) {
            return null;
        }

        return $this->releaseChecker->getReleaseAssetUrlsForChannel($assetName, $channel, $latestVersion);
    }

    private function resolveAgentAssetName(string $os, string $arch): ?string
    {
        if ($os === 'linux' && $arch === 'amd64') {
            return 'easywi-agent-linux-amd64';
        }

        if ($os === 'windows' && $arch === 'amd64') {
            return 'easywi-agent-windows-amd64.exe';
        }

        return null;
    }

    private function resolveAgentUpdateJobType(Agent $agent): string
    {
        $stats = $agent->getLastHeartbeatStats() ?? [];
        $os = is_string($stats['os'] ?? null) ? strtolower($stats['os']) : '';

        return $os === 'windows' ? 'agent.self_update' : 'agent.update';
    }

    /**
     * @param Agent[] $agents
     *
     * @return array{eligible: array<int, Agent>, blocked: array<int, array<string, string>>, skipped: array<int, array<string, string>>}
     */
    public function analyzeManualUpdateCandidates(array $agents, ?string $latestVersion, string $channel): array
    {
        $existingJobs = $this->buildUpdateJobIndex($agents);
        $eligible = [];
        $blocked = [];
        $skipped = [];

        foreach ($agents as $agent) {
            $existingJob = $existingJobs[$agent->getId()] ?? null;
            if ($existingJob !== null && in_array($existingJob->getStatus(), [JobStatus::Queued, JobStatus::Running], true)) {
                $blocked[] = [
                    'jobId' => $existingJob->getId(),
                    'agentId' => $agent->getId(),
                    'agentName' => $agent->getName(),
                    'status' => $existingJob->getStatus()->value,
                    'reason' => $existingJob->getStatus() === JobStatus::Running ? 'already_running' : 'already_queued',
                    'createdAt' => $existingJob->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    'updatedAt' => $existingJob->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                ];
                continue;
            }

            $analysis = $this->analyzeAgentUpdatePayload($agent, $latestVersion, $channel);
            if (($analysis['payload'] ?? null) === null) {
                $skipped[] = [
                    'agentId' => $agent->getId(),
                    'agentName' => $agent->getName() ?? '',
                    'reason' => (string) ($analysis['reason'] ?? 'release_check_failed'),
                ];
                continue;
            }

            $eligible[] = $agent;
        }

        return ['eligible' => $eligible, 'blocked' => $blocked, 'skipped' => $skipped];
    }
}
