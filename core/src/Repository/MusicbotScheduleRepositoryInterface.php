<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotSchedule;

/** @extends \Doctrine\Persistence\ObjectRepository<MusicbotSchedule> */

interface MusicbotScheduleRepositoryInterface extends \Doctrine\Persistence\ObjectRepository
{
    /** @return MusicbotSchedule[] */
    public function findByInstance(MusicbotInstance $instance): array;

    /** @return MusicbotSchedule[] */
    public function findByCustomerAndInstance(User $customer, MusicbotInstance $instance): array;

    /** @return MusicbotSchedule[] */
    public function findDue(\DateTimeImmutable $now): array;

    /**
     * @param array{customer_id?: int, instance_id?: int} $filters
     * @return MusicbotSchedule[]
     */
    public function findForAdmin(array $filters = [], int $limit = 200): array;
}
