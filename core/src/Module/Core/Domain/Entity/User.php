<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Enum\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
#[ORM\Index(name: 'idx_users_email_verification_token', columns: ['email_verification_token_hash'])]
#[ORM\Index(name: 'idx_users_reseller', columns: ['reseller_id'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $name = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $emailVerificationTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerificationExpiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $termsAcceptedAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $termsAcceptedIp = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $privacyAcceptedAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $privacyAcceptedIp = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminSignature = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminSshPublicKey = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'reseller_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $resellerOwner = null;

    public function __construct(string $email, UserType $type)
    {
        $this->email = strtolower($email);
        $this->type = $type->value;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = strtolower($email);
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function getType(): UserType
    {
        return UserType::from($this->type);
    }

    public function setType(UserType $type): void
    {
        $this->type = $type->value;
    }

    public function getRoles(): array
    {
        return match ($this->getType()) {
            UserType::Admin => ['ROLE_ADMIN'],
            UserType::Superadmin => ['ROLE_SUPERADMIN', 'ROLE_ADMIN'],
            UserType::Reseller => ['ROLE_RESELLER'],
            default => ['ROLE_CUSTOMER'],
        };
    }

    public function eraseCredentials(): void
    {
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isAdmin(): bool
    {
        return $this->getType()->isAdmin();
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $normalized = $name !== null ? trim($name) : null;
        $this->name = $normalized === '' ? null : $normalized;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): void
    {
        $this->emailVerifiedAt = $emailVerifiedAt;
    }

    public function getEmailVerificationTokenHash(): ?string
    {
        return $this->emailVerificationTokenHash;
    }

    public function setEmailVerificationTokenHash(?string $emailVerificationTokenHash): void
    {
        $this->emailVerificationTokenHash = $emailVerificationTokenHash;
    }

    public function getEmailVerificationExpiresAt(): ?\DateTimeImmutable
    {
        return $this->emailVerificationExpiresAt;
    }

    public function setEmailVerificationExpiresAt(?\DateTimeImmutable $emailVerificationExpiresAt): void
    {
        $this->emailVerificationExpiresAt = $emailVerificationExpiresAt;
    }

    public function getTermsAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function getTermsAcceptedIp(): ?string
    {
        return $this->termsAcceptedIp;
    }

    public function getPrivacyAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->privacyAcceptedAt;
    }

    public function getPrivacyAcceptedIp(): ?string
    {
        return $this->privacyAcceptedIp;
    }

    public function recordConsents(string $ipAddress, \DateTimeImmutable $acceptedAt): void
    {
        $this->termsAcceptedAt = $acceptedAt;
        $this->termsAcceptedIp = $ipAddress;
        $this->privacyAcceptedAt = $acceptedAt;
        $this->privacyAcceptedIp = $ipAddress;
    }

    public function getAdminSignature(): ?string
    {
        return $this->adminSignature;
    }

    public function setAdminSignature(?string $adminSignature): void
    {
        $this->adminSignature = $adminSignature === '' ? null : $adminSignature;
    }

    public function getAdminSshPublicKey(): ?string
    {
        return $this->adminSshPublicKey;
    }

    public function setAdminSshPublicKey(?string $adminSshPublicKey): void
    {
        $normalized = $adminSshPublicKey !== null ? trim($adminSshPublicKey) : null;
        $this->adminSshPublicKey = $normalized === '' ? null : $normalized;
    }

    public function anonymize(string $email, string $passwordHash): void
    {
        $this->email = strtolower($email);
        $this->passwordHash = $passwordHash;
        $this->name = null;
        $this->emailVerifiedAt = null;
        $this->emailVerificationTokenHash = null;
        $this->emailVerificationExpiresAt = null;
        $this->termsAcceptedAt = null;
        $this->termsAcceptedIp = null;
        $this->privacyAcceptedAt = null;
        $this->privacyAcceptedIp = null;
        $this->adminSignature = null;
        $this->adminSshPublicKey = null;
    }

    public function getResellerOwner(): ?self
    {
        return $this->resellerOwner;
    }

    public function setResellerOwner(?self $resellerOwner): void
    {
        $this->resellerOwner = $resellerOwner;
    }

    public function isOwnedBy(self $reseller): bool
    {
        return $this->resellerOwner?->getId() === $reseller->getId();
    }
}
