<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/** @psalm-api Transactional outbox: mail is sent only after the business transaction commits. */
#[ORM\Entity, ORM\Table(name: 'rental_notices')]
#[ORM\UniqueConstraint(name: 'uniq_rental_notice_event', columns: ['event_key'])]
#[ORM\Index(name: 'idx_rental_notice_pending', columns: ['sent_at', 'next_attempt_at'])]
class RentalNotice
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $sentAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $nextAttemptAt = null;
    #[ORM\Column] private int $attempts = 0;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private User $recipient,
        #[ORM\Column(length: 120)] private string $eventKey,
        #[ORM\Column(length: 160)] private string $title,
        #[ORM\Column(length: 500)] private string $body,
        #[ORM\Column(length: 255)] private string $actionUrl,
    ) {
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getNextAttemptAt(): ?\DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getActionUrl(): string
    {
        return $this->actionUrl;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function attempted(\DateTimeImmutable $at): void
    {
        ++$this->attempts;
        $this->nextAttemptAt = $at->modify('+15 minutes');
    }

    public function sent(\DateTimeImmutable $at): void
    {
        $this->sentAt = $at;
    }
}
