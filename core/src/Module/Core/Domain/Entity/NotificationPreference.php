<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\NotificationPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationPreferenceRepository::class)]
#[ORM\Table(name: 'notification_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_notification_preference_user_category', columns: ['recipient_id', 'category'])]
class NotificationPreference
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)] #[ORM\JoinColumn(name: 'recipient_id', nullable: false, onDelete: 'CASCADE')] private User $recipient,
        #[ORM\Column(length: 32)] private string $category,
        #[ORM\Column] private bool $inAppEnabled = true,
        #[ORM\Column] private bool $emailEnabled = false,
        #[ORM\Column] private bool $webhookEnabled = false,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $category)) {
            throw new \InvalidArgumentException('Invalid notification category.');
        }
    }

    public function isInAppEnabled(): bool { return $this->inAppEnabled; }
    public function isEmailEnabled(): bool { return $this->emailEnabled; }
    public function isWebhookEnabled(): bool { return $this->webhookEnabled; }
    public function getCategory(): string { return $this->category; }
    public function update(bool $inApp, bool $email, bool $webhook): void
    {
        $this->inAppEnabled = $inApp;
        $this->emailEnabled = $email;
        $this->webhookEnabled = $webhook;
    }
}
