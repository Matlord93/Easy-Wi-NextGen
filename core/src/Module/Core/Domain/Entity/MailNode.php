<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MailNodeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MailNodeRepository::class)]
#[ORM\Table(name: 'mail_nodes')]
class MailNode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $imapHost;

    #[ORM\Column]
    private int $imapPort;

    #[ORM\Column(length: 255)]
    private string $smtpHost;

    #[ORM\Column]
    private int $smtpPort;

    #[ORM\Column(length: 255)]
    private string $roundcubeUrl;

    public function __construct(string $name, string $imapHost, int $imapPort, string $smtpHost, int $smtpPort, string $roundcubeUrl)
    {
        $this->name = $name;
        $this->imapHost = $imapHost;
        $this->imapPort = $imapPort;
        $this->smtpHost = $smtpHost;
        $this->smtpPort = $smtpPort;
        $this->roundcubeUrl = $roundcubeUrl;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function getImapHost(): string
    {
        return $this->imapHost;
    }
    public function getImapPort(): int
    {
        return $this->imapPort;
    }
    public function getSmtpHost(): string
    {
        return $this->smtpHost;
    }
    public function getSmtpPort(): int
    {
        return $this->smtpPort;
    }
    public function getRoundcubeUrl(): string
    {
        return $this->roundcubeUrl;
    }
}
