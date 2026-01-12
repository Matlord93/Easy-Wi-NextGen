<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CustomerProfile;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\ConsentLogRepository;
use App\Repository\CustomerProfileRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class GdprAnonymizer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerProfileRepository $profileRepository,
        private readonly UserSessionRepository $sessionRepository,
        private readonly ApiTokenRepository $apiTokenRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly ConsentLogRepository $consentLogRepository,
    ) {
    }

    public function anonymize(User $customer): void
    {
        $email = sprintf('deleted+%d@privacy.invalid', $customer->getId() ?? 0);
        $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);

        $customer->anonymize($email, $passwordHash);
        $this->entityManager->persist($customer);

        $profile = $this->profileRepository->findOneByCustomer($customer);
        if ($profile instanceof CustomerProfile) {
            $profile->setFirstName('Anonymized');
            $profile->setLastName('User');
            $profile->setAddress('Redacted');
            $profile->setPostal('00000');
            $profile->setCity('Redacted');
            $profile->setCountry('ZZ');
            $profile->setPhone(null);
            $profile->setCompany(null);
            $profile->setVatId(null);
            $this->entityManager->persist($profile);
        }

        $this->sessionRepository->deleteByUser($customer);
        $this->apiTokenRepository->deleteByCustomer($customer);
        $this->notificationRepository->deleteByRecipient($customer);
        $this->consentLogRepository->redactByUser($customer, '0.0.0.0', 'anonymized');
    }
}
