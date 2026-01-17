<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\CustomerProfileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CustomerProfileRepository::class)]
#[ORM\Table(name: 'customer_profiles')]
#[ORM\UniqueConstraint(name: 'uniq_customer_profiles_customer', columns: ['customer_id'])]
class CustomerProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(length: 40)]
    private string $postal;

    #[ORM\Column(length: 120)]
    private string $city;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $company = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $vatId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $customer,
        string $firstName,
        string $lastName,
        string $address,
        string $postal,
        string $city,
        string $country,
    ) {
        $this->customer = $customer;
        $this->firstName = $this->normalizeValue($firstName);
        $this->lastName = $this->normalizeValue($lastName);
        $this->address = $this->normalizeValue($address);
        $this->postal = $this->normalizeValue($postal);
        $this->city = $this->normalizeValue($city);
        $this->country = strtoupper($country);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): void
    {
        $this->firstName = $this->normalizeValue($firstName);
        $this->touch();
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): void
    {
        $this->lastName = $this->normalizeValue($lastName);
        $this->touch();
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): void
    {
        $this->address = $this->normalizeValue($address);
        $this->touch();
    }

    public function getPostal(): string
    {
        return $this->postal;
    }

    public function setPostal(string $postal): void
    {
        $this->postal = $this->normalizeValue($postal);
        $this->touch();
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(string $city): void
    {
        $this->city = $this->normalizeValue($city);
        $this->touch();
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(string $country): void
    {
        $this->country = strtoupper(trim($country));
        $this->touch();
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $this->normalizeOptionalValue($phone);
        $this->touch();
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): void
    {
        $this->company = $this->normalizeOptionalValue($company);
        $this->touch();
    }

    public function getVatId(): ?string
    {
        return $this->vatId;
    }

    public function setVatId(?string $vatId): void
    {
        $vatId = $this->normalizeOptionalValue($vatId);
        $this->vatId = $vatId !== null ? strtoupper(str_replace(' ', '', $vatId)) : null;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function normalizeValue(string $value): string
    {
        $value = trim($value);
        return $value;
    }

    private function normalizeOptionalValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        return $value !== '' ? $value : null;
    }
}
