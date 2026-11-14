<?php

namespace PsalmMappingFixture;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class MappedRecord
{
    #[ORM\Id, ORM\Column]
    private int $id = 1;

    #[ORM\Column]
    private string $stored = 'persisted';

    private string $unmapped = 'still unused';
}

final class OrdinaryRecord
{
    #[ORM\Column]
    private string $notEntity = 'an attribute alone is not an ORM entity';

    public function run(): void
    {
    }
}

(new OrdinaryRecord())->run();

#[ORM\Entity, ORM\Table(name: 'promoted_records')]
class PromotedRecord
{
    public function __construct(
        #[ORM\Id, ORM\Column] private int $id = 1,
        #[ORM\Column] private string $stored = 'persisted',
        #[ORM\ManyToOne(targetEntity: MappedRecord::class)] private ?MappedRecord $related = null,
        private string $unmappedPromoted = 'still unused',
    ) {
    }
}
