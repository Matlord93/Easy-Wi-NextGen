<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Ts6;

use App\Module\Core\Dto\Ts6\ViewerDto;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Domain\Entity\Ts6Viewer;
use App\Repository\Ts6ViewerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class Ts6ViewerService
{
    public function __construct(
        private readonly AgentClient $agentClient,
        private readonly CacheInterface $cache,
        private readonly EntityManagerInterface $entityManager,
        private readonly Ts6ViewerRepository $viewerRepository,
    ) {
    }

    public function enableViewer(Ts6VirtualServer $server, ViewerDto $dto): Ts6Viewer
    {
        $viewer = $this->viewerRepository->findOneBy(['virtualServer' => $server]);
        if ($viewer === null) {
            $viewer = new Ts6Viewer($server, bin2hex(random_bytes(16)));
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

        $cacheKey = sprintf('ts6_viewer_%s', $publicId);
        $ttlSeconds = max(1, (int) ceil($viewer->getCacheTtlMs() / 1000));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($viewer, $ttlSeconds): array {
            $item->expiresAfter($ttlSeconds);
            $server = $viewer->getVirtualServer();
            $payload = $this->agentClient->request(
                $server->getNode(),
                'GET',
                sprintf('/v1/ts6/virtual-servers/%d/viewer-snapshot', $server->getSid()),
            );

            return [
                'status' => 'ok',
                'server' => $payload['server'] ?? ['sid' => $server->getSid(), 'name' => $server->getName()],
                'channels' => $payload['channels'] ?? [],
                'clients' => $payload['clients'] ?? [],
                'generated_at' => $payload['generated_at'] ?? (new \DateTimeImmutable())->format(DATE_ATOM),
            ];
        });
    }

    private function isDomainAllowed(Ts6Viewer $viewer, ?string $originOrReferer): bool
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
