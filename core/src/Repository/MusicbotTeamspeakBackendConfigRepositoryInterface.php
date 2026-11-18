<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Musicbot\Domain\Entity\MusicbotTeamspeakBackendConfig;

/** @extends \Doctrine\Persistence\ObjectRepository<MusicbotTeamspeakBackendConfig> */

interface MusicbotTeamspeakBackendConfigRepositoryInterface extends \Doctrine\Persistence\ObjectRepository
{
    public function findOneByNode(Agent $node): ?MusicbotTeamspeakBackendConfig;
}
