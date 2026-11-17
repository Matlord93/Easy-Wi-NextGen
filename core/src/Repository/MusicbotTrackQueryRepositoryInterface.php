<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Musicbot\Domain\Entity\MusicbotTrack;

interface MusicbotTrackQueryRepositoryInterface extends MusicbotTrackRepositoryInterface
{
    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return MusicbotTrack|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return list<MusicbotTrack>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;
}
