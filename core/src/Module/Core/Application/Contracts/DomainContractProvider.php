<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Enum\ShopProductKind;
use Doctrine\ORM\EntityManagerInterface;

/** The panel manages domain allocations. Registration is confirmed by its operator. */
final readonly class DomainContractProvider implements ContractResourceProvider
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[\Override]
    public function kinds(): array
    {
        return [ShopProductKind::Domain];
    }

    #[\Override]
    public function provision(ShopContract $contract): void
    {
        $domain = $this->em->getRepository(Domain::class)->findOneBy(['name' => (string) $contract->getConfiguration()['hostname']]);
        if (null !== $domain && $domain->getCustomer()->getId() !== $contract->getCustomer()->getId()) {
            throw new \DomainException('DOMAIN_ALREADY_IN_USE');
        }
        if (null === $domain) {
            $domain = new Domain($contract->getCustomer(), null, (string) $contract->getConfiguration()['hostname'], 'registration_requested');
            $this->em->persist($domain);
            $this->em->flush();
        }
        $contract->bind((string) $domain->getId(), null, 'manual');
        $contract->fail('DOMAIN_REGISTRATION_CONFIRMATION_REQUIRED');
    }

    #[\Override]
    public function readyAt(ShopContract $contract): ?\DateTimeImmutable
    {
        return null;
    }

    #[\Override]
    public function stop(ShopContract $contract): bool
    {
        return true;
    }
}
