<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Application\MailService;
use App\Module\Core\Application\NotificationService;
use App\Module\Core\Domain\Entity\RentalNotice;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\User;
use App\Repository\InvoicePreferencesRepository;
use App\Repository\NotificationPreferenceRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class RentalNotices
{
    public function __construct(private EntityManagerInterface $em, private NotificationService $notifications, private MailService $mail, private PaymentSettings $settings, private NotificationPreferenceRepository $preferences, private InvoicePreferencesRepository $invoicePreferences, private LoggerInterface $logger)
    {
    }

    public function customer(User $customer, string $key, string $title, string $body, string $url): void
    {
        if (!str_starts_with($url, '/') || str_starts_with($url, '//') || strlen($key) > 120) {
            throw new \InvalidArgumentException('Invalid rental notice.');
        }
        if (null !== $this->em->getRepository(RentalNotice::class)->findOneBy(['eventKey' => $key])) {
            return;
        }
        $body = mb_substr($body, 0, 500);
        $this->em->persist(new RentalNotice($customer, $key, $title, $body, $url));
        $this->notifications->notify($customer, $key, $title, mb_substr($body, 0, 255), 'billing', $url);
    }

    public function contract(ShopContract $contract, string $event, string $title, string $body): void
    {
        $id = \App\Infrastructure\Doctrine\EntityIdentifier::require($contract->getId());
        $this->customer($contract->getCustomer(), 'rental.'.$id.'.'.$event, $title, $body, '/marketplace/contracts/'.$id);
    }

    /** Bounded, independent transactions; repeated failures stay visible to operators. */
    public function deliver(\DateTimeImmutable $now): void
    {
        /** @var list<RentalNotice> $notices */
        $notices = $this->em->getRepository(RentalNotice::class)->createQueryBuilder('n')->where('n.sentAt IS NULL AND n.attempts < 5 AND (n.nextAttemptAt IS NULL OR n.nextAttemptAt <= :now)')->setParameter('now', $now, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)->orderBy('n.id')->setMaxResults(100)->getQuery()->getResult();
        foreach ($notices as $notice) {
            $this->em->wrapInTransaction(function () use ($notice, $now): void {
                $this->em->refresh($notice, LockMode::PESSIMISTIC_WRITE);
                if (null !== $notice->getSentAt() || (null !== $notice->getNextAttemptAt() && $notice->getNextAttemptAt() > $now) || $notice->getAttempts() >= 5) {
                    return;
                }
                $customer = $notice->getRecipient();
                $preference = $this->preferences->findFor($customer, 'billing');
                $invoicePreference = $this->invoicePreferences->findOneByCustomer($customer);
                $notice->attempted($now);
                if ((null !== $preference && !$preference->isEmailEnabled()) || (null !== $invoicePreference && !$invoicePreference->isEmailDelivery())) {
                    $notice->sent($now);

                    return;
                }
                try {
                    if ($this->mail->sendTemplate($customer->getEmail(), 'rental_notice', ['title' => $notice->getTitle(), 'body' => $notice->getBody(), 'action_url' => $this->settings->publicSettings()['base_url'].$notice->getActionUrl()], $invoicePreference?->getLocale(), true)) {
                        $notice->sent($now);
                    }
                } catch (\Throwable $error) {
                    $this->logger->error('rental.notice.failed', ['exception_type' => $error::class]);
                }
            });
        }
    }
}
