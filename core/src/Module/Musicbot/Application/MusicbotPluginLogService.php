<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPluginLog;
use App\Repository\MusicbotPluginLogRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotPluginLogService
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly MusicbotPluginLogRepository $repository) {}

    /** @param array<string, mixed> $context */
    public function log(MusicbotInstance $instance, string $pluginId, string $event, ?string $action, string $status, string $message, array $context = []): MusicbotPluginLog
    {
        $log = new MusicbotPluginLog($instance, $pluginId, $event, $action, $status, $message, $context);
        $this->entityManager->persist($log);
        $this->entityManager->flush();
        return $log;
    }

    /** @return MusicbotPluginLog[] */
    public function forInstance(MusicbotInstance $instance, int $limit = 50): array { return $this->repository->findForInstance($instance, $limit); }
}
