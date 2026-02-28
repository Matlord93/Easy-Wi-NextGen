<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MailForwardRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MailForwardRepository::class)]
#[ORM\Table(name: 'mail_forwards')]
/** @deprecated Legacy flow, replaced by MailAlias destinations. */
class MailForward
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Domain $domain;

    #[ORM\Column(length: 190)]
    private string $sourceLocalPart;

    #[ORM\Column(length: 255)]
    private string $destination;

    #[ORM\Column]
    private bool $enabled = true;

    public function __construct(Domain $domain, string $sourceLocalPart, string $destination, bool $enabled = true)
    {
        $this->domain = $domain;
        $this->sourceLocalPart = $sourceLocalPart;
        $this->destination = $destination;
        $this->enabled = $enabled;
    }
}
