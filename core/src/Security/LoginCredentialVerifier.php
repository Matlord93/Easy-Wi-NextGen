<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginCredentialVerifier
{
    private User $dummyUser;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        $this->dummyUser = new User('unknown@example.invalid', UserType::Customer);
        $this->dummyUser->setPasswordHash($this->passwordHasher->hashPassword($this->dummyUser, random_bytes(16)));
    }

    public function isValid(?User $user, string $plainPassword): bool
    {
        if ($user === null) {
            $this->passwordHasher->isPasswordValid($this->dummyUser, $plainPassword);

            return false;
        }

        return $this->passwordHasher->isPasswordValid($user, $plainPassword);
    }
}
