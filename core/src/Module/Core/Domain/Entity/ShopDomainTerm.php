<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/** @psalm-api Immutable confirmed domain registration/renewal period. */
#[ORM\Entity, ORM\Table(name: 'shop_domain_terms')]
#[ORM\UniqueConstraint(name: 'uniq_domain_term_reference', columns: ['contract_id', 'reference'])]
class ShopDomainTerm
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private ShopContract $contract,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true)] private ?User $actor,
        #[ORM\Column(length: 120)] private string $reference,
        #[ORM\Column] private \DateTimeImmutable $startsAt,
        #[ORM\Column] private \DateTimeImmutable $endsAt,
        #[ORM\Column] private int $amountCents,
    ) {
    }
}
