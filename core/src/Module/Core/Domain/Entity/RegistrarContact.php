<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/** @psalm-api Customer-specific registrar handles; never substitute the operator as domain owner. */
#[ORM\Entity, ORM\Table(name: 'registrar_contacts')]
#[ORM\UniqueConstraint(name: 'uniq_registrar_contact_owner', columns: ['customer_id', 'identity'])]
class RegistrarContact
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private User $customer,
        #[ORM\Column(length: 64)] private string $identity,
        #[ORM\Column] private int $contactId,
    ) {
    }

    public function getContactId(): int
    {
        return $this->contactId;
    }
}
