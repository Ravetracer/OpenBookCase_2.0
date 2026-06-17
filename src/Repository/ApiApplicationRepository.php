<?php

namespace App\Repository;

use App\Entity\ApiApplication;
use App\Entity\User;
use App\Enums\ApiApplicationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiApplication>
 *
 * @method ApiApplication|null find($id, $lockMode = null, $lockVersion = null)
 * @method ApiApplication|null findOneBy(array $criteria, array $orderBy = null)
 * @method ApiApplication[]    findAll()
 * @method ApiApplication[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ApiApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiApplication::class);
    }

    public function save(ApiApplication $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * All applications, newest first — for the admin list.
     *
     * @return ApiApplication[]
     */
    public function findAllNewestFirst(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** The current user's most recent application, if any (drives the profile UI). */
    public function findLatestForUser(User $user): ?ApiApplication
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.applicant = :user')
            ->setParameter('user', $user->id, 'ulid')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.status = :status')
            ->setParameter('status', ApiApplicationStatus::Pending->value)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
