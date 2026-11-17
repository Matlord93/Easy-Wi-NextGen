<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotQueueItem;

interface MusicbotQueueItemQueryRepositoryInterface extends MusicbotQueueItemRepositoryInterface
{
    public function findOneForCustomer(int $id, User $customer): ?MusicbotQueueItem;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return list<MusicbotQueueItem>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;
}
