<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/** @psalm-api Hydrated and persisted through Doctrine metadata. */
#[ORM\Entity, ORM\Table(name: 'infrastructure_samples')]
#[ORM\Index(name: 'idx_infra_sample_retention', columns: ['bucket'])]
class InfrastructureSample
{
    public function __construct(
        #[ORM\Id, ORM\Column(length: 64)] private string $resourceKey,
        #[ORM\Id, ORM\Column(length: 4)] private string $stream,
        #[ORM\Id, ORM\Column(type: 'bigint')] private int $bucket,
        #[ORM\Column(type: 'bigint')] private int $observedAt,
        #[ORM\Column(type: 'json')] private array $payload,
    ) {
    }
}
