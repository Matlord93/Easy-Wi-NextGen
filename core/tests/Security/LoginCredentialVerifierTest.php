<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Security\LoginCredentialVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginCredentialVerifierTest extends TestCase
{
    public function testUnknownUserPathStillExecutesPasswordValidationWork(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher
            ->expects(self::exactly(2))
            ->method('isPasswordValid')
            ->willReturnOnConsecutiveCalls(false, true);
        $hasher
            ->method('hashPassword')
            ->willReturn('$2y$13$wVQ9zH65PmU3WzDnqV6OueyT3m4uQW4s9uXh/Gd9wR1r8QjQeXrFq');

        $verifier = new LoginCredentialVerifier($hasher);

        self::assertFalse($verifier->isValid(null, 'wrong-password'));

        $user = new User('known@example.test', UserType::Customer);
        $user->setPasswordHash('hash');
        self::assertTrue($verifier->isValid($user, 'secret'));
    }
}
