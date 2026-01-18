<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Ts3;

use App\Module\Core\Dto\Ts3\ViewerDto;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\Ts3Viewer;
use App\Repository\Ts3ViewerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class Ts3ViewerService
{
    public function __construct(
        private readonly \App\Module\AgentOrchestrator\Application\AgentJobDispatcher $jobDispatcher,
        private readonly CacheInterface $cache,
        private readonly EntityManagerInterface $entityManager,
        private readonly Ts3ViewerRepository $viewerRepository,
    ) {
    }

    public function enableViewer(Ts3VirtualServer $server, ViewerDto $dto): Ts3Viewer
    {
        $viewer = $this->viewerRepository->findOneBy(['virtualServer' => $server]);
        if ($viewer === null) {
            $viewer = new Ts3Viewer($server, bin2hex(random_bytes(16)));
            $this->entityManager->persist($viewer);
        }

        $viewer->setEnabled($dto->enabled);
        $viewer->setCacheTtlMs($dto->cacheTtlMs);
        $viewer->setDomainAllowlist($dto->domainAllowlist);
        $this->entityManager->flush();

        return $viewer;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPublicSnapshot(string $publicId, ?string $originOrReferer): array
    {
        $viewer = $this->viewerRepository->findOneBy(['publicId' => $publicId]);
        if ($viewer === null || !$viewer->isEnabled()) {
            throw new \RuntimeException('Viewer not found.');
        }

        if (!$this->isDomainAllowed($viewer, $originOrReferer)) {
            throw new \RuntimeException('Domain not allowed.');
        }

        $cacheKey = sprintf('ts3_viewer_%s', $publicId);
        $ttlSeconds = max(1, (int) ceil($viewer->getCacheTtlMs() / 1000));

        $snapshot = $this->cache->get($cacheKey, function (ItemInterface $item) use ($viewer, $ttlSeconds): array {
            $item->expiresAfter($ttlSeconds);
            return [];
        });

        if ($snapshot === []) {
            $server = $viewer->getVirtualServer();
            $payload = [
                'virtual_server_id' => $server->getId(),
                'node_id' => $server->getNode()->getId(),
                'sid' => $server->getSid(),
                'cache_key' => $cacheKey,
            ];
            $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts3.viewer.snapshot', $payload);

            return [
                'status' => 'pending',
                'server' => ['sid' => $server->getSid(), 'name' => $server->getName()],
                'channels' => [],
                'clients' => [],
                'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ];
        }

        return $snapshot;
    }

    private function isDomainAllowed(Ts3Viewer $viewer, ?string $originOrReferer): bool
    {
        $allowlist = $viewer->getDomainAllowlistEntries();
        if ($allowlist === []) {
            return true;
        }

        if ($originOrReferer === null || $originOrReferer === '') {
            return false;
        }

        $host = parse_url($originOrReferer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        foreach ($allowlist as $entry) {
            if ($entry === $host) {
                return true;
            }

            if (str_starts_with($entry, '*.')) {
                $suffix = substr($entry, 2);
                if ($suffix !== '' && str_ends_with($host, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
