<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotRole;
use App\Module\Musicbot\Domain\Entity\MusicbotRoleAssignment;
use App\Module\Musicbot\Domain\Enum\MusicbotRoleSubjectType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotRoleAssignment> */
final class MusicbotRoleAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotRoleAssignment::class);
    }

    public function findById(int $id): ?MusicbotRoleAssignment
    {
        return $this->find($id);
    }

    /** @return MusicbotRoleAssignment[] */
    public function findByRole(MusicbotRole $role): array
    {
        return $this->findBy(['role' => $role], ['createdAt' => 'ASC']);
    }

    /** @return MusicbotRoleAssignment[] */
    public function findBySubject(MusicbotRoleSubjectType $type, string $subjectId): array
    {
        return $this->findBy(['subjectType' => $type, 'subjectId' => $subjectId]);
    }

    /**
     * Returns all assignments for a subject scoped to a specific bot instance.
     *
     * @return MusicbotRoleAssignment[]
     */
    public function findBySubjectAndInstance(
        MusicbotRoleSubjectType $type,
        string $subjectId,
        MusicbotInstance $instance,
    ): array {
        return $this->createQueryBuilder('a')
            ->join('a.role', 'r')
            ->where('a.subjectType = :type')
            ->andWhere('a.subjectId = :sid')
            ->andWhere('r.instance = :instance')
            ->setParameter('type', $type->value)
            ->setParameter('sid', $subjectId)
            ->setParameter('instance', $instance)
            ->getQuery()
            ->getResult();
    }

    public function findOneByRoleAndSubject(
        MusicbotRole $role,
        MusicbotRoleSubjectType $type,
        string $subjectId,
    ): ?MusicbotRoleAssignment {
        return $this->findOneBy(['role' => $role, 'subjectType' => $type, 'subjectId' => $subjectId]);
    }

    public function findOneForRole(int $id, MusicbotRole $role): ?MusicbotRoleAssignment
    {
        return $this->findOneBy(['id' => $id, 'role' => $role]);
    }
}
